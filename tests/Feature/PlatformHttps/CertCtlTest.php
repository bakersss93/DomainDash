<?php

namespace Tests\Feature\PlatformHttps;

use App\Models\Domain;
use App\Models\PlatformCertificate;
use App\Models\PlatformCertificateRequest as Req;
use App\Services\Https\CertCtl;
use App\Services\Https\PlatformHttps;
use App\Services\Https\TxtResolver;
use App\Services\Https\ZoneFinder;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Support\Https\CertificateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\Support\CertFactory;
use Tests\TestCase;

class CertCtlTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;
    private $synergy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/dd-certctl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/snippets', 0755, true);
        mkdir($this->tmp . '/live', 0755, true);
        touch($this->tmp . '/certbot');
        chmod($this->tmp . '/certbot', 0755);

        config([
            'domaindash.https.paths.ssl_snippet' => $this->tmp . '/snippets/domaindash-ssl.conf',
            'domaindash.https.paths.ssl_dir' => $this->tmp . '/ssl',
            'domaindash.https.paths.letsencrypt_live' => $this->tmp . '/live',
            'domaindash.https.commands.certbot' => $this->tmp . '/certbot',
            'domaindash.https.commands.nginx' => '/usr/sbin/nginx',
            'domaindash.https.commands.systemctl' => '/usr/bin/systemctl',
        ]);
        $this->synergy = Mockery::mock(SynergyWholesaleClient::class);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    private function ctl(): CertCtl
    {
        $resolver = new class implements TxtResolver {
            public function lookupTxt(string $name): array { return []; }
            public function lookupNs(string $zone): array { return ['ns1.nameserver.net.au']; }
        };

        return new CertCtl(new CertificateValidator(), new ZoneFinder($this->synergy), $this->synergy, $resolver);
    }

    private function uploadRequest(string $host = 'dash.example.com'): Req
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, $host, [$host]);
        $bundle = (new CertificateValidator())->validatePem($leaf['cert'], $leaf['key'], $ca['cert'], $host);

        return (new PlatformHttps())->createUploadRequest($host, $bundle, null);
    }

    public function test_tick_writes_heartbeat(): void
    {
        $this->ctl()->tick();
        $hb = (new PlatformHttps())->heartbeat();
        $this->assertFalse($hb['stale']);
        $this->assertSame(CertCtl::VERSION, $hb['info']['version']);
    }

    public function test_upload_is_installed_with_safe_permissions_and_nginx_reloaded(): void
    {
        Process::fake();
        $req = $this->uploadRequest();

        $this->ctl()->tick();

        $req->refresh();
        $this->assertSame('succeeded', $req->status, (string) $req->log);
        $this->assertNull($req->getRawOriginal('payload'), 'key payload wiped');

        $dir = $this->tmp . '/ssl/dash.example.com';
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dir . '/privkey.pem')), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($dir . '/fullchain.pem')), -4));
        $this->assertStringContainsString('BEGIN PRIVATE KEY', file_get_contents($dir . '/privkey.pem'));
        $this->assertSame(2, substr_count(file_get_contents($dir . '/fullchain.pem'), 'BEGIN CERTIFICATE'));

        $snippet = file_get_contents($this->tmp . '/snippets/domaindash-ssl.conf');
        $this->assertStringContainsString("ssl_certificate {$dir}/fullchain.pem;", $snippet);
        $this->assertStringContainsString("ssl_certificate_key {$dir}/privkey.pem;", $snippet);
        $this->assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $snippet);
        $this->assertStringNotContainsString('ssl_stapling', $snippet, 'no OCSP URL in the test cert');

        Process::assertRan(fn (PendingProcess $p) => $p->command === ['/usr/sbin/nginx', '-t']);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['/usr/bin/systemctl', 'reload', 'nginx']);

        $cert = PlatformCertificate::active();
        $this->assertSame('upload', $cert->source);
        $this->assertFalse($cert->auto_renew);
        $this->assertNotNull($cert->private_key_encrypted);
        $this->assertStringNotContainsString('PRIVATE KEY', $cert->private_key_encrypted);
    }

    public function test_nginx_test_failure_restores_previous_snippet_and_fails_request(): void
    {
        file_put_contents($this->tmp . '/snippets/domaindash-ssl.conf', "# previous\n");
        Process::fake(fn (PendingProcess $p) => $p->command === ['/usr/sbin/nginx', '-t'] ? Process::result('', 'emerg: bad', 1) : Process::result());
        $req = $this->uploadRequest();

        $this->ctl()->tick();

        $req->refresh();
        $this->assertSame('failed', $req->status);
        $this->assertStringContainsString('nginx configuration test failed', $req->error);
        $this->assertSame("# previous\n", file_get_contents($this->tmp . '/snippets/domaindash-ssl.conf'));
        Process::assertNotRan(fn (PendingProcess $p) => in_array('reload', $p->command, true));
        $this->assertNull(PlatformCertificate::active());
    }

    public function test_failed_first_install_removes_snippet(): void
    {
        Process::fake(fn (PendingProcess $p) => $p->command === ['/usr/sbin/nginx', '-t'] ? Process::result('', 'bad', 1) : Process::result());
        $this->uploadRequest();
        $this->ctl()->tick();
        $this->assertFileDoesNotExist($this->tmp . '/snippets/domaindash-ssl.conf');
    }

    public function test_helper_revalidates_tampered_db_payload(): void
    {
        Process::fake();
        $req = $this->uploadRequest();
        // Attacker with DB write access swaps the hostname to something the cert does not cover.
        $req->update(['hostname' => 'evil.example.com']);
        $this->ctl()->tick();
        $req->refresh();
        $this->assertSame('failed', $req->status);
        $this->assertStringContainsString('does not cover', $req->error);
        Process::assertNothingRan();

        // ...or injects a path traversal / shell hostname.
        $req2 = $this->uploadRequest();
        $req2->update(['hostname' => '../../etc/x.com; reboot']);
        $this->ctl()->tick();
        $this->assertSame('failed', $req2->refresh()->status);
        $this->assertDirectoryDoesNotExist($this->tmp . '/ssl/..');
        Process::assertNothingRan();
    }

    public function test_unknown_request_type_and_bad_letsencrypt_inputs_never_run_commands(): void
    {
        Process::fake();
        $a = Req::create(['type' => 'shell', 'hostname' => 'dash.example.com', 'status' => 'pending']);
        $b = Req::create(['type' => 'letsencrypt', 'hostname' => 'dash.example.com;id', 'email' => 'a@example.com', 'status' => 'pending']);
        $c = Req::create(['type' => 'letsencrypt', 'hostname' => 'dash.example.com', 'email' => "a@example.com -d evil.com", 'status' => 'pending']);

        $this->ctl()->tick();

        foreach ([$a, $b, $c] as $r) {
            $this->assertSame('failed', $r->refresh()->status);
        }
        Process::assertNothingRan();
    }

    public function test_letsencrypt_unmanaged_zone_fails_with_upload_hint(): void
    {
        Process::fake();
        $this->synergy->shouldReceive('listDomains')->andReturn(['domainList' => []]);
        $r = Req::create(['type' => 'letsencrypt', 'hostname' => 'dash.nowhere.org', 'email' => 'a@example.com', 'status' => 'pending']);

        $this->ctl()->tick();

        $this->assertStringContainsString('not managed in Synergy', $r->refresh()->error);
        $this->assertStringContainsString('Upload a certificate', $r->error);
        Process::assertNothingRan();
    }

    public function test_letsencrypt_issues_via_manual_dns_hooks_never_nginx_plugin(): void
    {
        Domain::create(['name' => 'example.com']);
        $this->synergy->shouldReceive('balanceQuery')->once()->andReturn(['balance' => '10']);
        $host = 'dash.example.com';
        $leaf = CertFactory::selfSigned($host);

        Process::fake(function (PendingProcess $p) use ($host, $leaf) {
            if (($p->command[1] ?? '') === 'certonly') {
                @mkdir($this->tmp . "/live/{$host}", 0755, true);
                file_put_contents($this->tmp . "/live/{$host}/cert.pem", $leaf['cert']);
            }

            return Process::result("Successfully received certificate.\npassword=hunter2\n");
        });

        $r = Req::create(['type' => 'letsencrypt', 'hostname' => $host, 'email' => 'ops@example.com', 'staging' => true, 'status' => 'pending']);
        $this->ctl()->tick();

        $r->refresh();
        $this->assertSame('succeeded', $r->status, (string) $r->log);
        $this->assertStringNotContainsString('hunter2', $r->log);

        Process::assertRan(function (PendingProcess $p) use ($host) {
            $c = $p->command;

            return is_array($c) && $c[1] === 'certonly'
                && in_array('--manual', $c, true) && in_array('--staging', $c, true)
                && $c[array_search('--preferred-challenges', $c) + 1] === 'dns'
                && $c[array_search('-d', $c) + 1] === $host
                && $c[array_search('-m', $c) + 1] === 'ops@example.com'
                && in_array('--manual-auth-hook', $c, true) && in_array('--manual-cleanup-hook', $c, true)
                && in_array('--deploy-hook', $c, true) && in_array('--non-interactive', $c, true)
                && ! in_array('--nginx', $c, true) && ! in_array('--webroot', $c, true) && ! in_array('--standalone', $c, true);
        });

        $cert = PlatformCertificate::active();
        $this->assertSame('letsencrypt', $cert->source);
        $this->assertTrue($cert->auto_renew);
        $this->assertTrue($cert->staging);
        $this->assertNull($cert->private_key_encrypted, 'LE keys never enter the DB');
        $this->assertStringContainsString("ssl_certificate {$this->tmp}/live/{$host}/fullchain.pem;", file_get_contents($this->tmp . '/snippets/domaindash-ssl.conf'));
    }

    public function test_wildcard_request_uses_safe_cert_name(): void
    {
        Domain::create(['name' => 'example.com']);
        $this->synergy->shouldReceive('balanceQuery')->andReturn([]);
        Process::fake(fn () => Process::result('', 'denied', 1));
        $r = Req::create(['type' => 'letsencrypt', 'hostname' => '*.example.com', 'email' => 'ops@example.com', 'status' => 'pending']);
        $this->ctl()->tick();

        $this->assertSame('failed', $r->refresh()->status);
        Process::assertRan(fn (PendingProcess $p) => in_array('wildcard.example.com', $p->command, true) && in_array('*.example.com', $p->command, true));
    }

    public function test_renew_runs_certbot_renew_with_deploy_hook_and_records_failure(): void
    {
        Process::fake(fn () => Process::result('', 'Failed to renew', 1));
        $this->assertFalse($this->ctl()->renew()['ok']);

        Process::assertRan(fn (PendingProcess $p) => array_slice($p->command, 1, 2) === ['renew', '--quiet'] && in_array('--deploy-hook', $p->command, true));
        $last = (new PlatformHttps())->lastRenewal();
        $this->assertFalse($last['ok']);
        $this->assertFalse($last['notified']);
        $this->assertSame('failed', Req::where('type', 'renew')->first()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'https.renew.failed']);
    }

    public function test_renewal_detected_updates_metadata(): void
    {
        $host = 'dash.example.com';
        $old = CertFactory::selfSigned($host);
        $new = CertFactory::selfSigned($host);
        @mkdir($this->tmp . "/live/{$host}", 0755, true);
        file_put_contents($this->tmp . "/live/{$host}/cert.pem", $new['cert']);

        $meta = (new CertificateValidator())->inspect($old['cert']);
        PlatformCertificate::create(['hostname' => $host, 'source' => 'letsencrypt', 'fingerprint_sha256' => $meta['fingerprint_sha256'],
            'not_before' => $meta['not_before'], 'not_after' => $meta['not_after'], 'is_active' => true, 'auto_renew' => true]);

        $this->ctl()->syncActiveLetsEncrypt();

        $cert = PlatformCertificate::active();
        $this->assertNotSame($meta['fingerprint_sha256'], $cert->fingerprint_sha256);
        $this->assertNotNull($cert->last_renewed_at);
    }

    public function test_stale_running_requests_are_failed(): void
    {
        $r = Req::create(['type' => 'upload', 'status' => 'running', 'started_at' => now()->subHour()]);
        $this->ctl()->tick();
        $this->assertSame('failed', $r->refresh()->status);
    }

    public function test_snippet_renderer_rejects_unsafe_paths(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->ctl()->renderSnippet("/etc/x;\nroot /;", '/etc/k.pem', null);
    }

    public function test_command_rejects_unknown_operation(): void
    {
        $this->artisan('domaindash:certctl', ['op' => 'rm -rf'])->assertExitCode(2);
    }
}
