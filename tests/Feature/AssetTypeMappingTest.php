<?php

namespace Tests\Feature;

use App\Jobs\SyncHaloAssetsJob;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Sync\HaloDomainPusher;
use App\Services\Sync\ItGlueDomainPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssetTypeMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key']);
        Setting::put('itglue', ['api_key' => 'itg-key', 'flexible_asset_type_id' => 77]);
    }

    private function admin(bool $administrator = true, bool $sync = true): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        if ($administrator) {
            $user->assignRole('Administrator');
        }
        if ($sync) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function fakeHaloTypes(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype/7*' => Http::response([
                'id' => 7, 'name' => 'Domain',
                'keyfield_id' => 201, 'keyfield_name' => 'Domain Name',
                'keyfield2_id' => 202, 'keyfield2_name' => 'Expiry Date',
                'keyfield3_id' => 0,
                'field_list' => [
                    ['id' => 201, 'name' => 'Domain Name', 'kind' => 1, 'mandatory' => true, 'typeinfo_id' => 9001],
                    ['id' => 202, 'name' => 'Expiry Date', 'kind' => 3, 'mandatory' => false, 'typeinfo_id' => 9002],
                    ['id' => 203, 'name' => 'Name Servers', 'kind' => 1, 'mandatory' => false],
                    ['id' => 204, 'name' => 'WHOIS', 'kind' => 2, 'mandatory' => false],
                    ['id' => 205, 'name' => 'Colour', 'kind' => 1],
                ],
            ]),
            'halo.example.com/api/assettype*' => Http::response(['assettypes' => [
                ['id' => 9, 'name' => 'Server', 'assetgroup_name' => 'Hardware'],
                ['id' => 7, 'name' => 'domain', 'assetgroup_name' => 'Services'],
                ['id' => 0, 'name' => 'Broken'],
            ]]),
        ]);
    }

    // ---------------- Halo endpoints ----------------

    public function test_halo_asset_types_endpoint_lists_sorted_types_and_preselects_domain(): void
    {
        $this->fakeHaloTypes();

        $json = $this->actingAs($this->admin())->getJson('/admin/settings/halo/asset-types')->assertOk()->json();

        $this->assertSame([['id' => 7, 'name' => 'domain', 'group' => 'Services'], ['id' => 9, 'name' => 'Server', 'group' => 'Hardware']], $json['types']);
        $this->assertSame(7, $json['suggested_id']);
        $this->assertNull($json['stored_id']);
        $this->assertStringNotContainsString('secret-api-key', json_encode($json));
    }

    public function test_halo_asset_types_are_cached_and_refresh_bypasses_the_cache(): void
    {
        $this->fakeHaloTypes();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/settings/halo/asset-types')->assertOk();
        $this->actingAs($admin)->getJson('/admin/settings/halo/asset-types')->assertOk();
        $count = fn () => Http::recorded(fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/assettype'))->count();
        $this->assertSame(1, $count());

        $this->actingAs($admin)->getJson('/admin/settings/halo/asset-types?refresh=1')->assertOk();
        $this->assertSame(2, $count());
    }

    public function test_halo_asset_type_fields_endpoint_returns_fields_suggestions_and_key_fields(): void
    {
        $this->fakeHaloTypes();

        $json = $this->actingAs($this->admin())->getJson('/admin/settings/halo/asset-types/7/fields')->assertOk()->json();

        $this->assertSame(7, $json['id']);
        $this->assertCount(5, $json['fields']);
        $this->assertSame(['domain' => 201, 'expiry' => 202, 'nameservers' => 203, 'whois' => 204], $json['suggestions']);
        $this->assertSame('domain', $json['keyfields'][0]['suggested_point']);
        $this->assertSame(201, $json['keyfields'][0]['field_id']);
        $this->assertSame('expiry', $json['keyfields'][1]['suggested_point']);
        $this->assertCount(2, $json['keyfields']); // slot 3 has no key field

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/assettype/7') && str_contains($r->url(), 'includedetails=true'));
    }

    public function test_halo_fields_fall_back_to_typeinfo_shape(): void
    {
        $type = HaloPsaClient::normalizeAssetType([
            'id' => 4, 'name' => 'X',
            'fields' => [['field_id' => 55, 'field_name' => 'Expiry', 'typeinfo_id' => 1], ['field_id' => 0, 'field_name' => 'junk']],
            'keyfield_id' => 55,
        ]);

        $this->assertSame([['id' => 55, 'name' => 'Expiry', 'kind' => null, 'mandatory' => false, 'typeinfo_id' => 1]], $type['fields']);
        $this->assertSame([['slot' => 1, 'id' => 55, 'name' => '']], $type['keyfields']);
    }

    public function test_halo_endpoint_returns_sanitised_422_when_halo_rejects(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype*' => Http::response('{"error":"forbidden","token":"s3cr3tvalue123"}', 403),
        ]);

        $res = $this->actingAs($this->admin())->getJson('/admin/settings/halo/asset-types')->assertStatus(422);

        $this->assertStringContainsString('403', $res->json('error'));
        $this->assertStringNotContainsString('s3cr3tvalue123', $res->json('error'));
    }

    public function test_halo_endpoints_report_unconfigured_halo_without_calling_it(): void
    {
        Setting::put('halo', []);
        Http::fake();

        $this->actingAs($this->admin())->getJson('/admin/settings/halo/asset-types')->assertStatus(422)->assertJsonStructure(['error']);
        Http::assertNothingSent();
    }

    // ---------------- Authorisation ----------------

    public function test_new_endpoints_are_admin_only_and_throttled(): void
    {
        Http::fake();
        $paths = [
            ['get', '/admin/settings/halo/asset-types'],
            ['get', '/admin/settings/halo/asset-types/7/fields'],
            ['post', '/admin/settings/halo/preview-mapping'],
            ['get', '/admin/settings/itglue/flexible-asset-types'],
            ['get', '/admin/settings/itglue/flexible-asset-types/7/fields'],
        ];

        foreach ($paths as [$method, $path]) {
            $guest = $this->{$method . 'Json'}($path);
            $this->assertContains($guest->status(), [401, 302, 403], "guest $path");

            $nonAdmin = $this->actingAs($this->admin(false, true))->{$method . 'Json'}($path);
            $nonAdmin->assertForbidden();
        }

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'admin/settings/halo/asset-types') || str_starts_with($route->uri(), 'admin/settings/itglue/') || $route->uri() === 'admin/settings/halo/preview-mapping') {
                $mw = $route->gatherMiddleware();
                $this->assertContains('role:Administrator', $mw, $route->uri());
                $this->assertContains('throttle:30,1', $mw, $route->uri());
            }
        }
        Http::assertNothingSent();
    }

    // ---------------- Preview ----------------

    public function test_preview_shows_target_fields_without_any_halo_call(): void
    {
        Http::fake();
        $domain = Domain::create(['name' => 'acme.com', 'expiry_date' => '2030-01-05', 'name_servers' => ['ns1.x.com', 'ns2.x.com']]);

        $json = $this->actingAs($this->admin())->postJson('/admin/settings/halo/preview-mapping', [
            'domain' => 'acme.com',
            'halo' => [
                'asset_type_id' => 7,
                'asset_type_name' => 'Domain',
                'asset_field_ids' => ['domain' => 201, 'expiry' => 202, 'nameservers' => 0, 'whois' => 204],
                'asset_field_names' => ['domain' => 'Domain Name', 'expiry' => 'Expiry Date', 'nameservers' => '', 'whois' => 'WHOIS'],
                'asset_key_fields' => [1 => 'domain', 2 => 'expiry', 3 => ''],
            ],
        ])->assertOk()->json();

        $this->assertSame(7, $json['asset_type_id']);
        $byPoint = collect($json['rows'])->keyBy('point');
        $this->assertSame(201, $byPoint['domain']['field_id']);
        $this->assertSame('Domain Name', $byPoint['domain']['field_name']);
        $this->assertSame('acme.com', $byPoint['domain']['value']);
        $this->assertSame('2030-01-05', $byPoint['expiry']['value']);
        $this->assertFalse($byPoint['nameservers']['synced']);
        $this->assertSame([['property' => 'key_field', 'value' => 'acme.com'], ['property' => 'key_field2', 'value' => '2030-01-05']], $json['key_fields']);
        $this->assertSame([201, 202, 204], array_column($json['payload']['fields'], 'id'));
        $this->assertSame('Domain Name', $json['payload']['fields'][0]['name']);
        Http::assertNothingSent();
        $this->assertNull($domain->fresh()->halo_asset_id);
    }

    public function test_preview_validates_input(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/admin/settings/halo/preview-mapping', [])->assertStatus(422);
        $this->actingAs($admin)->postJson('/admin/settings/halo/preview-mapping', ['domain' => 'nope.com'])->assertStatus(422);
        Domain::create(['name' => 'acme.com']);
        $this->actingAs($admin)->postJson('/admin/settings/halo/preview-mapping', ['domain' => 'acme.com', 'halo' => ['asset_key_fields' => [1 => 'bogus']]])->assertStatus(422);
    }

    // ---------------- Settings save + validation + audit ----------------

    private function settingsPayload(array $halo = [], array $itglue = []): array
    {
        return array_filter([
            'halo' => $halo ?: null,
            'itglue' => $itglue ?: null,
        ]);
    }

    public function test_settings_save_stores_halo_mapping_and_audits_it_without_secrets(): void
    {
        $this->actingAs($this->admin())->post(route('admin.settings.update'), $this->settingsPayload([
            'base_url' => 'https://halo.example.com',
            'api_key' => '********',
            'asset_type_id' => '7',
            'asset_type_name' => 'Domain',
            'asset_field_ids' => ['domain' => '201', 'expiry' => '202', 'nameservers' => '0', 'whois' => ''],
            'asset_field_names' => ['domain' => 'Domain Name', 'expiry' => 'Expiry Date', 'nameservers' => '', 'whois' => ''],
            'asset_key_fields' => [1 => 'domain', 2 => 'expiry', 3 => ''],
        ]))->assertSessionHasNoErrors();

        $m = HaloAssetMapping::fromSettings();
        $this->assertSame(7, $m->typeId);
        $this->assertSame('Domain', $m->typeName);
        $this->assertSame(['domain' => 201, 'expiry' => 202, 'nameservers' => 0, 'whois' => 165], $m->fieldIds);
        $this->assertSame(['domain' => 'Domain Name', 'expiry' => 'Expiry Date'], $m->fieldNames);
        $this->assertSame([1 => 'domain', 2 => 'expiry', 3 => ''], $m->keyFields);
        $this->assertSame('secret-api-key', Setting::get('halo')['api_key']); // sentinel kept the secret

        $log = AuditLog::query()->where('action', 'integration.mapping.update')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('secret-api-key', json_encode($log->toArray()));
        $this->assertStringContainsString('201', json_encode($log->toArray()));
    }

    public function test_settings_reject_invalid_halo_and_itglue_mapping_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/admin/settings')->post(route('admin.settings.update'), $this->settingsPayload([
            'asset_type_id' => '0', 'asset_field_ids' => ['domain' => '-3'], 'asset_key_fields' => [1 => 'nope'],
        ]))->assertSessionHasErrors(['halo.asset_type_id', 'halo.asset_field_ids.domain', 'halo.asset_key_fields.1']);

        $this->actingAs($admin)->from('/admin/settings')->post(route('admin.settings.update'), $this->settingsPayload([], [
            'flexible_asset_type_id' => 'abc',
            'flexible_asset_traits' => ['domain' => 'Bad Key!', 'expiry' => str_repeat('a', 130)],
        ]))->assertSessionHasErrors(['itglue.flexible_asset_type_id', 'itglue.flexible_asset_traits.domain', 'itglue.flexible_asset_traits.expiry']);

        $this->assertSame(77, (int) Setting::get('itglue')['flexible_asset_type_id']); // unchanged
    }

    public function test_settings_save_stores_itglue_mapping(): void
    {
        $this->actingAs($this->admin())->post(route('admin.settings.update'), $this->settingsPayload([], [
            'api_key' => 'itg-key',
            'flexible_asset_type_id' => '4521154692859938',
            'flexible_asset_type_name' => 'Domains',
            'flexible_asset_traits' => ['domain' => 'dom-name', 'name_servers' => '', 'expiry' => 'renews_on', 'whois' => 'whois', 'dns' => ''],
        ]))->assertSessionHasNoErrors();

        $cfg = Setting::get('itglue');
        $this->assertSame('4521154692859938', (string) $cfg['flexible_asset_type_id']);
        $this->assertSame('Domains', $cfg['flexible_asset_type_name']);
        $this->assertSame('renews_on', $cfg['flexible_asset_traits']['expiry']);
    }

    public function test_settings_page_renders_the_mapping_cards(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('haloLoadAssetTypes', false)
            ->assertSee('halo[asset_type_id]', false)
            ->assertSee('halo[asset_key_fields][1]', false)
            ->assertSee('itgLoadTypes', false)
            ->assertSee('itglue[flexible_asset_traits][dns]', false)
            ->assertSee('Advanced: manual IDs', false);
    }

    // ---------------- Pusher / jobs use the configured type + mapping ----------------

    public function test_mapping_defaults_when_nothing_configured(): void
    {
        $m = HaloAssetMapping::fromSettings();

        $this->assertNull($m->typeId);
        $this->assertSame('Domain', $m->typeLabel());
        $this->assertSame(['domain' => 148, 'expiry' => 149, 'nameservers' => 150, 'whois' => 165], $m->fieldIds);
        $this->assertSame([1 => 'domain', 2 => 'expiry', 3 => 'nameservers'], $m->keyFields);
        $this->assertSame([], $m->fieldNames);
    }

    public function test_configured_asset_type_is_used_without_any_lookup(): void
    {
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'k', 'asset_type_id' => 42, 'asset_type_name' => 'Web Domain']);
        Http::fake();

        $this->assertSame(42, app(HaloPsaClient::class)->getDomainAssetTypeId());
        Http::assertNothingSent();
    }

    public function test_domain_name_lookup_is_only_the_fallback(): void
    {
        $this->fakeHaloTypes();

        $this->assertSame(7, app(HaloPsaClient::class)->getDomainAssetTypeId());
    }

    public function test_pusher_sends_configured_type_field_ids_names_and_key_fields(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/client/*' => Http::response(['id' => 10, 'site_id' => 3, 'site_name' => 'Main']),
            'halo.example.com/api/site*' => Http::response(['sites' => [['id' => 3, 'name' => 'Main']]]),
            'halo.example.com/api/asset*' => Http::response([['id' => 900]]),
        ]);
        Setting::put('halo', [
            'base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'k',
            'asset_type_id' => 42, 'asset_type_name' => 'Web Domain',
            'asset_field_ids' => ['domain' => 301, 'expiry' => 302, 'nameservers' => 0, 'whois' => 304],
            'asset_field_names' => ['domain' => 'Domain', 'expiry' => 'Renewal', 'whois' => 'Whois text'],
            'asset_key_fields' => [1 => 'expiry', 2 => 'domain', 3 => ''],
        ]);
        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'halo_default_site_id' => 3, 'halo_synced_at' => now()]);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01', 'name_servers' => ['ns1.x.com']]);
        $domain->forceFill(['whois_synced_at' => now(), 'whois_data' => ['registrar' => 'X']])->save();

        $halo = app(HaloPsaClient::class);
        $typeId = $halo->getDomainAssetTypeId();
        app(HaloDomainPusher::class)->push($domain->fresh(), $halo, $typeId, true);

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/api/asset')) {
                return false;
            }
            $asset = $r->data()[0];
            $fields = $asset['fields'];

            return $asset['assettype_id'] === 42
                && array_column($fields, 'id') === [301, 302, 304]
                && array_column($fields, 'name') === ['Domain', 'Renewal', 'Whois text']
                && $fields[0]['value'] === 'acme.com'
                && $fields[1]['value'] === '2030-01-01'
                && $asset['key_field'] === '2030-01-01'
                && $asset['key_field2'] === 'acme.com'
                && ! array_key_exists('key_field3', $asset);
        });
        $this->assertDatabaseHas('halo_assets', ['halo_id' => 900, 'assettype_id' => 42, 'assettype_name' => 'Web Domain']);
    }

    public function test_default_payload_is_unchanged_for_existing_installs(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/client/*' => Http::response(['id' => 10, 'site_id' => 3, 'site_name' => 'Main']),
            'halo.example.com/api/site*' => Http::response(['sites' => [['id' => 3, 'name' => 'Main']]]),
            'halo.example.com/api/asset*' => Http::response([['id' => 901]]),
        ]);
        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'halo_default_site_id' => 3, 'halo_synced_at' => now()]);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01', 'name_servers' => ['ns1.x.com']]);
        $domain->forceFill(['whois_synced_at' => now(), 'whois_data' => ['registrar' => 'X']])->save();

        app(HaloDomainPusher::class)->push($domain->fresh(), app(HaloPsaClient::class), 7, true);

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/api/asset')) {
                return false;
            }
            $a = $r->data()[0];

            return array_column($a['fields'], 'id') === [148, 149, 150, 165]
                && ! array_key_exists('name', $a['fields'][0])
                && $a['key_field'] === 'acme.com' && $a['key_field2'] === '2030-01-01' && $a['key_field3'] === 'ns1.x.com';
        });
        $this->assertDatabaseHas('halo_assets', ['halo_id' => 901, 'assettype_name' => 'Domain']);
    }

    public function test_changing_the_mapping_changes_the_sync_hash_so_domains_are_repushed(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/client/*' => Http::response(['id' => 10, 'site_id' => 3, 'site_name' => 'Main']),
            'halo.example.com/api/site*' => Http::response(['sites' => [['id' => 3, 'name' => 'Main']]]),
            'halo.example.com/api/asset*' => Http::response([['id' => 902]]),
        ]);
        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'halo_default_site_id' => 3, 'halo_synced_at' => now()]);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01', 'name_servers' => ['ns1.x.com']]);
        $halo = app(HaloPsaClient::class);
        $pusher = app(HaloDomainPusher::class);

        $this->assertNotSame('skipped', $pusher->push($domain->fresh(), $halo, 7));
        $this->assertSame('skipped', $pusher->push($domain->fresh(), $halo, 7));

        Setting::put('halo', Setting::get('halo') + ['asset_field_ids' => ['domain' => 999]]);
        $this->assertNotSame('skipped', $pusher->push($domain->fresh(), $halo, 7), 'field id change must re-push');
        $this->assertSame('skipped', $pusher->push($domain->fresh(), $halo, 7));

        Setting::put('halo', Setting::get('halo') + ['asset_field_names' => ['domain' => 'Domain']]);
        $this->assertNotSame('skipped', $pusher->push($domain->fresh(), $halo, 7), 'field name change must re-push');
    }

    public function test_halo_assets_job_filters_by_the_configured_asset_type(): void
    {
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'k', 'asset_type_id' => 42]);
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/asset*' => Http::response(['record_count' => 0, 'assets' => []]),
        ]);

        (new SyncHaloAssetsJob())->handle();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/asset?') && str_contains($r->url(), 'assettype_id=42'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/assettype'));
        $this->assertSame(SyncRun::OK, SyncRun::first()->status);
    }

    public function test_halo_assets_job_fails_clearly_when_no_type_is_configured_or_found(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype*' => Http::response(['assettypes' => [['id' => 9, 'name' => 'Server']]]),
        ]);

        (new SyncHaloAssetsJob())->handle();

        $run = SyncRun::first();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('Settings', $run->error);
    }

    // ---------------- IT Glue ----------------

    private function fakeItGlueTypes(): void
    {
        Http::fake([
            'api.itglue.com/flexible_asset_types/77*' => Http::response([
                'data' => ['id' => '77', 'type' => 'flexible-asset-types', 'attributes' => ['name' => 'Domains']],
                'included' => [
                    ['id' => '1', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Domain Name', 'name-key' => 'domain-name', 'kind' => 'Text', 'required' => true, 'order' => 1]],
                    ['id' => '2', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Renewal', 'name-key' => 'renewal-date', 'kind' => 'Date', 'required' => false, 'order' => 2]],
                    ['id' => '3', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Name Servers', 'name-key' => 'name-servers', 'kind' => 'Textbox', 'required' => false, 'order' => 3]],
                    ['id' => '4', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Contact', 'name-key' => 'contact', 'kind' => 'Tag', 'required' => false, 'order' => 4]],
                    ['id' => '5', 'type' => 'organizations', 'attributes' => ['name' => 'ignored']],
                ],
            ]),
            'api.itglue.com/flexible_asset_types*' => Http::response([
                'data' => [
                    ['id' => '88', 'type' => 'flexible-asset-types', 'attributes' => ['name' => 'Servers', 'icon' => 'server']],
                    ['id' => '77', 'type' => 'flexible-asset-types', 'attributes' => ['name' => 'Domains', 'icon' => 'globe']],
                ],
                'meta' => ['next-page' => null],
            ]),
        ]);
    }

    public function test_itglue_types_endpoint_filters_enabled_and_sorts(): void
    {
        $this->fakeItGlueTypes();

        $json = $this->actingAs($this->admin())->getJson('/admin/settings/itglue/flexible-asset-types')->assertOk()->json();

        $this->assertSame(['77', '88'], array_column($json['types'], 'id'));
        $this->assertSame('77', (string) $json['stored_id']);
        Http::assertSent(fn (HttpRequest $r) => str_contains(urldecode($r->url()), 'filter[enabled]=true') && str_contains($r->url(), 'sort=name'));
    }

    public function test_itglue_fields_endpoint_returns_fields_kinds_and_suggestions(): void
    {
        $this->fakeItGlueTypes();

        $json = $this->actingAs($this->admin())->getJson('/admin/settings/itglue/flexible-asset-types/77/fields')->assertOk()->json();

        $this->assertSame(['domain-name', 'renewal-date', 'name-servers', 'contact'], array_column($json['fields'], 'name_key'));
        $this->assertSame([true, true, true, false], array_column($json['fields'], 'fillable'));
        $this->assertSame('domain-name', $json['suggestions']['domain']);
        $this->assertSame('renewal-date', $json['suggestions']['expiry']);
        $this->assertSame('name-servers', $json['suggestions']['name_servers']);
        $this->assertNull($json['suggestions']['whois']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/flexible_asset_types/77') && str_contains(urldecode($r->url()), 'include=flexible_asset_fields'));
    }

    public function test_itglue_endpoints_require_configuration(): void
    {
        Setting::put('itglue', []);
        Http::fake();

        $this->actingAs($this->admin())->getJson('/admin/settings/itglue/flexible-asset-types')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_itglue_traits_use_the_mapping_and_defaults(): void
    {
        $domain = Domain::create(['name' => 'acme.com', 'expiry_date' => '2030-01-05', 'name_servers' => ['ns1.x.com']]);

        $default = (new ItGlueClient())->buildDomainTraits($domain, null);
        $this->assertSame(['domain-name', 'name-servers', 'expiry', 'whois'], array_keys($default));

        Setting::put('itglue', ['api_key' => 'k', 'flexible_asset_type_id' => 77, 'flexible_asset_traits' => [
            'domain' => 'dom', 'name_servers' => '', 'expiry' => 'renewal-date', 'whois' => 'whois-text', 'dns' => 'dns-html',
        ]]);

        $mapped = (new ItGlueClient())->buildDomainTraits($domain, ['A' => []]);
        $this->assertSame('acme.com', $mapped['dom']);
        $this->assertSame('2030-01-05', $mapped['renewal-date']);
        $this->assertArrayHasKey('whois-text', $mapped);
        $this->assertArrayHasKey('dns-html', $mapped);
        $this->assertArrayNotHasKey('name-servers', $mapped);
        $this->assertCount(4, $mapped);
    }

    public function test_itglue_push_hash_changes_with_the_trait_mapping(): void
    {
        Http::fake([
            'api.itglue.com/*' => Http::response(['data' => ['id' => '5001']], 201),
        ]);
        $client = Client::create(['business_name' => 'Acme', 'itglue_org_id' => '500']);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-05', 'itglue_id' => 5001]);

        $hashFor = function () use ($domain) {
            $d = $domain->fresh();
            $d->forceFill(['itglue_sync_hash' => null])->save();
            app(ItGlueDomainPusher::class)->push($d->fresh(), new ItGlueClient(), true);

            return $domain->fresh()->itglue_sync_hash;
        };

        $a = $hashFor();
        Setting::put('itglue', ['api_key' => 'itg-key', 'flexible_asset_type_id' => 77, 'flexible_asset_traits' => ['domain' => 'other-key']]);
        $b = $hashFor();

        $this->assertNotNull($a);
        $this->assertNotSame($a, $b);
    }

    // ---------------- Per-item run results ----------------

    public function test_run_items_reports_only_domains_touched_by_the_run(): void
    {
        $admin = $this->admin();
        $run = SyncRun::create(['provider' => 'halo', 'scope' => 'domains', 'status' => 'running', 'total' => 4, 'started_at' => now()->subMinute()]);
        $run->forceFill(['created_at' => now()->subMinute()])->save();

        $old = Domain::create(['name' => 'old.com']);
        $old->forceFill(['halo_synced_at' => now()->subDay()])->save();
        $old->timestamps = false;
        $old->forceFill(['updated_at' => now()->subDay()])->save();

        $ok = Domain::create(['name' => 'ok.com']);
        $ok->forceFill(['halo_synced_at' => now(), 'halo_sync_error' => null])->save();
        $bad = Domain::create(['name' => 'bad.com']);
        $bad->forceFill(['halo_sync_error' => 'Halo API POST /asset failed with HTTP 422'])->save();
        Domain::create(['name' => 'pending.com']);

        $json = $this->actingAs($admin)->getJson('/admin/sync/runs/' . $run->id . '/items')->assertOk()->json();

        $byId = collect($json['items'])->keyBy('domain_id');
        $this->assertCount(2, $byId);
        $this->assertSame('done', $byId[$ok->id]['status']);
        $this->assertNotNull($byId[$ok->id]['synced_at']);
        $this->assertSame('failed', $byId[$bad->id]['status']);
        $this->assertStringContainsString('422', $byId[$bad->id]['error']);
        $this->assertFalse($json['finished']);
        $this->assertNotEmpty($json['server_time']);

        // Incremental poll: nothing changed after server_time.
        $again = $this->actingAs($admin)->getJson('/admin/sync/runs/' . $run->id . '/items?since=' . urlencode(now()->addSeconds(30)->toIso8601String()))->assertOk()->json();
        $this->assertSame([], $again['items']);
    }

    public function test_run_items_use_itglue_columns_and_ignore_other_scopes(): void
    {
        $admin = $this->admin();
        $run = SyncRun::create(['provider' => 'itglue', 'scope' => 'configurations', 'status' => 'ok', 'total' => 1, 'finished_at' => now()]);
        $run->forceFill(['created_at' => now()->subMinute()])->save();
        $d = Domain::create(['name' => 'ok.com']);
        $d->forceFill(['itglue_synced_at' => now()])->save();
        $h = Domain::create(['name' => 'halo-only.com']);
        $h->forceFill(['halo_synced_at' => now()])->save();

        $json = $this->actingAs($admin)->getJson('/admin/sync/runs/' . $run->id . '/items')->assertOk()->json();
        $this->assertSame([$d->id], array_column($json['items'], 'domain_id'));
        $this->assertTrue($json['finished']);

        $clients = SyncRun::create(['provider' => 'halo', 'scope' => 'clients', 'status' => 'ok']);
        $this->actingAs($admin)->getJson('/admin/sync/runs/' . $clients->id . '/items')->assertOk()->assertJson(['items' => []]);
    }

    public function test_run_items_endpoint_requires_sync_permission(): void
    {
        $run = SyncRun::create(['provider' => 'halo', 'scope' => 'domains', 'status' => 'running']);

        $this->actingAs($this->admin(true, false))->getJson('/admin/sync/runs/' . $run->id . '/items')->assertForbidden();
    }

    public function test_itglue_clients_endpoint_sends_organisations_once(): void
    {
        \App\Models\ItglueOrganization::create(['itglue_id' => 500, 'name' => 'Acme IT', 'synced_at' => now()]);
        Client::create(['business_name' => 'A']);
        Client::create(['business_name' => 'B']);

        $json = $this->actingAs($this->admin())->getJson('/admin/sync/itglue/clients')->assertOk()->json();

        $this->assertCount(2, $json['clients']);
        $this->assertArrayNotHasKey('organizations', $json['clients'][0]);
        $this->assertSame([['id' => '500', 'name' => 'Acme IT']], $json['organizations']);
    }
}
