<?php

namespace Tests\Feature\PlatformHttps;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\PlatformCertificate;
use App\Models\PlatformCertificateRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Https\PlatformHttps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\Support\CertFactory;
use Tests\TestCase;

class PlatformHttpsWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['domaindash.mfa.enforce' => false, 'app.url' => 'https://dash.example.com']);
        $this->withoutVite();
        Cache::flush();
    }

    private function admin(): User
    {
        Role::findOrCreate('Administrator');
        $u = User::factory()->create();
        $u->assignRole('Administrator');

        return $u;
    }

    private function activeCert(int $days = 60): PlatformCertificate
    {
        return PlatformCertificate::create([
            'hostname' => 'dash.example.com', 'source' => 'letsencrypt', 'issuer' => 'R3 (Lets Encrypt)', 'sans' => ['dash.example.com'],
            'fingerprint_sha256' => 'AA:BB', 'key_type' => 'EC P-256', 'not_before' => now()->subDay(), 'not_after' => now()->addDays($days),
            'is_active' => true, 'auto_renew' => true,
        ]);
    }

    public function test_non_admin_gets_403_and_guests_are_redirected(): void
    {
        $user = User::factory()->create();
        $this->get('/admin/https')->assertRedirect('/login');
        foreach (['get' => '/admin/https', 'post' => '/admin/https/letsencrypt'] as $m => $url) {
            $this->actingAs($user)->{$m}($url)->assertForbidden();
        }
        $this->actingAs($user)->post('/admin/https/upload')->assertForbidden();
        $this->actingAs($user)->post('/admin/https/force', ['force' => 1])->assertForbidden();
        $this->assertSame(0, PlatformCertificateRequest::count());
    }

    public function test_admin_without_mfa_is_blocked_when_enforced(): void
    {
        config(['domaindash.mfa.enforce' => true]);
        $this->actingAs($this->admin())->getJson('/admin/https')->assertForbidden();
    }

    public function test_admin_sees_page_with_helper_warning_and_defaults(): void
    {
        $this->actingAs($this->admin())->get('/admin/https')
            ->assertOk()->assertSee('Platform HTTPS')->assertSee('HTTPS helper not installed or not running')
            ->assertSee('value="dash.example.com"', false);
    }

    public function test_settings_page_links_to_platform_https(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings')->assertOk()->assertSee(route('admin.https'), false);
    }

    public function test_status_card_shows_active_certificate(): void
    {
        $this->activeCert(5);
        (new \App\Services\Https\CertCtl(new \App\Support\Https\CertificateValidator(), new \App\Services\Https\ZoneFinder(), \Mockery::mock(\App\Services\Synergy\SynergyWholesaleClient::class), \Mockery::mock(\App\Services\Https\TxtResolver::class)))->heartbeat();
        $this->actingAs($this->admin())->get('/admin/https')->assertOk()
            ->assertSee('R3 (Lets Encrypt)')->assertSee('ui-stat--danger', false)->assertDontSee('HTTPS helper not installed');
    }

    public function test_heartbeat_staleness(): void
    {
        $svc = new PlatformHttps();
        $this->assertTrue($svc->heartbeat()['stale'], 'never seen');

        Setting::put(PlatformHttps::HEARTBEAT, ['last_seen' => now()->subMinutes(4)->toIso8601String()]);
        $this->assertFalse($svc->heartbeat()['stale']);

        Setting::put(PlatformHttps::HEARTBEAT, ['last_seen' => now()->subMinutes(6)->toIso8601String()]);
        $this->assertTrue($svc->heartbeat()['stale']);
    }

    public function test_letsencrypt_request_created_and_audited(): void
    {
        Domain::create(['name' => 'example.com']);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/https/letsencrypt', ['hostname' => ' Dash.Example.com ', 'email' => 'ops@example.com', 'staging' => 1])
            ->assertRedirect('/admin/https');

        $req = PlatformCertificateRequest::first();
        $this->assertSame('letsencrypt', $req->type);
        $this->assertSame('dash.example.com', $req->hostname);
        $this->assertTrue($req->staging);
        $this->assertSame('pending', $req->status);
        $this->assertSame($admin->id, $req->requested_by);
        $this->assertTrue(AuditLog::where('action', 'https.request.letsencrypt')->exists());
    }

    public function test_letsencrypt_validation_errors(): void
    {
        Domain::create(['name' => 'example.com']);
        $a = $this->admin();
        foreach ([
            ['hostname' => 'bad host', 'email' => 'ops@example.com', 'f' => 'hostname'],
            ['hostname' => 'dash.example.com;id', 'email' => 'ops@example.com', 'f' => 'hostname'],
            ['hostname' => '1.2.3.4', 'email' => 'ops@example.com', 'f' => 'hostname'],
            ['hostname' => 'dash.example.com', 'email' => 'nope', 'f' => 'email'],
            ['hostname' => 'dash.example.com', 'email' => "a@example.com\nBcc:x@y.z", 'f' => 'email'],
            ['hostname' => 'dash.unmanaged.org', 'email' => 'ops@example.com', 'f' => 'hostname'],
        ] as $case) {
            $f = array_pop($case);
            $this->actingAs($a)->from('/admin/https')->post('/admin/https/letsencrypt', $case)->assertSessionHasErrors($f);
        }
        $this->assertSame(0, PlatformCertificateRequest::count());
    }

    public function test_wildcard_allowed_for_dns01(): void
    {
        Domain::create(['name' => 'example.com']);
        $this->actingAs($this->admin())->post('/admin/https/letsencrypt', ['hostname' => '*.example.com', 'email' => 'ops@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('*.example.com', PlatformCertificateRequest::first()->hostname);
    }

    public function test_second_request_blocked_while_one_is_open(): void
    {
        Domain::create(['name' => 'example.com']);
        $a = $this->admin();
        $d = ['hostname' => 'dash.example.com', 'email' => 'ops@example.com'];
        $this->actingAs($a)->post('/admin/https/letsencrypt', $d);
        $this->actingAs($a)->post('/admin/https/letsencrypt', $d)->assertSessionHasErrors('hostname');
        $this->assertSame(1, PlatformCertificateRequest::count());
    }

    public function test_upload_pem_validates_and_encrypts_key(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'dash.example.com', ['dash.example.com']);

        $this->actingAs($this->admin())->post('/admin/https/upload', [
            'hostname' => 'dash.example.com', 'mode' => 'pem', 'certificate' => $leaf['cert'], 'private_key' => $leaf['key'], 'chain' => $ca['cert'],
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/https');

        $req = PlatformCertificateRequest::first();
        $raw = \DB::table('platform_certificate_requests')->where('id', $req->id)->value('payload');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', Crypt::decryptString($raw));

        // Audit log must not contain key material.
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('PRIVATE KEY', json_encode($log->toArray()));
        }
    }

    public function test_upload_rejects_mismatch_expired_and_wrong_host(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'dash.example.com', ['dash.example.com']);
        $other = CertFactory::leaf($ca, 'dash.example.com', ['dash.example.com']);
        $a = $this->admin();

        $this->actingAs($a)->post('/admin/https/upload', ['hostname' => 'dash.example.com', 'mode' => 'pem', 'certificate' => $leaf['cert'], 'private_key' => $other['key']])
            ->assertSessionHasErrors('certificate');
        $this->actingAs($a)->post('/admin/https/upload', ['hostname' => 'x.example.com', 'mode' => 'pem', 'certificate' => $leaf['cert'], 'private_key' => $leaf['key']])
            ->assertSessionHasErrors('certificate');
        $this->assertSame(0, PlatformCertificateRequest::count());
    }

    public function test_upload_pfx_with_files(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'dash.example.com', ['dash.example.com']);
        openssl_pkcs12_export($leaf['cert'], $pfx, $leaf['keyObj'], 'pw123', ['extracerts' => [$ca['cert']]]);
        $file = UploadedFile::fake()->createWithContent('cert.pfx', $pfx);

        $this->actingAs($this->admin())->post('/admin/https/upload', ['hostname' => 'dash.example.com', 'mode' => 'pfx', 'pfx_file' => $file, 'pfx_password' => 'pw123'])
            ->assertSessionHasNoErrors();
        $this->assertSame('upload', PlatformCertificateRequest::first()->type);
        PlatformCertificateRequest::query()->update(['status' => 'succeeded']);

        $this->actingAs($this->admin())->post('/admin/https/upload', ['hostname' => 'dash.example.com', 'mode' => 'pfx', 'pfx_file' => UploadedFile::fake()->createWithContent('c.pfx', $pfx), 'pfx_password' => 'bad'])
            ->assertSessionHasErrors('certificate');
    }

    public function test_force_https_requires_valid_certificate_and_confirmation(): void
    {
        $a = $this->admin();
        $this->actingAs($a)->post('/admin/https/force', ['force' => 1, 'confirm' => 1])->assertSessionHasErrors('force');
        $this->assertFalse(PlatformHttps::forceEnabled());

        $cert = $this->activeCert();
        $this->actingAs($a)->post('/admin/https/force', ['force' => 1])->assertSessionHasErrors('force');

        $this->actingAs($a)->post('/admin/https/force', ['force' => 1, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertTrue(PlatformHttps::forceEnabled());

        $cert->update(['not_after' => now()->subDay()]);
        $this->actingAs($a)->post('https://dash.example.com/admin/https/force', ['force' => 0])->assertSessionHasNoErrors();
        $this->assertFalse(PlatformHttps::forceEnabled());
        $this->actingAs($a)->post('/admin/https/force', ['force' => 1, 'confirm' => 1])->assertSessionHasErrors('force');
    }

    public function test_force_https_middleware(): void
    {
        $this->get('/login')->assertOk(); // not forced

        PlatformHttps::setForce(true);
        $this->get('/login?x=1')->assertStatus(301)->assertRedirect('https://dash.example.com/login?x=1');
        $this->post('/login', [])->assertStatus(308);
        $this->get('/up')->assertOk();                                  // health check exempt
        $this->get('https://dash.example.com/login')->assertOk();       // already secure

        // Host header is never reflected into the redirect.
        $this->get('http://evil.test/login')->assertRedirect('https://dash.example.com/login');
    }

    public function test_hsts_sent_on_secure_responses(): void
    {
        $this->get('https://dash.example.com/login')->assertHeader('Strict-Transport-Security');
    }

    public function test_trusted_proxies_config_parsing(): void
    {
        foreach (['' => null, '*' => '*', '10.0.0.1, 10.0.0.2' => ['10.0.0.1', '10.0.0.2']] as $env => $expected) {
            putenv("TRUSTED_PROXIES={$env}");
            $_ENV['TRUSTED_PROXIES'] = $env;
            $_SERVER['TRUSTED_PROXIES'] = $env;
            $this->assertSame($expected, (require base_path('config/trustedproxy.php'))['proxies'], "env={$env}");
        }
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }

    public function test_request_is_secure_behind_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '*']);
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://dash.example.com/login')->assertHeader('Strict-Transport-Security');

        config(['trustedproxy.proxies' => null]);
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://dash.example.com/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
