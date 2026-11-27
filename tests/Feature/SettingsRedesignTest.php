<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\HaloClient;
use App\Models\ItglueOrganization;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingsAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Settings redesign (docs/review/18-settings-redesign.md): home + area pages, partial saves, option endpoints
 * and mapping-table CRUD. Written without vendor/ in the build environment, so not run there:
 *   php artisan test --filter=SettingsRedesignTest
 */
class SettingsRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key']);
        Setting::put('itglue', ['api_key' => 'itg-key', 'flexible_asset_type_id' => 77, 'flexible_asset_type_name' => 'Domains']);
    }

    private function admin(bool $administrator = true): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        if ($administrator) {
            $user->assignRole('Administrator');
        }
        $user->givePermissionTo($permission);

        return $user;
    }

    private function fakeHalo(): void
    {
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/tickettype*' => Http::response([['id' => 3, 'name' => 'Incident'], ['id' => 1, 'name' => 'Request'], ['id' => 0, 'name' => 'Broken']]),
            'halo.example.com/api/status*' => Http::response([['id' => 5, 'name' => 'Open'], ['id' => 9, 'name' => 'Closed']]),
            'halo.example.com/api/priority*' => Http::response([['id' => 11, 'priorityid' => 2, 'name' => 'High']]),
            'halo.example.com/api/team*' => Http::response([['id' => 4, 'name' => 'Service Desk']]),
            'halo.example.com/api/sla*' => Http::response([['id' => 6, 'name' => 'Gold']]),
            'halo.example.com/api/site*' => Http::response(['record_count' => 1, 'sites' => [['id' => 8, 'name' => 'Head Office', 'client_name' => 'Acme']]]),
            'halo.example.com/api/clientcontract*' => Http::response(['record_count' => 3, 'contracts' => [
                ['id' => 1, 'contracttype' => 12, 'contracttype_name' => 'Managed Services'],
                ['id' => 2, 'contracttype' => 12, 'contracttype_name' => 'Managed Services'],
                ['id' => 3, 'contracttype' => 15, 'contracttype_name' => 'Project'],
            ]]),
            'halo.example.com/api/lookup*' => Http::response([]),
            'halo.example.com/api/assettype*' => Http::response(['assettypes' => [['id' => 7, 'name' => 'Domain', 'assetgroup_name' => 'Services']]]),
        ]);
    }

    // ------------------------------------------------------------------ pages

    public function test_settings_home_lists_every_area_for_admin(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
        foreach (SettingsAreas::all() as $slug => $area) {
            $response->assertSee($area['title']);
            $response->assertSee(SettingsAreas::href($slug), false);
        }
        $response->assertSee(route('admin.https'), false);
        // Billing has one home (HaloPSA -> Billing tab), not a tile of its own.
        $response->assertDontSee('/admin/settings/halopsa#billing', false);
        $this->assertArrayNotHasKey('halo-billing', SettingsAreas::all());
        $this->assertArrayNotHasKey('billing', SettingsAreas::categories());
        $this->assertSame(1, substr_count(json_encode(array_keys(SettingsAreas::all()['halopsa']['tabs'])), 'billing'));
    }

    public function test_old_billing_route_redirects_to_the_halopsa_billing_tab(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings/halo-billing')->assertRedirect('/admin/settings/halopsa?tab=billing');
        $this->actingAs($this->admin())->get('/admin/settings/halopsa?tab=billing')->assertOk()
            ->assertSee('Agreements')->assertSee('Recurring invoice lines')->assertSee('Subscriptions');
    }

    public function test_every_area_page_renders_all_its_tabs_for_admin(): void
    {
        $admin = $this->admin();

        foreach (SettingsAreas::all() as $slug => $area) {
            if ($area['tabs'] === []) {
                continue; // tile links elsewhere (HTTPS page)
            }
            $response = $this->actingAs($admin)->get(route('admin.settings.area', $slug))->assertOk()->assertSee($area['title']);
            foreach ($area['tabs'] as $tab => $label) {
                $response->assertSee('id="panel-' . $tab . '"', false);
            }
        }
    }

    public function test_area_deep_link_and_unknown_area(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings/halopsa?tab=tickets')->assertOk()->assertSee('data-initial="tickets"', false);
        $this->actingAs($admin)->get('/admin/settings/halopsa?tab=nonsense')->assertOk()->assertSee('data-initial="connection"', false);
        $this->actingAs($admin)->get('/admin/settings/nope')->assertNotFound();
        $this->actingAs($admin)->get('/admin/settings?section=halo')->assertRedirect(route('admin.settings.area', 'halopsa'));
    }

    public function test_settings_pages_are_forbidden_for_non_admins(): void
    {
        $user = $this->admin(false);

        $this->actingAs($user)->get(route('admin.settings'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.settings.area', 'halopsa'))->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.settings.options', 'halo.statuses'))->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.settings.mappings.index', 'halo.statuses'))->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.settings.mappings.store', 'halo.statuses'), [])->assertForbidden();
        $this->actingAs($user)->putJson(route('admin.settings.mappings.update', ['halo.statuses', 0]), [])->assertForbidden();
        $this->actingAs($user)->deleteJson(route('admin.settings.mappings.destroy', ['halo.statuses', 0]))->assertForbidden();
    }

    // ------------------------------------------------------------------ partial saves

    public function test_saving_one_tab_does_not_wipe_other_keys(): void
    {
        $admin = $this->admin();
        Setting::put('halo', [
            'base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key',
            'asset_type_id' => 7, 'asset_field_ids' => ['domain' => 201, 'expiry' => 202],
            'ticket_type_mappings' => [['service_category' => 'Domain', 'ticket_type' => 'Support/Issue', 'halo_ticket_type_id' => 3, 'halo_ticket_type_name' => 'Incident']],
            'billing' => ['sync_subscriptions' => '1', 'strategy' => 'contract', 'contract_ref' => 'MS-*'],
        ]);
        Setting::put('itglue', ['api_key' => 'itg-key', 'flexible_asset_type_id' => 77, 'flexible_asset_traits' => ['domain' => 'dom', 'dns' => 'dns']]);
        Setting::put('vocus', ['access_key' => 'AK1', 'realms' => ['a.example.com'], 'scopes' => ['RESELLER-CONNECT']]);
        Setting::put('smtp', ['host' => 'smtp.example.com', 'port' => 587, 'from' => 'a@example.com']);

        // Halo Billing tab posts only halo.billing.*
        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'halo' => ['billing' => ['sync_lines' => '0', 'contract_type_id' => '12']],
            '_return' => '/admin/settings/halopsa#billing',
        ])->assertRedirect('/admin/settings/halopsa#billing')->assertSessionHasNoErrors();

        $halo = Setting::get('halo');
        $this->assertSame('secret-api-key', $halo['api_key']);
        $this->assertSame(7, $halo['asset_type_id']);
        $this->assertSame(['domain' => 201, 'expiry' => 202], $halo['asset_field_ids']);
        $this->assertSame('Incident', $halo['ticket_type_mappings'][0]['halo_ticket_type_name']);
        $this->assertSame('1', $halo['billing']['sync_subscriptions'], 'sibling billing key kept');
        $this->assertSame('MS-*', $halo['billing']['contract_ref']);
        $this->assertSame('0', $halo['billing']['sync_lines']);
        $this->assertSame('12', (string) $halo['billing']['contract_type_id']);

        // IT Glue connection tab posts base_url + api_key only
        $this->actingAs($admin)->post(route('admin.settings.update'), ['itglue' => ['base_url' => 'https://api.itglue.com', 'api_key' => '********']])->assertSessionHasNoErrors();
        $itg = Setting::get('itglue');
        $this->assertSame('itg-key', $itg['api_key']);
        $this->assertSame(77, $itg['flexible_asset_type_id']);
        $this->assertSame(['domain' => 'dom', 'dns' => 'dns'], $itg['flexible_asset_traits']);

        // Vocus sandbox tab must not touch realms/scopes/access key
        $this->actingAs($admin)->post(route('admin.settings.update'), ['vocus' => ['environment' => 'sandbox', 'sandbox_pin_ip' => '1']])->assertSessionHasNoErrors();
        $vocus = Setting::get('vocus');
        $this->assertSame('sandbox', $vocus['environment']);
        $this->assertSame('AK1', $vocus['access_key']);
        $this->assertSame(['a.example.com'], $vocus['realms']);

        // Only one SMTP field posted: the rest stays
        $this->actingAs($admin)->post(route('admin.settings.update'), ['smtp' => ['from_name' => 'Acme']])->assertSessionHasNoErrors();
        $this->assertSame('smtp.example.com', Setting::get('smtp')['host']);
        $this->assertSame('Acme', Setting::get('smtp')['from_name']);
    }

    public function test_return_path_is_restricted_to_settings_urls(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from('/admin/settings')->post(route('admin.settings.update'), ['mfa' => ['issuer' => 'X'], '_return' => 'https://evil.example.com/'])
            ->assertRedirect('/admin/settings');
    }

    // ------------------------------------------------------------------ option endpoints

    public function test_option_endpoints_normalise_halo_lists(): void
    {
        $this->fakeHalo();
        $admin = $this->admin();

        $types = $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.ticket-types'))->assertOk()->json();
        $this->assertSame([['id' => '3', 'label' => 'Incident'], ['id' => '1', 'label' => 'Request']], array_map(fn ($o) => ['id' => $o['id'], 'label' => $o['label']], $types['options']));
        $this->assertSame(2, $types['total'], 'rows without a positive id are dropped');

        $this->assertSame(['Closed', 'Open'], array_column($this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.statuses'))->json('options'), 'label'), 'sorted by label');
        $this->assertSame('2', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.priorities'))->json('options.0.id'), 'ticket priority id is priorityid');
        $this->assertSame('Service Desk', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.teams'))->json('options.0.label'));
        $this->assertSame('Gold', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.slas'))->json('options.0.label'));
        $this->assertSame('Head Office', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.sites'))->json('options.0.label'));
        $this->assertSame('Domain', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.asset-types'))->json('options.0.label'));
    }

    public function test_contract_types_fall_back_to_distinct_client_contract_types_when_the_lookup_is_empty(): void
    {
        $this->fakeHalo();
        $json = $this->actingAs($this->admin())->getJson(route('admin.settings.options', 'halo.contract-types'))->assertOk()->json();

        $this->assertSame([['id' => '12', 'label' => 'Managed Services'], ['id' => '15', 'label' => 'Project']], array_map(fn ($o) => ['id' => $o['id'], 'label' => $o['label']], $json['options']));
    }

    public function test_options_are_cached_for_ten_minutes_and_refresh_bypasses_the_cache(): void
    {
        $this->fakeHalo();
        $admin = $this->admin();
        $count = fn () => Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/api/team'))->count();

        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.teams'))->assertOk()->assertJsonPath('cached', false);
        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.teams'))->assertOk()->assertJsonPath('cached', true);
        $this->assertSame(1, $count());

        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.teams') . '?refresh=1')->assertOk();
        $this->assertSame(2, $count());
    }

    public function test_options_filter_server_side_and_keep_a_saved_value_selectable(): void
    {
        $this->fakeHalo();
        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.ticket-types') . '?q=req')
            ->assertOk()->assertJsonCount(1, 'options')->assertJsonPath('options.0.label', 'Request');

        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.contract-types') . '?include=99')
            ->assertOk()->assertJsonPath('options.0.id', '99');
    }

    public function test_option_endpoint_errors_are_422_without_secrets_and_unknown_source_is_404(): void
    {
        Http::fake(['halo.example.com/*' => Http::response('boom secret-api-key', 500)]);
        $admin = $this->admin();

        $res = $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.teams'))->assertStatus(422);
        $this->assertArrayHasKey('error', $res->json());
        $this->assertStringNotContainsString('secret-api-key', json_encode($res->json()));

        $this->actingAs($admin)->getJson('/admin/settings/options/nope.source')->assertNotFound();

        Setting::put('halo', []);
        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.statuses'))->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_local_mirror_options_search_server_side(): void
    {
        $admin = $this->admin();
        Client::create(['business_name' => 'Rockhound Group']);
        Client::create(['business_name' => 'Acme']);
        HaloClient::create(['halo_id' => 501, 'name' => 'Rockhound Group Pty', 'inactive' => false]);
        HaloClient::create(['halo_id' => 502, 'name' => 'Zeta', 'inactive' => false]);
        ItglueOrganization::create(['itglue_id' => 9001, 'name' => 'Rockhound IT']);

        $this->assertSame(['Rockhound Group'], array_column($this->actingAs($admin)->getJson(route('admin.settings.options', 'dd.clients') . '?q=roc')->json('options'), 'label'));
        $this->assertSame(['Rockhound Group Pty'], array_column($this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.clients') . '?q=roc')->json('options'), 'label'));
        $this->assertSame(['Rockhound IT'], array_column($this->actingAs($admin)->getJson(route('admin.settings.options', 'itglue.organisations') . '?q=roc')->json('options'), 'label'));
    }

    // ------------------------------------------------------------------ mapping CRUD (same setting keys as the old forms)

    public function test_ticket_type_mapping_crud_persists_the_same_setting_shape(): void
    {
        $admin = $this->admin();
        $url = route('admin.settings.mappings.index', 'halo.ticket-types');

        $this->actingAs($admin)->postJson($url, [
            'service_category' => 'Domain', 'halo_ticket_type' => 3, 'halo_ticket_type_label' => 'Incident', 'ticket_class' => 'Support/Issue',
        ])->assertCreated()->assertJsonPath('row.id', '0')->assertJsonPath('row.cells.halo_ticket_type', 'Incident');

        $this->assertSame([
            ['service_category' => 'Domain', 'ticket_type' => 'Support/Issue', 'halo_ticket_type_id' => 3, 'halo_ticket_type_name' => 'Incident'],
        ], Setting::get('halo')['ticket_type_mappings']);
        $this->assertSame('secret-api-key', Setting::get('halo')['api_key'], 'other halo keys are kept');

        // duplicate category + class is rejected
        $this->actingAs($admin)->postJson($url, ['service_category' => 'domain', 'halo_ticket_type' => 1, 'ticket_class' => 'Support/Issue'])->assertStatus(422)->assertJsonValidationErrors('service_category');
        // invalid class / id
        $this->actingAs($admin)->postJson($url, ['service_category' => 'SSL', 'halo_ticket_type' => 0, 'ticket_class' => 'Nope'])->assertStatus(422)->assertJsonValidationErrors(['halo_ticket_type', 'ticket_class']);

        $this->actingAs($admin)->postJson($url, ['service_category' => 'SSL', 'halo_ticket_type' => 1, 'halo_ticket_type_label' => 'Request', 'ticket_class' => 'Service Request'])->assertCreated();
        $this->actingAs($admin)->putJson($url . '/1', ['service_category' => 'SSL', 'halo_ticket_type' => 3, 'halo_ticket_type_label' => 'Incident', 'ticket_class' => 'Service Request'])->assertOk();
        $this->assertSame(3, Setting::get('halo')['ticket_type_mappings'][1]['halo_ticket_type_id']);

        $list = $this->actingAs($admin)->getJson($url . '?per_page=5')->assertOk()->json();
        $this->assertSame(2, $list['total']);
        $this->assertSame('SSL', $list['rows'][1]['cells']['service_category']);

        $this->actingAs($admin)->deleteJson($url . '/0')->assertOk();
        $remaining = Setting::get('halo')['ticket_type_mappings'];
        $this->assertCount(1, $remaining);
        $this->assertSame('SSL', $remaining[0]['service_category']);
        $this->actingAs($admin)->deleteJson($url . '/5')->assertNotFound();
    }

    public function test_status_mapping_crud_persists_the_same_setting_shape(): void
    {
        $admin = $this->admin();
        $url = route('admin.settings.mappings.index', 'halo.statuses');

        $this->actingAs($admin)->postJson($url, ['domaindash_status' => 'Open', 'halo_status' => 5, 'halo_status_label' => 'Open (Halo)'])->assertCreated();
        $this->assertSame([['domaindash_status' => 'Open', 'halo_status_id' => 5, 'halo_status_name' => 'Open (Halo)']], Setting::get('halo')['status_mappings']);

        $this->actingAs($admin)->postJson($url, ['domaindash_status' => 'Closed', 'halo_status' => 5])->assertStatus(422)->assertJsonValidationErrors('halo_status');
        $this->actingAs($admin)->postJson($url, ['halo_status' => 9])->assertStatus(422)->assertJsonValidationErrors('domaindash_status');

        $this->actingAs($admin)->putJson($url . '/0', ['domaindash_status' => 'In Progress', 'halo_status' => 5, 'halo_status_label' => 'Open (Halo)'])->assertOk();
        $this->assertSame('In Progress', Setting::get('halo')['status_mappings'][0]['domaindash_status']);

        $this->actingAs($admin)->deleteJson($url . '/0')->assertOk();
        $this->assertSame([], Setting::get('halo')['status_mappings']);
    }

    public function test_mapping_list_paginates_ten_per_page(): void
    {
        $admin = $this->admin();
        $rows = [];
        for ($i = 1; $i <= 23; $i++) {
            $rows[] = ['domaindash_status' => 'S' . $i, 'halo_status_id' => $i, 'halo_status_name' => 'H' . $i];
        }
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'status_mappings' => $rows]);

        $page3 = $this->actingAs($admin)->getJson(route('admin.settings.mappings.index', 'halo.statuses') . '?page=3')->assertOk()->json();
        $this->assertSame(23, $page3['total']);
        $this->assertSame(10, $page3['per_page']);
        $this->assertCount(3, $page3['rows']);
        $this->assertSame('S21', $page3['rows'][0]['cells']['domaindash_status']);
    }

    public function test_client_mapping_sets_and_clears_the_halo_reference(): void
    {
        $admin = $this->admin();
        $a = Client::create(['business_name' => 'Acme']);
        $b = Client::create(['business_name' => 'Beta']);
        HaloClient::create(['halo_id' => 501, 'name' => 'Acme Halo', 'abn' => '123', 'inactive' => false]);
        $url = route('admin.settings.mappings.index', 'halo.clients');

        $this->actingAs($admin)->postJson($url, ['dash_client' => $a->id, 'remote' => '501'])->assertCreated()->assertJsonPath('row.cells.remote', 'Acme Halo');
        $this->assertSame('501', $a->fresh()->halopsa_reference);

        // the same Halo client cannot map to two DomainDash clients; unknown mirror ids are rejected
        $this->actingAs($admin)->postJson($url, ['dash_client' => $b->id, 'remote' => '501'])->assertStatus(422)->assertJsonValidationErrors('remote');
        $this->actingAs($admin)->postJson($url, ['dash_client' => $b->id, 'remote' => '999'])->assertStatus(422)->assertJsonValidationErrors('remote');

        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('rows.0.values.dash_client.id', (string) $a->id);

        $this->actingAs($admin)->deleteJson($url . '/' . $a->id)->assertOk();
        $this->assertNull($a->fresh()->halopsa_reference);
    }

    public function test_itglue_organisation_mapping_sets_and_clears_the_org_id(): void
    {
        $admin = $this->admin();
        $a = Client::create(['business_name' => 'Acme']);
        ItglueOrganization::create(['itglue_id' => 9001, 'name' => 'Acme IT']);
        $url = route('admin.settings.mappings.index', 'itglue.organisations');

        $this->actingAs($admin)->postJson($url, ['dash_client' => $a->id, 'remote' => '9001'])->assertCreated();
        $this->assertSame('9001', (string) $a->fresh()->itglue_org_id);
        $this->actingAs($admin)->postJson($url, ['dash_client' => $a->id, 'remote' => '1'])->assertStatus(422);

        $this->actingAs($admin)->deleteJson($url . '/' . $a->id)->assertOk();
        $this->assertNull($a->fresh()->itglue_org_id);
    }

    public function test_vocus_realm_mapping_validates_and_keeps_other_vocus_keys(): void
    {
        $admin = $this->admin();
        Setting::put('vocus', ['access_key' => 'AK1', 'realms' => ['a.example.com']]);
        $url = route('admin.settings.mappings.index', 'vocus.realms');

        $this->actingAs($admin)->postJson($url, ['realm' => 'b.example.com.au'])->assertCreated();
        $this->assertSame(['a.example.com', 'b.example.com.au'], Setting::get('vocus')['realms']);
        $this->assertSame('AK1', Setting::get('vocus')['access_key']);

        $this->actingAs($admin)->postJson($url, ['realm' => 'not a host'])->assertStatus(422)->assertJsonValidationErrors('realm');
        $this->actingAs($admin)->putJson($url . '/0', ['realm' => 'c.example.com'])->assertOk();
        $this->actingAs($admin)->deleteJson($url . '/1')->assertOk();
        $this->assertSame(['c.example.com'], Setting::get('vocus')['realms']);
    }

    public function test_unknown_mapping_key_is_404(): void
    {
        $this->actingAs($this->admin())->getJson('/admin/settings/mappings/nope.nothing')->assertNotFound();
    }
}
