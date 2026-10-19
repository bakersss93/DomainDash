<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Setting;
use App\Services\Halo\HaloPsaClient;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\ItGlue\ItGlueClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class IntegrationClientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sleep::fake();
        Http::preventStrayRequests();

        Setting::put('halo', [
            'base_url' => 'https://halo.example.com',
            'client_id' => 'cid',
            'api_key' => 'secret',
        ]);
        Setting::put('itglue', [
            'api_key' => 'itg-key',
            'flexible_asset_type_id' => 77,
        ]);
    }

    private function haloToken(): array
    {
        return ['halo.example.com/auth/token*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])];
    }

    public function test_halo_paginates_with_pageinate_page_size_and_page_no(): void
    {
        Http::fake($this->haloToken() + [
            'halo.example.com/api/client*' => function (Request $request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
                $page = (int) $q['page_no'];
                $rows = match ($page) {
                    1 => [['id' => 1], ['id' => 2]],
                    2 => [['id' => 3]],
                    default => [],
                };

                return Http::response(['record_count' => 3, 'clients' => $rows]);
            },
        ]);

        config(['integrations.halo.page_size' => 2]);

        $clients = (new HaloPsaClient())->listClients();

        $this->assertSame([1, 2, 3], array_column($clients, 'id'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/client')
            && str_contains($r->url(), 'pageinate=true')
            && str_contains($r->url(), 'page_size=2')
            && str_contains($r->url(), 'page_no=2'));
    }

    public function test_halo_get_retries_429_honouring_retry_after(): void
    {
        Http::fake($this->haloToken() + [
            'halo.example.com/api/client/5' => Http::sequence()
                ->push('', 429, ['Retry-After' => '3'])
                ->push(['id' => 5, 'name' => 'Acme']),
        ]);

        $client = (new HaloPsaClient())->getClient(5);

        $this->assertSame('Acme', $client['name']);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function test_halo_get_failure_throws_typed_exception_after_bounded_retries(): void
    {
        Http::fake($this->haloToken() + [
            'halo.example.com/api/client/9' => Http::response('boom', 503),
        ]);

        try {
            (new HaloPsaClient())->getClient(9);
            $this->fail('Expected IntegrationApiException');
        } catch (IntegrationApiException $e) {
            $this->assertSame(503, $e->status);
            $this->assertTrue($e->retryable);
            $this->assertSame('halo', $e->provider);
        }

        // 1 token + max_attempts (4) GETs
        Http::assertSentCount(1 + 4);
    }

    public function test_halo_post_is_never_retried(): void
    {
        Http::fake($this->haloToken() + [
            'halo.example.com/api/asset' => Http::response('boom', 500),
        ]);

        try {
            (new HaloPsaClient())->createDomainAsset(['name' => 'x.com']);
            $this->fail('Expected IntegrationApiException');
        } catch (IntegrationApiException $e) {
            $this->assertSame(500, $e->status);
        }

        $assetPosts = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/api/asset'));
        $this->assertCount(1, $assetPosts);
        Sleep::assertNeverSlept();
    }

    public function test_halo_token_is_cached_per_credentials(): void
    {
        Http::fake($this->haloToken() + [
            'halo.example.com/api/client/1' => Http::response(['id' => 1]),
        ]);

        $halo = new HaloPsaClient();
        $halo->getClient(1);
        $halo->getClient(1);

        $tokenCalls = Http::recorded(fn (Request $r) => str_contains($r->url(), '/auth/token'));
        $this->assertCount(1, $tokenCalls);

        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'other', 'api_key' => 'secret']);
        (new HaloPsaClient())->getClient(1);

        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), '/auth/token')));
    }

    public function test_halo_rejects_plain_http_base_url(): void
    {
        Setting::put('halo', ['base_url' => 'http://halo.example.com', 'client_id' => 'a', 'api_key' => 'b']);

        $this->expectException(IntegrationConfigurationException::class);
        new HaloPsaClient();
    }

    public function test_halo_notes_block_is_replaced_not_appended(): void
    {
        $once = HaloPsaClient::replaceManagedBlock("Existing note", "Line A");
        $twice = HaloPsaClient::replaceManagedBlock($once, "Line A");
        $changed = HaloPsaClient::replaceManagedBlock($twice, "Line B");

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($changed, HaloPsaClient::NOTES_BEGIN));
        $this->assertStringContainsString('Existing note', $changed);
        $this->assertStringContainsString('Line B', $changed);
        $this->assertStringNotContainsString('Line A', $changed);
    }

    public function test_itglue_paginates_using_page_size_1000_and_next_page_meta(): void
    {
        Http::fake([
            'api.itglue.com/organizations*' => function (Request $request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
                $this->assertSame('1000', $q['page']['size']);
                $page = (int) $q['page']['number'];

                return Http::response([
                    'data' => [['id' => (string) $page, 'attributes' => ['name' => "Org {$page}"]]],
                    'meta' => ['next-page' => $page < 3 ? $page + 1 : null],
                ]);
            },
        ]);

        $orgs = (new ItGlueClient())->listOrganisations();

        $this->assertCount(3, $orgs['data']);
    }

    public function test_itglue_patch_sends_full_trait_set_preserving_existing_traits(): void
    {
        Http::fake([
            'api.itglue.com/flexible_assets/42' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => '42', 'attributes' => [
                        'traits' => ['domain-name' => 'old.com', 'notes-by-human' => 'keep me', 'empty' => null],
                    ]]]);
                }

                return Http::response(['data' => ['id' => '42']]);
            },
        ]);

        (new ItGlueClient())->updateFlexibleAsset(42, 'example.com', ['domain-name' => 'example.com']);

        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'PATCH') {
                return false;
            }
            $traits = $r->data()['data']['attributes']['traits'];

            return $traits['domain-name'] === 'example.com'
                && $traits['notes-by-human'] === 'keep me'
                && ! array_key_exists('empty', $traits);
        });
    }

    public function test_itglue_lookup_failure_throws_and_never_creates_a_duplicate(): void
    {
        Http::fake([
            'api.itglue.com/flexible_assets*' => Http::response('rate limited', 429, ['Retry-After' => '1']),
        ]);

        $domain = new Domain(['name' => 'example.com']);

        try {
            (new ItGlueClient())->syncDomain($domain, 5, []);
            $this->fail('Expected IntegrationApiException');
        } catch (IntegrationApiException $e) {
            $this->assertTrue($e->isRateLimited());
        }

        $this->assertCount(0, Http::recorded(fn (Request $r) => $r->method() === 'POST'));
    }

    /**
     * @dataProvider baseUrls
     */
    public function test_itglue_base_url_validation(?string $url, ?string $expected): void
    {
        if ($expected === null) {
            $this->expectException(IntegrationConfigurationException::class);
        }

        $this->assertSame($expected, ItGlueClient::normalizeBaseUrl($url));
    }

    public static function baseUrls(): array
    {
        return [
            'blank defaults to US' => ['', 'https://api.itglue.com'],
            'EU' => ['https://api.eu.itglue.com/', 'https://api.eu.itglue.com'],
            'AU' => ['https://api.au.itglue.com', 'https://api.au.itglue.com'],
            'http rejected' => ['http://api.itglue.com', null],
            'foreign host rejected' => ['https://evil.example.com', null],
            'lookalike rejected' => ['https://api.itglue.com.evil.com', null],
            'path rejected' => ['https://api.itglue.com/v2', null],
        ];
    }
}
