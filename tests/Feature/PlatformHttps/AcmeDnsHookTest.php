<?php

namespace Tests\Feature\PlatformHttps;

use App\Models\Domain;
use App\Models\PlatformAcmeChallenge;
use App\Models\PlatformCertificateRequest;
use App\Services\Https\AcmeDnsHook;
use App\Services\Https\TxtResolver;
use App\Services\Https\ZoneFinder;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AcmeDnsHookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    /** @var list<int> */
    private array $sleeps = [];

    private function runningRequest(string $host = 'dash.example.com.au'): void
    {
        PlatformCertificateRequest::create(['type' => 'letsencrypt', 'hostname' => $host, 'status' => 'running']);
    }

    private function resolver(array $answers): TxtResolver
    {
        return new class($answers) implements TxtResolver {
            public int $calls = 0;

            public function __construct(private array $answers)
            {
            }

            public function lookupTxt(string $name): array
            {
                return $this->answers[min($this->calls++, count($this->answers) - 1)];
            }

            public function lookupNs(string $zone): array
            {
                return ['ns1.nameserver.net.au'];
            }
        };
    }

    private function hook(SynergyWholesaleClient $synergy, TxtResolver $resolver, int $timeout = 60): AcmeDnsHook
    {
        return new AcmeDnsHook($synergy, $resolver, function (int $s) { $this->sleeps[] = $s; }, $timeout, 10, 5);
    }

    public function test_zone_candidates_and_local_discovery(): void
    {
        $this->assertSame(['a.b.example.com.au', 'b.example.com.au', 'example.com.au', 'com.au'], ZoneFinder::candidates('a.b.example.com.au'));
        $this->assertSame(['example.com'], ZoneFinder::candidates('*.example.com'));

        Domain::create(['name' => 'example.com.au']);
        Domain::create(['name' => 'other.com']);
        $this->assertSame('example.com.au', ZoneFinder::fromLocalDomains('dash.example.com.au'));
        $this->assertSame('example.com.au', ZoneFinder::fromLocalDomains('example.com.au'));
        $this->assertNull(ZoneFinder::fromLocalDomains('dash.unmanaged.org'));
        $this->assertNull(ZoneFinder::fromLocalDomains('com.au'));
    }

    public function test_zone_falls_back_to_synergy_list(): void
    {
        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldReceive('listDomains')->once()->with(1, 500)->andReturn(['domainList' => [(object) ['domainName' => 'live.example.net']]]);
        $this->assertSame('live.example.net', (new ZoneFinder($synergy))->find('dash.live.example.net'));
    }

    public function test_auth_adds_txt_polls_until_visible_and_cleanup_removes_only_that_record(): void
    {
        Domain::create(['name' => 'example.com.au']);
        $this->runningRequest();
        $other = 'ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';
        PlatformAcmeChallenge::create(['domain' => 'dash.example.com.au', 'zone' => 'example.com.au', 'record_name' => '_acme-challenge.dash', 'value' => $other, 'record_id' => 'rec-other']);

        /** @var SynergyWholesaleClient&MockInterface $synergy */
        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldReceive('addDNSRecord')->once()->with('example.com.au', '_acme-challenge.dash', 'TXT', self::TOKEN, 300)->andReturn(['id' => 'rec-1']);
        $synergy->shouldReceive('deleteDNSRecord')->once()->with('example.com.au', 'rec-1')->andReturn([]);

        $resolver = $this->resolver([[], [], ['"other"', self::TOKEN]]);
        $hook = $this->hook($synergy, $resolver);

        $msg = $hook->auth('dash.example.com.au', self::TOKEN);
        $this->assertStringContainsString('visible after 20s', $msg);
        $this->assertSame([10, 10, 5], $this->sleeps, 'two polls then the settle delay');
        $this->assertSame(3, $resolver->calls);

        $hook->cleanup('dash.example.com.au', self::TOKEN);
        $this->assertDatabaseMissing('platform_acme_challenges', ['value' => self::TOKEN]);
        $this->assertDatabaseHas('platform_acme_challenges', ['value' => $other]); // untouched
    }

    public function test_propagation_timeout_fails_and_removes_the_record(): void
    {
        Domain::create(['name' => 'example.com.au']);
        $this->runningRequest();

        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldReceive('addDNSRecord')->once()->andReturn(['id' => 'rec-9']);
        $synergy->shouldReceive('deleteDNSRecord')->once()->with('example.com.au', 'rec-9');

        $resolver = $this->resolver([[]]);
        try {
            $this->hook($synergy, $resolver, 30)->auth('dash.example.com.au', self::TOKEN);
            $this->fail('should time out');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('did not become visible within 30s', $e->getMessage());
        }
        $this->assertSame([10, 10, 10], $this->sleeps);
        $this->assertSame(0, PlatformAcmeChallenge::count());
    }

    public function test_record_id_discovered_from_zone_when_api_returns_none_and_apex_name(): void
    {
        Domain::create(['name' => 'example.com.au']);
        $this->runningRequest('example.com.au');

        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldReceive('addDNSRecord')->once()->with('example.com.au', '_acme-challenge', 'TXT', self::TOKEN, 300)->andReturn(['status' => 'OK']);
        $synergy->shouldReceive('listDNSZone')->andReturn([
            (object) ['id' => 'x1', 'hostName' => '_acme-challenge.example.com.au', 'type' => 'TXT', 'content' => '"' . self::TOKEN . '"'],
            (object) ['id' => 'x2', 'hostName' => '_acme-challenge.example.com.au', 'type' => 'TXT', 'content' => 'different'],
        ]);
        $synergy->shouldReceive('deleteDNSRecord')->once()->with('example.com.au', 'x1');

        $hook = $this->hook($synergy, $this->resolver([[self::TOKEN]]));
        $hook->auth('example.com.au', self::TOKEN);
        $hook->cleanup('example.com.au', self::TOKEN);
    }

    public function test_cleanup_without_db_row_matches_by_value_and_is_idempotent(): void
    {
        Domain::create(['name' => 'example.com.au']);
        $this->runningRequest();

        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldReceive('listDNSZone')->andReturn([(object) ['id' => 'k1', 'hostName' => '_acme-challenge.dash', 'type' => 'TXT', 'content' => self::TOKEN]]);
        $synergy->shouldReceive('deleteDNSRecord')->once()->with('example.com.au', 'k1');

        $this->assertStringContainsString('Removed 1', $this->hook($synergy, $this->resolver([[]]))->cleanup('dash.example.com.au', self::TOKEN));

        $synergy2 = Mockery::mock(SynergyWholesaleClient::class);
        $synergy2->shouldReceive('listDNSZone')->andReturn([]);
        $synergy2->shouldNotReceive('deleteDNSRecord');
        $this->assertStringContainsString('Removed 0', $this->hook($synergy2, $this->resolver([[]]))->cleanup('dash.example.com.au', self::TOKEN));
    }

    public function test_unmanaged_zone_fails_before_touching_dns(): void
    {
        $this->runningRequest('dash.unmanaged.org');
        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldReceive('listDomains')->andReturn(['domainList' => []]);
        $synergy->shouldNotReceive('addDNSRecord');

        $this->expectExceptionMessage('not managed in Synergy');
        $this->hook($synergy, $this->resolver([[]]))->auth('dash.unmanaged.org', self::TOKEN);
    }

    public function test_certbot_env_validation(): void
    {
        Domain::create(['name' => 'example.com.au']);
        $this->runningRequest();
        $synergy = Mockery::mock(SynergyWholesaleClient::class);
        $synergy->shouldNotReceive('addDNSRecord');
        $hook = $this->hook($synergy, $this->resolver([[]]));

        $cases = [
            ['dash.example.com.au; rm -rf /', self::TOKEN, 'Rejected certbot input'],
            ['Dash.Example.com.au', self::TOKEN, 'Rejected certbot input'],
            ['dash.example.com.au', 'short', 'Invalid ACME validation'],
            ['dash.example.com.au', self::TOKEN . '"; DROP', 'Invalid ACME validation'],
            ['', self::TOKEN, 'Rejected certbot input'],
            ['evil.example.org', self::TOKEN, 'not a hostname requested through DomainDash'],
        ];
        foreach ($cases as [$d, $v, $msg]) {
            try {
                $hook->auth($d, $v);
                $this->fail("accepted {$d}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
    }

    public function test_command_reads_certbot_env_and_rejects_bad_phase(): void
    {
        $this->artisan('domaindash:acme-dns', ['phase' => 'bogus'])->assertExitCode(2);

        putenv('CERTBOT_DOMAIN=bad;host');
        putenv('CERTBOT_VALIDATION=' . self::TOKEN);
        $this->artisan('domaindash:acme-dns', ['phase' => 'auth'])->assertFailed();
        putenv('CERTBOT_DOMAIN');
        putenv('CERTBOT_VALIDATION');
    }
}
