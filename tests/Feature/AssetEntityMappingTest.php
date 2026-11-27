<?php

namespace Tests\Feature;

use App\Jobs\PushEntityAssetsJob;
use App\Jobs\SyncEntityAssetsJob;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HostingService;
use App\Models\InternetService;
use App\Models\Setting;
use App\Models\SslCertificate;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Assets\AssetEntity;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueAssetMapping;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Sync\HaloEntityPusher;
use App\Services\Sync\ItGlueEntityPusher;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Settings -> HaloPSA / IT Glue -> Assets: one mapping per DomainDash entity (domains, internet services, hosting
 * services, SSL certificates), the migration of the old single domain mapping, the options endpoints, and the
 * entity pushers / jobs behind "Sync to Halo" / "Sync to IT Glue".
 */
class AssetEntityMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key']);
        Setting::put('itglue', ['api_key' => 'itg-key']);
    }

    private function admin(bool $administrator = true, bool $sync = true): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        if ($administrator) {
            $user->assignRole('Administrator');
        }
        if ($sync) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function haloMapping(string $entity, array $over = []): void
    {
        $halo = Setting::get('halo', []);
        $halo['asset_mappings'][$entity] = $over + [
            'enabled' => true,
            'asset_type_id' => 55,
            'asset_type_name' => 'Internet Service',
            'asset_field_ids' => [],
            'asset_field_names' => [],
            'asset_key_fields' => [1 => '', 2 => '', 3 => ''],
        ];
        Setting::put('halo', $halo);
    }

    private function itglueMapping(string $entity, array $over = []): void
    {
        $cfg = Setting::get('itglue', []);
        $cfg['asset_mappings'][$entity] = $over + [
            'enabled' => true,
            'flexible_asset_type_id' => 88,
            'flexible_asset_type_name' => 'Internet',
            'flexible_asset_traits' => [],
        ];
        Setting::put('itglue', $cfg);
    }

    private function linkedClient(): Client
    {
        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'itglue_org_id' => '321']);
        $client->forceFill(['halo_default_site_id' => 3, 'halo_synced_at' => now()])->save();

        return $client;
    }

    private function internetService(Client $client = null, array $over = []): InternetService
    {
        $plan = VocusPlan::create(['plan_id' => 'NBN100', 'name' => 'Fibre 100', 'download_mbps' => 100, 'upload_mbps' => 20, 'technology' => 'FTTP', 'active' => true]);

        return InternetService::create($over + [
            'vocus_service_id' => 'SVC-1001', 'client_id' => $client?->id, 'plan_id' => 'NBN100', 'vocus_plan_id' => $plan->id,
            'service_status' => 'ACTIVE', 'service_type' => 'FTTP', 'directory_id' => 'LOC000123', 'avc_id' => 'AVC9',
            'username' => 'user@realm.example', 'ip_address' => '203.0.113.7', 'address_long' => '1 Test St, Sydney', 'service_class' => '12',
            'ntd_id' => 'NTD1', 'uni_port' => 'UNI-D1',
        ]);
    }

    /** Http::fake() stubs are first-match-wins and accumulate, so start every fake from a clean factory. */
    private function resetHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
    }

    private function fakeHalo(array $existing = []): void
    {
        $this->resetHttp();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/asset*' => function (HttpRequest $r) use ($existing) {
                if ($r->method() === 'GET') {
                    return Http::response(['assets' => $existing]);
                }

                return Http::response([['id' => 900]]);
            },
        ]);
    }

    private function fakeItGlue(array $existing = []): void
    {
        $this->resetHttp();
        Http::fake([
            'api.itglue.com/flexible_assets*' => function (HttpRequest $r) use ($existing) {
                if ($r->method() === 'GET') {
                    return Http::response(['data' => $existing]);
                }

                return Http::response(['data' => ['id' => '5001']], $r->method() === 'POST' ? 201 : 200);
            },
        ]);
    }

    // ---------------- Reading / migrating the old domain mapping ----------------

    public function test_existing_domain_mapping_is_read_as_the_domains_row(): void
    {
        Setting::put('halo', Setting::get('halo') + [
            'asset_type_id' => 7, 'asset_type_name' => 'Domain',
            'asset_field_ids' => ['domain' => 201, 'expiry' => 0],
            'asset_field_names' => ['domain' => 'Domain Name'],
            'asset_key_fields' => [1 => 'expiry', 2 => '', 3 => ''],
        ]);
        Setting::put('itglue', Setting::get('itglue') + [
            'flexible_asset_type_id' => 77, 'flexible_asset_type_name' => 'Domains',
            'flexible_asset_traits' => ['domain' => 'dom', 'expiry' => 'renews', 'dns' => ''],
        ]);

        $halo = HaloAssetMapping::forEntity('domain');
        $this->assertTrue($halo->enabled);
        $this->assertSame(7, $halo->typeId);
        $this->assertSame(201, $halo->fieldIds['domain']);
        $this->assertSame(0, $halo->fieldIds['expiry']);
        $this->assertSame(150, $halo->fieldIds['nameservers']); // unset keeps the built-in default
        $this->assertSame('expiry', $halo->keyFields[1]);
        $this->assertSame(HaloAssetMapping::fromSettings()->fingerprint(), $halo->fingerprint());

        $itg = ItGlueAssetMapping::forEntity('domain');
        $this->assertTrue($itg->enabled);
        $this->assertSame(77, $itg->typeId);
        $this->assertSame('renews', $itg->traits['expiry']);
        $this->assertSame('', $itg->traits['dns']);
        // The client still reads the same thing for the (unchanged) domain pusher.
        $this->assertSame(77, (new ItGlueClient())->configuredFlexibleAssetTypeId());
        $this->assertSame('dom', (new ItGlueClient())->traitMapping()['domain']);

        // Other entities are off until configured.
        foreach ([AssetEntity::INTERNET, AssetEntity::HOSTING, AssetEntity::SSL] as $entity) {
            $this->assertFalse(HaloAssetMapping::forEntity($entity)->enabled);
            $this->assertNull(HaloAssetMapping::forEntity($entity)->typeId);
            $this->assertFalse(ItGlueAssetMapping::forEntity($entity)->enabled);
        }
    }

    public function test_index_lists_all_entities_with_the_domain_row_migrated(): void
    {
        Setting::put('halo', Setting::get('halo') + ['asset_type_id' => 7, 'asset_type_name' => 'Domain', 'asset_field_ids' => ['domain' => 201]]);

        $rows = $this->actingAs($this->admin())->getJson(route('admin.settings.asset-mappings.index', 'halo'))
            ->assertOk()->json('rows');

        $this->assertSame(['domain', 'internet_service', 'hosting_service', 'ssl_certificate'], array_column($rows, 'id'));
        $domain = $rows[0];
        $this->assertSame('Domains', $domain['cells']['entity']);
        $this->assertSame('Domain', $domain['cells']['asset_type']);
        $this->assertSame('Enabled', $domain['cells']['enabled']);
        $this->assertSame('201', $domain['values']['fields']['map']['domain']['id']);
        $this->assertSame('1', $domain['values']['enabled']);

        $internet = $rows[1];
        $this->assertSame('Not configured', $internet['cells']['asset_type']);
        $this->assertSame('Off', $internet['cells']['enabled']);
        $this->assertSame('0 of 15 mapped', $internet['cells']['fields']);
        $this->assertContains('avc_id', array_column($internet['values']['fields']['points'], 'key'));

        $itg = $this->actingAs($this->admin())->getJson(route('admin.settings.asset-mappings.index', 'itglue'))->assertOk()->json('rows');
        $this->assertSame('Off', $itg[1]['cells']['enabled']);
        $this->assertNull($itg[1]['values']['fields']['key_fields']);
    }

    // ---------------- CRUD ----------------

    public function test_halo_mapping_crud_stores_the_new_structure_and_audits(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->putJson(route('admin.settings.asset-mappings.update', ['halo', 'internet_service']), [
            'enabled' => true,
            'asset_type' => '55',
            'asset_type_label' => 'Internet Service',
            'fields' => ['service_id' => '301', 'plan' => '302', 'username' => '', 'avc_id' => '0'],
            'fields_label' => ['service_id' => 'Service ID', 'plan' => 'Plan'],
            'key_fields' => [1 => 'service_id', 2 => 'plan', 3 => ''],
        ])->assertOk();

        $this->assertSame('Enabled', $res->json('row.cells.enabled'));
        $this->assertSame('Internet Service', $res->json('row.cells.asset_type'));
        $this->assertSame('2 of 15 mapped', $res->json('row.cells.fields'));

        $stored = Setting::get('halo')['asset_mappings']['internet_service'];
        $this->assertTrue($stored['enabled']);
        $this->assertSame(55, $stored['asset_type_id']);
        $this->assertSame(['service_id' => 301, 'plan' => 302, 'username' => 0, 'avc_id' => 0], $stored['asset_field_ids']);
        $this->assertSame(['service_id' => 'Service ID', 'plan' => 'Plan'], $stored['asset_field_names']);
        $this->assertSame('plan', $stored['asset_key_fields'][2]);
        // Connection settings untouched.
        $this->assertSame('https://halo.example.com', Setting::get('halo')['base_url']);

        $mapping = HaloAssetMapping::forEntity('internet_service');
        $this->assertSame(2, $mapping->mappedCount());
        $this->assertTrue($mapping->active());

        $this->assertTrue(AuditLog::where('action', 'settings.mapping.updated')->where('description', 'like', '%halo/internet_service%')->exists());
        $this->assertStringNotContainsString('secret-api-key', json_encode(AuditLog::all()->toArray()));

        // Reset
        $this->actingAs($admin)->deleteJson(route('admin.settings.asset-mappings.destroy', ['halo', 'internet_service']))->assertOk();
        $this->assertArrayNotHasKey('internet_service', Setting::get('halo')['asset_mappings']);
        $this->assertFalse(HaloAssetMapping::forEntity('internet_service')->enabled);
    }

    public function test_itglue_mapping_crud_and_domain_mirrors_the_legacy_keys(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(route('admin.settings.asset-mappings.update', ['itglue', 'ssl_certificate']), [
            'enabled' => true, 'asset_type' => '4521154692859938', 'asset_type_label' => 'SSL',
            'fields' => ['common_name' => 'cn', 'expiry' => 'expires-on', 'status' => ''],
        ])->assertOk()->assertJsonPath('row.cells.fields', '2 of 6 mapped');

        $stored = Setting::get('itglue')['asset_mappings']['ssl_certificate'];
        $this->assertSame(4521154692859938, $stored['flexible_asset_type_id']);
        $this->assertSame('expires-on', $stored['flexible_asset_traits']['expiry']);

        $this->actingAs($admin)->putJson(route('admin.settings.asset-mappings.update', ['itglue', 'domain']), [
            'enabled' => true, 'asset_type' => '77', 'asset_type_label' => 'Domains',
            'fields' => ['domain' => 'domain-name', 'name_servers' => 'ns', 'expiry' => '', 'whois' => '', 'dns' => ''],
        ])->assertOk();

        $cfg = Setting::get('itglue');
        $this->assertSame(77, $cfg['asset_mappings']['domain']['flexible_asset_type_id']);
        $this->assertSame(77, $cfg['flexible_asset_type_id']); // legacy mirror
        $this->assertSame('ns', $cfg['flexible_asset_traits']['name_servers']);
        $this->assertSame('ns', (new ItGlueClient())->traitMapping()['name_servers']);
        $this->assertSame('itg-key', $cfg['api_key']);
    }

    public function test_halo_domain_mapping_saved_through_the_new_ui_drives_the_domain_pusher_settings(): void
    {
        $this->actingAs($this->admin())->putJson(route('admin.settings.asset-mappings.update', ['halo', 'domain']), [
            'enabled' => true, 'asset_type' => '42', 'asset_type_label' => 'Web Domain',
            'fields' => ['domain' => '301', 'expiry' => '302', 'nameservers' => '', 'whois' => '304'],
            'fields_label' => ['domain' => 'Domain', 'expiry' => 'Renewal', 'whois' => 'Whois text'],
            'key_fields' => [1 => 'expiry', 2 => 'domain', 3 => ''],
        ])->assertOk();

        $m = HaloAssetMapping::fromSettings();
        $this->assertSame(42, $m->typeId);
        $this->assertSame([301, 302, 0, 304], array_values($m->fieldIds));
        $this->assertSame('expiry', $m->keyFields[1]);
        $this->assertSame(42, Setting::get('halo')['asset_type_id']); // legacy mirror
    }

    public function test_mapping_validation(): void
    {
        $admin = $this->admin();
        $url = route('admin.settings.asset-mappings.update', ['halo', 'hosting_service']);

        // Turning a non-domain entity on without a type.
        $this->actingAs($admin)->putJson($url, ['enabled' => true, 'fields' => []])->assertStatus(422)->assertJsonValidationErrors('asset_type');
        // Unknown DomainDash value / bad field id / bad key field.
        $this->actingAs($admin)->putJson($url, ['asset_type' => '5', 'fields' => ['avc_id' => '1']])->assertStatus(422)->assertJsonValidationErrors('fields');
        $this->actingAs($admin)->putJson($url, ['asset_type' => '5', 'fields' => ['plan' => 'abc']])->assertStatus(422)->assertJsonValidationErrors('fields');
        $this->actingAs($admin)->putJson($url, ['asset_type' => '5', 'key_fields' => [1 => 'nope']])->assertStatus(422)->assertJsonValidationErrors('key_fields');
        $this->actingAs($admin)->putJson($url, ['asset_type' => 'x1'])->assertStatus(422)->assertJsonValidationErrors('asset_type');
        // IT Glue trait keys.
        $this->actingAs($admin)->putJson(route('admin.settings.asset-mappings.update', ['itglue', 'hosting_service']), ['asset_type' => '5', 'fields' => ['plan' => 'Bad Key!']])
            ->assertStatus(422)->assertJsonValidationErrors('fields');
        // Unknown entity / provider.
        $this->actingAs($admin)->putJson(route('admin.settings.asset-mappings.update', ['halo', 'bogus']), [])->assertNotFound();
        $this->actingAs($admin)->getJson('/admin/settings/asset-mappings/other')->assertNotFound();

        $this->assertArrayNotHasKey('hosting_service', Setting::get('halo')['asset_mappings'] ?? []);
    }

    public function test_mapping_endpoints_are_administrator_only(): void
    {
        $nonAdmin = $this->admin(false, true);
        foreach ([
            ['getJson', route('admin.settings.asset-mappings.index', 'halo')],
            ['postJson', route('admin.settings.asset-mappings.store', 'itglue')],
            ['putJson', route('admin.settings.asset-mappings.update', ['halo', 'domain'])],
            ['deleteJson', route('admin.settings.asset-mappings.destroy', ['halo', 'domain'])],
        ] as [$method, $url]) {
            $this->actingAs($nonAdmin)->{$method}($url)->assertForbidden();
            auth()->logout();
            $this->assertContains($this->{$method}($url)->status(), [401, 302, 403]);
        }
        $this->assertArrayNotHasKey('asset_mappings', Setting::get('halo'));
    }

    public function test_store_creates_a_mapping_from_the_body_entity(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.settings.asset-mappings.store', 'halo'), ['entity' => 'ssl_certificate', 'enabled' => false, 'asset_type' => '9'])
            ->assertCreated()->assertJsonPath('row.id', 'ssl_certificate');
        $this->assertSame(9, HaloAssetMapping::forEntity('ssl_certificate')->typeId);
        $this->actingAs($this->admin())->postJson(route('admin.settings.asset-mappings.store', 'halo'), ['entity' => 'nope'])->assertStatus(422);
    }

    // ---------------- Options endpoints ----------------

    public function test_halo_type_fields_endpoint_suggests_by_entity(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype/55*' => Http::response([
                'id' => 55, 'name' => 'Internet Service',
                'field_list' => [
                    ['id' => 11, 'name' => 'Service ID', 'kind' => 1],
                    ['id' => 12, 'name' => 'AVC ID', 'kind' => 1],
                    ['id' => 13, 'name' => 'Plan', 'kind' => 1],
                    ['id' => 14, 'name' => 'IP Address', 'kind' => 1],
                    ['id' => 15, 'name' => 'Service Address', 'kind' => 1],
                    ['id' => 16, 'name' => 'Colour', 'kind' => 1],
                ],
            ]),
        ]);

        $res = $this->actingAs($this->admin())->getJson('/admin/settings/halo/asset-types/55/fields?entity=internet_service')->assertOk();
        $s = $res->json('suggestions');
        $this->assertSame(11, $s['service_id']);
        $this->assertSame(12, $s['avc_id']);
        $this->assertSame(13, $s['plan']);
        $this->assertSame(14, $s['ip_address']);
        $this->assertSame(15, $s['address']);
        $this->assertNull($s['username']);
        $this->assertSame('internet_service', $res->json('entity'));
        $this->assertArrayHasKey('ntd_port', $res->json('points'));

        // Options source used by the field pickers works for any asset type; unknown entity is rejected.
        $this->actingAs($this->admin())->getJson('/admin/settings/halo/asset-types/55/fields?entity=bogus')->assertStatus(422);
        $this->actingAs($this->admin())->getJson('/admin/settings/options/halo.asset-fields?asset_type_id=55')->assertOk()->assertJsonCount(6, 'options');
    }

    public function test_itglue_type_fields_endpoint_suggests_by_entity_and_respects_kinds(): void
    {
        Http::fake([
            'api.itglue.com/flexible_asset_types/88*' => Http::response(['data' => ['id' => '88', 'attributes' => ['name' => 'SSL'], 'relationships' => ['flexible-asset-fields' => ['data' => []]]],
                'included' => [
                    ['id' => '1', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Common Name', 'name-key' => 'common-name', 'kind' => 'Text']],
                    ['id' => '2', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Expiry', 'name-key' => 'expiry-date', 'kind' => 'Date']],
                    ['id' => '3', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Issued On', 'name-key' => 'issued-on', 'kind' => 'Date']],
                    ['id' => '4', 'type' => 'flexible-asset-fields', 'attributes' => ['name' => 'Linked Org', 'name-key' => 'linked-org', 'kind' => 'Tag']],
                ]]),
        ]);

        $res = $this->actingAs($this->admin())->getJson('/admin/settings/itglue/flexible-asset-types/88/fields?entity=ssl_certificate')->assertOk();
        $s = $res->json('suggestions');
        $this->assertSame('common-name', $s['common_name']);
        $this->assertSame('expiry-date', $s['expiry']);
        $this->assertSame('issued-on', $s['issued']);
        $this->assertArrayHasKey('cert_id', $res->json('points'));
        $this->assertSame([], $res->json('defaults'));
    }

    public function test_entity_option_sources(): void
    {
        $admin = $this->actingAs($this->admin());
        $ids = array_column($admin->getJson('/admin/settings/options/dd.asset-entities')->assertOk()->json('options'), 'id');
        $this->assertSame(AssetEntity::KEYS, $ids);
        $points = array_column($admin->getJson('/admin/settings/options/dd.asset-points?entity=hosting_service')->assertOk()->json('options'), 'id');
        $this->assertContains('bandwidth', $points);
        $this->assertNotContains('nameservers', $points);
        $legacy = array_column($admin->getJson('/admin/settings/options/dd.asset-points')->assertOk()->json('options'), 'id');
        $this->assertSame(['domain', 'expiry', 'nameservers', 'whois'], $legacy);
    }

    // ---------------- Halo entity pusher ----------------

    public function test_halo_pusher_builds_the_payload_for_each_entity(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo();
        $halo = app(HaloPsaClient::class);
        $pusher = app(HaloEntityPusher::class);

        // Internet service
        $this->haloMapping(AssetEntity::INTERNET, [
            'asset_field_ids' => ['service_id' => 301, 'plan' => 302, 'username' => 303, 'avc_id' => 0, 'address' => 304, 'ntd_port' => 305, 'technology' => 306],
            'asset_field_names' => ['service_id' => 'Service ID', 'plan' => 'Plan'],
            'asset_key_fields' => [1 => 'service_id', 2 => 'plan', 3 => 'status'],
        ]);
        $service = $this->internetService($client);
        $this->assertSame('created', $pusher->push(AssetEntity::INTERNET, $service->fresh(['client', 'vocusPlan']), $halo, HaloAssetMapping::forEntity(AssetEntity::INTERNET)));

        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/api/asset')) {
                return false;
            }
            $a = $r->data()[0];
            $byId = collect($a['fields'])->keyBy('id');

            return $a['assettype_id'] === 55 && $a['client_id'] === 10 && $a['site_id'] === 3
                && $a['inventory_number'] === 'SVC-1001' && ! isset($a['id'])
                && $byId[301]['value'] === 'SVC-1001' && $byId[301]['name'] === 'Service ID'
                && $byId[302]['value'] === 'Fibre 100 (100/20 Mbps)'
                && $byId[303]['value'] === 'user@realm.example'
                && $byId[304]['value'] === '1 Test St, Sydney'
                && $byId[305]['value'] === 'NTD1 / UNI-D UNI-D1'
                && $byId[306]['value'] === 'FTTP'
                && $byId->count() === 6 // avc_id is "don't sync"
                && $a['key_field'] === 'SVC-1001' && $a['key_field2'] === 'Fibre 100 (100/20 Mbps)' && $a['key_field3'] === 'ACTIVE';
        });
        $this->assertSame(900, $service->fresh()->halo_asset_id);
        $this->assertNotNull($service->fresh()->halo_asset_synced_at);
        $this->assertNull($service->fresh()->halo_asset_sync_error);
        $this->assertDatabaseHas('halo_assets', ['halo_id' => 900, 'assettype_id' => 55, 'inventory_number' => 'SVC-1001', 'halo_client_id' => 10]);
        // Billing columns are untouched by the asset sync.
        $this->assertNull($service->fresh()->halo_subscription_id);
        $this->assertNull($service->fresh()->halo_synced_at);

        // Hosting
        $this->haloMapping(AssetEntity::HOSTING, ['asset_type_id' => 56, 'asset_field_ids' => ['domain' => 401, 'plan' => 402, 'disk' => 403, 'renewal' => 404]]);
        $host = HostingService::create(['client_id' => $client->id, 'hoid' => 'H1', 'plan' => 'Business', 'server' => 'srv1', 'disk_limit_mb' => 5000, 'disk_usage_mb' => 120, 'next_renewal_due' => '2030-02-03']);
        $host->forceFill(['domain_name' => 'acme.com', 'service_status' => 'Active'])->save();
        $pusher->push(AssetEntity::HOSTING, $host->fresh(['client', 'domain']), $halo, HaloAssetMapping::forEntity(AssetEntity::HOSTING));
        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/api/asset')) {
                return false;
            }
            $a = $r->data()[0];

            return ($a['assettype_id'] ?? null) === 56 && $a['inventory_number'] === 'acme.com'
                && collect($a['fields'])->pluck('value', 'id')->sortKeys()->all() === [401 => 'acme.com', 402 => 'Business', 403 => '120 / 5000 MB', 404 => '2030-02-03'];
        });

        // SSL
        $this->haloMapping(AssetEntity::SSL, ['asset_type_id' => 57, 'asset_field_ids' => ['common_name' => 501, 'expiry' => 502, 'product' => 503]]);
        $ssl = SslCertificate::create(['client_id' => $client->id, 'cert_id' => 'C1', 'common_name' => 'www.acme.com', 'product_name' => 'DV', 'expire_date' => '2031-05-06', 'status' => 'Issued']);
        $pusher->push(AssetEntity::SSL, $ssl->fresh('client'), $halo, HaloAssetMapping::forEntity(AssetEntity::SSL));
        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/api/asset')) {
                return false;
            }
            $a = $r->data()[0];

            return ($a['assettype_id'] ?? null) === 57 && $a['inventory_number'] === 'www.acme.com'
                && collect($a['fields'])->pluck('value', 'id')->sortKeys()->all() === [501 => 'www.acme.com', 502 => '2031-05-06', 503 => 'DV'];
        });
        $this->assertSame(900, $ssl->fresh()->halo_asset_id);
    }

    public function test_halo_pusher_create_vs_update_hash_skip_and_force(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo();
        $this->haloMapping(AssetEntity::INTERNET, ['asset_field_ids' => ['service_id' => 301]]);
        $halo = app(HaloPsaClient::class);
        $pusher = app(HaloEntityPusher::class);
        $service = $this->internetService($client);
        $mapping = HaloAssetMapping::forEntity(AssetEntity::INTERNET);
        $load = fn () => $service->fresh(['client', 'vocusPlan']);

        $this->assertSame('created', $pusher->push(AssetEntity::INTERNET, $load(), $halo, $mapping));
        // Unchanged: no request at all.
        $this->resetHttp();
        Http::fake();
        $this->assertSame('skipped', $pusher->push(AssetEntity::INTERNET, $load(), $halo, $mapping));
        Http::assertNothingSent();

        // Changed data: update with the stored id.
        $this->fakeHalo();
        $service->forceFill(['service_status' => 'SUSPEND'])->save();
        $this->haloMapping(AssetEntity::INTERNET, ['asset_field_ids' => ['service_id' => 301, 'status' => 302]]);
        $this->assertSame('updated', $pusher->push(AssetEntity::INTERNET, $load(), $halo, HaloAssetMapping::forEntity(AssetEntity::INTERNET)));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && ($r->data()[0]['id'] ?? null) === 900);

        // Force re-pushes an unchanged record.
        $this->fakeHalo();
        $this->assertSame('updated', $pusher->push(AssetEntity::INTERNET, $load(), $halo, HaloAssetMapping::forEntity(AssetEntity::INTERNET), true));
    }

    public function test_halo_pusher_adopts_an_existing_asset_instead_of_creating_a_duplicate(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo([['id' => 777, 'inventory_number' => 'SVC-1001', 'assettype_id' => 55, 'client_id' => 10]]);
        $this->haloMapping(AssetEntity::INTERNET, ['asset_field_ids' => ['service_id' => 301]]);
        $service = $this->internetService($client);

        $this->assertSame('updated', app(HaloEntityPusher::class)->push(AssetEntity::INTERNET, $service->fresh(['client', 'vocusPlan']), app(HaloPsaClient::class), HaloAssetMapping::forEntity(AssetEntity::INTERNET)));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && ($r->data()[0]['id'] ?? null) === 777);
    }

    public function test_halo_pusher_needs_a_linked_client_and_a_type(): void
    {
        $this->haloMapping(AssetEntity::INTERNET);
        $service = $this->internetService(null);
        $mapping = HaloAssetMapping::forEntity(AssetEntity::INTERNET);
        $pusher = app(HaloEntityPusher::class);
        Http::fake();

        try {
            $pusher->push(AssetEntity::INTERNET, $service->fresh(['client', 'vocusPlan']), app(HaloPsaClient::class), $mapping);
            $this->fail('expected failure');
        } catch (\App\Services\Sync\SyncItemFailed $e) {
            $this->assertSame('No client assigned', $e->getMessage());
        }

        $unlinked = Client::create(['business_name' => 'No Halo']);
        $service->forceFill(['client_id' => $unlinked->id])->save();
        $this->expectExceptionMessage('Client not linked to HaloPSA');
        $pusher->push(AssetEntity::INTERNET, $service->fresh(['client', 'vocusPlan']), app(HaloPsaClient::class), $mapping);
    }

    // ---------------- IT Glue entity pusher ----------------

    public function test_itglue_pusher_payload_create_update_and_skip(): void
    {
        $client = $this->linkedClient();
        $this->fakeItGlue();
        $this->itglueMapping(AssetEntity::INTERNET, ['flexible_asset_traits' => ['service_id' => 'service-id', 'plan' => 'plan', 'avc_id' => '', 'username' => 'pppoe-user']]);
        $itg = app(ItGlueClient::class);
        $pusher = app(ItGlueEntityPusher::class);
        $service = $this->internetService($client);
        $mapping = ItGlueAssetMapping::forEntity(AssetEntity::INTERNET);
        $load = fn () => $service->fresh(['client', 'vocusPlan']);

        $this->assertSame('created', $pusher->push(AssetEntity::INTERNET, $load(), $itg, $mapping));
        Http::assertSent(function (HttpRequest $r) {
            if ($r->method() !== 'POST') {
                return false;
            }
            $a = $r->data()['data']['attributes'];

            return $a['organization-id'] === 321 && $a['flexible-asset-type-id'] === 88 && $a['name'] === 'SVC-1001'
                && $a['traits'] === ['service-id' => 'SVC-1001', 'plan' => 'Fibre 100 (100/20 Mbps)', 'pppoe-user' => 'user@realm.example'];
        });
        $fresh = $service->fresh();
        $this->assertSame(5001, $fresh->itglue_id);
        $this->assertNotNull($fresh->itglue_synced_at);
        $this->assertNull($fresh->itglue_sync_error);

        $this->resetHttp();
        Http::fake();
        $this->assertSame('skipped', $pusher->push(AssetEntity::INTERNET, $load(), $itg, $mapping));
        Http::assertNothingSent();

        // Changed data: PATCH the stored asset (full trait set is loaded first).
        $this->resetHttp();
        Http::fake([
            'api.itglue.com/flexible_assets/5001' => function (HttpRequest $r) {
                return $r->method() === 'GET'
                    ? Http::response(['data' => ['id' => '5001', 'attributes' => ['traits' => ['keep-me' => 'x']]]])
                    : Http::response(['data' => ['id' => '5001']]);
            },
        ]);
        $service->forceFill(['username' => 'other@realm.example'])->save();
        $this->assertSame('updated', $pusher->push(AssetEntity::INTERNET, $load(), $itg, $mapping));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && $r->data()['data']['attributes']['traits']['keep-me'] === 'x'
            && $r->data()['data']['attributes']['traits']['pppoe-user'] === 'other@realm.example');
    }

    public function test_itglue_pusher_hosting_and_ssl_and_org_linkage(): void
    {
        $client = $this->linkedClient();
        $this->fakeItGlue();
        $itg = app(ItGlueClient::class);
        $pusher = app(ItGlueEntityPusher::class);

        $this->itglueMapping(AssetEntity::HOSTING, ['flexible_asset_type_id' => 91, 'flexible_asset_traits' => ['domain' => 'domain', 'ip_address' => 'ip', 'bandwidth' => 'bw']]);
        $host = HostingService::create(['client_id' => $client->id, 'hoid' => 'H1', 'ip_address' => '198.51.100.1', 'bandwidth_used_mb' => 10, 'bandwidth_limit_mb' => 100]);
        $host->forceFill(['domain_name' => 'acme.com'])->save();
        $pusher->push(AssetEntity::HOSTING, $host->fresh(['client', 'domain']), $itg, ItGlueAssetMapping::forEntity(AssetEntity::HOSTING));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->data()['data']['attributes']['flexible-asset-type-id'] === 91
            && $r->data()['data']['attributes']['traits'] === ['domain' => 'acme.com', 'ip' => '198.51.100.1', 'bw' => '10 / 100 MB']);

        $this->itglueMapping(AssetEntity::SSL, ['flexible_asset_type_id' => 92, 'flexible_asset_traits' => ['common_name' => 'cn', 'expiry' => 'exp']]);
        $ssl = SslCertificate::create(['client_id' => $client->id, 'common_name' => 'www.acme.com', 'expire_date' => '2031-05-06']);
        $pusher->push(AssetEntity::SSL, $ssl->fresh('client'), $itg, ItGlueAssetMapping::forEntity(AssetEntity::SSL));
        $this->assertSame(5001, $ssl->fresh()->itglue_id);

        // No organisation linked.
        $other = Client::create(['business_name' => 'No ITG']);
        $ssl2 = SslCertificate::create(['client_id' => $other->id, 'common_name' => 'b.example']);
        $this->expectExceptionMessage('not linked to an IT Glue organization');
        $pusher->push(AssetEntity::SSL, $ssl2->fresh('client'), $itg, ItGlueAssetMapping::forEntity(AssetEntity::SSL));
    }

    // ---------------- Jobs / runs / dispatcher ----------------

    public function test_halo_push_job_records_a_sync_run_with_per_item_isolation(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo();
        $this->haloMapping(AssetEntity::INTERNET, ['asset_field_ids' => ['service_id' => 301]]);
        $good = $this->internetService($client);
        $unlinked = InternetService::create(['vocus_service_id' => 'SVC-2', 'client_id' => Client::create(['business_name' => 'No Halo'])->id]);
        $noClient = InternetService::create(['vocus_service_id' => 'SVC-3']);

        $run = app(SyncDispatcher::class)->pushAssets('halo', AssetEntity::INTERNET, [$good->id, $unlinked->id, $noClient->id], null, true, null);

        $run = $run->fresh();
        $this->assertSame('halo', $run->provider);
        $this->assertSame('push-internet', $run->scope);
        $this->assertSame(3, $run->total);
        $this->assertSame(1, $run->done);
        $this->assertSame(2, $run->failed);
        $this->assertSame(SyncRun::PARTIAL, $run->status);

        $this->assertSame(900, $good->fresh()->halo_asset_id);
        $this->assertSame('Client not linked to HaloPSA', $unlinked->fresh()->halo_asset_sync_error);
        $this->assertSame('No client assigned', $noClient->fresh()->halo_asset_sync_error);
    }

    public function test_itglue_push_job_records_a_sync_run_and_skips_unchanged(): void
    {
        $client = $this->linkedClient();
        $this->fakeItGlue();
        $this->itglueMapping(AssetEntity::SSL, ['flexible_asset_type_id' => 92, 'flexible_asset_traits' => ['common_name' => 'cn']]);
        $ssl = SslCertificate::create(['client_id' => $client->id, 'common_name' => 'www.acme.com']);

        $first = app(SyncDispatcher::class)->pushAssets('itglue', AssetEntity::SSL, [$ssl->id], null, false, null)->fresh();
        $this->assertSame('push-ssl', $first->scope);
        $this->assertSame(SyncRun::OK, $first->status);
        $this->assertSame(1, $first->done);

        $this->resetHttp();
        Http::fake();
        $second = app(SyncDispatcher::class)->pushAssets('itglue', AssetEntity::SSL, [$ssl->id], null, false, null)->fresh();
        $this->assertSame(1, $second->skipped);
        Http::assertNothingSent();
    }

    public function test_disabled_or_unmapped_entity_is_skipped_and_never_calls_the_remote(): void
    {
        $client = $this->linkedClient();
        Http::fake();
        $service = $this->internetService($client);
        $dispatcher = app(SyncDispatcher::class);

        // Not configured at all.
        $this->assertStringContainsString('turned off', $dispatcher->assetMappingProblem('halo', AssetEntity::INTERNET));
        $run = $dispatcher->pushAssets('halo', AssetEntity::INTERNET, [$service->id])->fresh();
        $this->assertSame(SyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('turned off', (string) $run->error);

        // Configured but switched off.
        $this->itglueMapping(AssetEntity::INTERNET, ['enabled' => false]);
        $run = $dispatcher->pushAssets('itglue', AssetEntity::INTERNET, [$service->id])->fresh();
        $this->assertSame(SyncRun::SKIPPED, $run->status);

        // Enabled without a type.
        $this->haloMapping(AssetEntity::INTERNET, ['asset_type_id' => null]);
        $this->assertStringContainsString('No asset type', $dispatcher->assetMappingProblem('halo', AssetEntity::INTERNET));

        // The push job itself also refuses a disabled mapping (defence in depth).
        $this->haloMapping(AssetEntity::INTERNET, ['enabled' => false]);
        $run = SyncRun::queue('halo', 'push-internet')->markRunning(1);
        (new PushEntityAssetsJob('halo', AssetEntity::INTERNET, [$service->id], $run->id, true))->handle();
        $this->assertSame(1, $run->fresh()->failed);
        $this->assertNull($service->fresh()->halo_asset_id);

        Http::assertNothingSent();
        $this->assertSame([], $dispatcher->enabledAssetEntities('itglue'));
    }

    public function test_run_all_pushes_only_records_with_clients_and_failure_details_are_listed(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo();
        $this->haloMapping(AssetEntity::SSL, ['asset_type_id' => 57, 'asset_field_ids' => ['common_name' => 501]]);
        $a = SslCertificate::create(['client_id' => $client->id, 'common_name' => 'a.example']);
        SslCertificate::create(['common_name' => 'orphan.example']); // no client: not part of an "all" run

        $run = app(SyncDispatcher::class)->pushAssets('halo', AssetEntity::SSL, null, null, true)->fresh();
        $this->assertSame(1, $run->total);
        $this->assertSame(1, $run->done);
        $this->assertSame(900, $a->fresh()->halo_asset_id);

        // Run items / failures endpoints understand the entity scopes.
        $admin = $this->admin();
        $items = $this->actingAs($admin)->getJson("/admin/sync/runs/{$run->id}/items")->assertOk()->json('items');
        $this->assertSame([$a->id], array_column($items, 'domain_id'));
    }

    public function test_scheduled_pipelines_include_enabled_entities_only(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->haloMapping(AssetEntity::INTERNET);
        $this->haloMapping(AssetEntity::SSL, ['enabled' => false]);
        $this->itglueMapping(AssetEntity::HOSTING);

        app(SyncDispatcher::class)->scheduleHaloPipeline();
        \Illuminate\Support\Facades\Bus::assertChained([
            \App\Jobs\SyncHaloClientsJob::class,
            \App\Jobs\SyncHaloAssetsJob::class,
            \App\Jobs\SyncHaloDomainsJob::class,
            fn (SyncEntityAssetsJob $j) => $j->provider === 'halo' && $j->entity === AssetEntity::INTERNET && $j->clientId === null && $j->force === false,
        ]);

        app(SyncDispatcher::class)->scheduleItGluePipeline();
        \Illuminate\Support\Facades\Bus::assertChained([
            \App\Jobs\SyncItGlueOrganizationsJob::class,
            \App\Jobs\SyncItGlueConfigurationsJob::class,
            fn (SyncEntityAssetsJob $j) => $j->provider === 'itglue' && $j->entity === AssetEntity::HOSTING,
        ]);

        // A disabled domain mapping drops the domain step.
        \Illuminate\Support\Facades\Bus::fake();
        $this->actingAs($this->admin())->putJson(route('admin.settings.asset-mappings.update', ['halo', 'domain']), ['enabled' => false])->assertOk();
        app(SyncDispatcher::class)->scheduleHaloPipeline();
        \Illuminate\Support\Facades\Bus::assertChained([
            \App\Jobs\SyncHaloClientsJob::class,
            \App\Jobs\SyncHaloAssetsJob::class,
            fn (SyncEntityAssetsJob $j) => $j->entity === AssetEntity::INTERNET,
        ]);
    }

    public function test_disabled_domain_mapping_skips_the_manual_domain_runs(): void
    {
        $client = $this->linkedClient();
        Http::fake();
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01']);
        $this->actingAs($this->admin())->putJson(route('admin.settings.asset-mappings.update', ['halo', 'domain']), ['enabled' => false])->assertOk();
        $this->actingAs($this->admin())->putJson(route('admin.settings.asset-mappings.update', ['itglue', 'domain']), ['enabled' => false])->assertOk();

        $halo = app(SyncDispatcher::class)->pushHaloDomains([$domain->id])->fresh();
        $this->assertSame(SyncRun::SKIPPED, $halo->status);
        $itg = app(SyncDispatcher::class)->pushItGlueDomains([$domain->id])->fresh();
        $this->assertSame(SyncRun::SKIPPED, $itg->status);
        Http::assertNothingSent();
    }

    // ---------------- Sync tab endpoints + per-record action ----------------

    public function test_sync_tab_list_and_queue_endpoints(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo();
        $this->haloMapping(AssetEntity::INTERNET, ['asset_field_ids' => ['service_id' => 301]]);
        $service = $this->internetService($client);
        $admin = $this->admin();

        $list = $this->actingAs($admin)->getJson('/admin/sync/assets/halo/internet_service')->assertOk();
        $this->assertSame('SVC-1001', $list->json('items.0.name'));
        $this->assertSame('Acme', $list->json('items.0.client'));
        $this->assertFalse($list->json('items.0.exists_remote'));
        $this->assertNull($list->json('problem'));

        $this->actingAs($admin)->postJson('/admin/sync/assets/halo/internet_service/sync', ['ids' => []])->assertStatus(422);
        $this->actingAs($admin)->postJson('/admin/sync/assets/halo/internet_service/sync', ['ids' => [$service->id]])
            ->assertStatus(202)->assertJsonPath('success', true)->assertJsonPath('run.provider', 'halo');
        $this->assertSame(900, $service->fresh()->halo_asset_id);

        // Off -> refused with the reason, nothing queued.
        $this->actingAs($admin)->postJson('/admin/sync/assets/itglue/internet_service/sync', ['ids' => [$service->id]])->assertStatus(422)->assertJsonPath('success', false);
        // Domains and unknown targets are not served here; sync.run is required.
        $this->actingAs($admin)->getJson('/admin/sync/assets/halo/domain')->assertNotFound();
        $this->actingAs($this->admin(true, false))->getJson('/admin/sync/assets/halo/internet_service')->assertForbidden();
    }

    public function test_per_record_sync_action_and_cards_on_pages(): void
    {
        $client = $this->linkedClient();
        $this->fakeHalo();
        $this->haloMapping(AssetEntity::INTERNET, ['asset_field_ids' => ['service_id' => 301]]);
        $service = $this->internetService($client);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.services.internet.show', $service))
            ->assertOk()->assertSee('asset-sync-card', false)->assertSee('Asset records')->assertSee('Sync to Halo');

        $this->actingAs($admin)->post(route('admin.assets.sync', ['halo', 'internet_service', $service->id]))->assertRedirect();
        $this->assertSame(900, $service->fresh()->halo_asset_id);

        // IT Glue is off for this entity: refused with a message, nothing sent.
        $this->actingAs($admin)->post(route('admin.assets.sync', ['itglue', 'internet_service', $service->id]))->assertRedirect()->assertSessionHas('error');
        // sync.run required.
        $this->actingAs($this->admin(true, false))->post(route('admin.assets.sync', ['halo', 'internet_service', $service->id]))->assertForbidden();
    }

    public function test_sync_tabs_show_a_card_per_enabled_entity(): void
    {
        $this->haloMapping(AssetEntity::SSL);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.settings.area', 'halopsa'))
            ->assertOk()->assertSee("openAssetSyncModal('halo', 'ssl_certificate')", false)
            ->assertDontSee("openAssetSyncModal('halo', 'internet_service')", false);
        $this->actingAs($admin)->get(route('admin.settings.area', 'itglue'))
            ->assertOk()->assertDontSee("openAssetSyncModal('itglue'", false);
    }
}
