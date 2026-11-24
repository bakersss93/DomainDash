<?php

namespace Tests\Feature;

require_once __DIR__ . '/../Support/soap_polyfill.php';

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\Setting;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Vocus\VocusOptions;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SoapFault;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeSoap;
use Tests\TestCase;

/**
 * Vocus NBN ordering UX: realms, scopes, plans, price snapshot, client view, LOC ID qualify and the
 * Create payload. Not runnable in the build environment (no vendor/, no ext-soap); run on the server:
 *   php artisan test --filter=VocusOrderingUxTest
 */
class VocusOrderingUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        Setting::put('vocus', ['access_key' => 'AK-SECRET-1', 'cert_path' => 'vocus/client.p12', 'environment' => 'sandbox', 'realms' => ['wba.example.com.au', 'wba.samplewsm.com.au']]);
    }

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('Administrator');
        $user->givePermissionTo($permission);

        return $user;
    }

    private function fakeClient(array $queue): FakeSoap
    {
        $soap = new FakeSoap($queue);
        $client = new VocusWsmClient(fn () => $soap, null, fn () => 'SID');
        $client->setRateLimiter(new VocusRateLimiter(1000, 10, 15, fn () => null, fn () => 1000.0, 'ux_rate_' . uniqid()));
        app()->instance(VocusWsmClient::class, $client);

        return $soap;
    }

    private function xmlOf(FakeSoap $soap, int $call = 0): string
    {
        $payload = $soap->calls[$call]['args'][0];

        return (string) ($payload->enc_value ?? $payload->data);
    }

    private function plan(array $o = []): VocusPlan
    {
        // firstOrCreate: orderData() asks for the default plan again after a test already created it (plan_id is unique).
        $attrs = $o + ['plan_id' => 'RC-100-20', 'name' => 'Fast 100', 'speed_tier' => '100/20', 'cost_price' => '50.00', 'sell_price' => '79.95', 'active' => true, 'scopes' => null];

        return VocusPlan::firstOrCreate(['plan_id' => $attrs['plan_id']], $attrs);
    }

    private function orderData(array $o = []): array
    {
        return array_merge([
            'vocus_plan_id' => $this->plan()->id, 'scope' => 'RESELLER-CONNECT', 'customer_name' => 'Yogesh Shinde', 'phone' => '0433111222',
            'directory_id' => 'LOC001122334455', 'location_reference' => '3000', 'service_type' => 'FTTC', 'copper_pair_id' => 'CPI300010022999',
            'realm' => 'wba.example.com.au', 'username' => 'yshinde', 'password' => 'Abc-123.x', 'service_level' => 'STANDARD',
        ], $o);
    }

    private function ok(): object
    {
        return (object) ['TransactionID' => 'EF99182AC', 'ResponseType' => 'ASYNC'];
    }

    // ------------------------------------------------------------ realms

    public function test_realms_are_validated_normalised_stored_and_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(route('admin.settings.vocus.realms'), ['realms' => ['Wba.example.com.au', 'second.example.net']])
            ->assertOk()->assertJsonPath('realms', ['wba.example.com.au', 'second.example.net']);
        $this->assertSame(['wba.example.com.au', 'second.example.net'], Setting::get('vocus')['realms']);
        $this->assertSame('AK-SECRET-1', Setting::get('vocus')['access_key'], 'other Vocus settings are preserved');
        $this->assertTrue(AuditLog::where('action', 'vocus.realms.update')->exists());

        foreach ([['not a host'], ['localhost'], ['10.0.0.1'], ['a.com', 'A.com'], [str_repeat('a', 60) . '.com.au'], ['bad_underscore.com']] as $bad) {
            $this->actingAs($admin)->putJson(route('admin.settings.vocus.realms'), ['realms' => $bad])->assertStatus(422);
        }
        $this->assertSame(['wba.example.com.au', 'second.example.net'], Setting::get('vocus')['realms']);
    }

    public function test_settings_endpoints_are_administrator_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('admin.settings.vocus.realms'), ['realms' => ['a.example.com']])->assertForbidden();
        $this->actingAs($user)->putJson(route('admin.settings.vocus.scopes'), ['scopes' => ['RESELLER-CONNECT']])->assertForbidden();
        $this->actingAs($user)->postJson(route('admin.settings.vocus.plans.store'), [])->assertForbidden();
        $this->assertSame(['wba.example.com.au', 'wba.samplewsm.com.au'], Setting::get('vocus')['realms']);
    }

    // ------------------------------------------------------------ username / password

    public function test_username_is_composed_as_username_at_realm_and_blank_username_is_omitted(): void
    {
        $this->assertSame('yshinde@wba.example.com.au', VocusOptions::composeUsername('yshinde', 'WBA.Example.com.au'));
        $this->assertNull(VocusOptions::composeUsername('  ', 'wba.example.com.au'));

        $soap = $this->fakeClient([$this->ok(), $this->ok()]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData())->assertSessionHasNoErrors();
        $xml = $this->xmlOf($soap, 0);
        $this->assertStringContainsString('<std:Param id="Username">yshinde@wba.example.com.au</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Realm">wba.example.com.au</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Password">Abc-123.x</std:Param>', $xml);

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData(['username' => '']))->assertSessionHasNoErrors();
        $xml = $this->xmlOf($soap, 1);
        $this->assertStringNotContainsString('id="Username"', $xml);
        $this->assertStringContainsString('<std:Param id="Realm">wba.example.com.au</std:Param>', $xml);
    }

    public function test_username_password_and_realm_rules(): void
    {
        $soap = $this->fakeClient([$this->ok()]); // only the one valid order below reaches Vocus
        $admin = $this->admin();
        $post = fn (array $o) => $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($o));

        foreach (['a', str_repeat('a', 23), 'bad name', 'bad-name', 'user@realm'] as $u) {
            $post(['username' => $u])->assertSessionHasErrors('username');
        }
        foreach (['p', 'bad pass!', 'bad_underscore', 'quote"', str_repeat('a', 31)] as $pw) {
            $post(['password' => $pw])->assertSessionHasErrors('password');
        }
        $post(['password' => ''])->assertSessionHasErrors('password'); // Password is 1..1 on RESELLER-CONNECT
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData(['username' => 'ab', 'password' => str_repeat('a', 30)]))->assertSessionHasNoErrors('username');
        $post(['realm' => 'unknown.example.com'])->assertSessionHasErrors('realm');
        $post(['realm' => ''])->assertSessionHasErrors('realm');
        // Every rejected request was stopped by validation; only the one valid order was placed.
        $this->assertDatabaseCount('internet_services', 1);
        $this->assertCount(1, $soap->calls);
    }

    public function test_password_is_never_flashed_back_or_stored(): void
    {
        $this->fakeClient([new SoapFault('env:Server', 'No', null, (object) ['FaultResponse' => (object) ['TransactionID' => 'TX1', 'ErrorCode' => 'WSM-4220']])]);

        $this->actingAs($this->admin())->post(route('admin.services.internet.order.store'), $this->orderData())->assertSessionHas('error');

        $this->assertArrayNotHasKey('password', (array) session()->get('_old_input', []));
        $this->assertDatabaseCount('internet_services', 0);
    }

    public function test_contact_name_phone_and_postcode_rules(): void
    {
        $this->fakeClient([]);
        $admin = $this->admin();
        $post = fn (array $o) => $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($o));

        $post(['customer_name' => 'Bob'])->assertSessionHasErrors('customer_name');
        $post(['customer_name' => 'Madonna'])->assertSessionHasErrors('customer_name'); // needs first and last name
        $post(['customer_name' => str_repeat('a', 20) . ' ' . str_repeat('b', 20)])->assertSessionHasErrors('customer_name');
        $post(['customer_name' => 'Jo-Anne Smith'])->assertSessionHasErrors('customer_name');
        $post(['phone' => '12345'])->assertSessionHasErrors('phone');
        $post(['location_reference' => 'VIC'])->assertSessionHasErrors('location_reference');
        $this->assertDatabaseCount('internet_services', 0);
    }

    // ------------------------------------------------------------ scopes

    public function test_scope_settings_filter_the_order_form_and_server_side_validation(): void
    {
        $admin = $this->admin();
        $this->plan();

        $this->actingAs($admin)->putJson(route('admin.settings.vocus.scopes'), ['scopes' => []])->assertStatus(422);
        $this->actingAs($admin)->putJson(route('admin.settings.vocus.scopes'), ['scopes' => ['MADE-UP']])->assertStatus(422);
        $this->actingAs($admin)->putJson(route('admin.settings.vocus.scopes'), ['scopes' => ['RESELLER-CONNECT']])->assertOk();

        $this->assertSame(['RESELLER-CONNECT'], VocusOptions::enabledScopes());
        $page = $this->actingAs($admin)->get(route('admin.services.internet.order'))->assertOk();
        $page->assertSee('name="scope" value="RESELLER-CONNECT"', false)->assertDontSee('value="NETWORK-CONNECT"', false); // the page script may still mention the scope name; it must not be offered

        $this->fakeClient([]);
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData(['scope' => 'NETWORK-CONNECT']))->assertSessionHasErrors('scope');

        $this->actingAs($admin)->putJson(route('admin.settings.vocus.scopes'), ['scopes' => ['RESELLER-CONNECT', 'NETWORK-CONNECT']])->assertOk();
        $this->actingAs($admin)->get(route('admin.services.internet.order'))->assertSee('<select id="f-scope" name="scope"', false);
        $this->assertTrue(AuditLog::where('action', 'vocus.scopes.update')->exists());
    }

    // ------------------------------------------------------------ plans

    public function test_plan_crud_validation_and_audit(): void
    {
        $admin = $this->admin();
        $body = ['plan_id' => 'RC-DEFAULT-MAP', 'name' => 'Fast 100', 'scopes' => ['RESELLER-CONNECT'], 'speed_tier' => '100/20 Mbps', 'cost_price' => '55', 'sell_price' => '79.9', 'active' => true];

        $id = $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), $body)->assertCreated()
            ->assertJsonPath('plan.cost_price', '55.00')->assertJsonPath('plan.sell_price', '79.90')->json('plan.id');
        $this->assertDatabaseHas('vocus_plans', ['plan_id' => 'RC-DEFAULT-MAP', 'active' => 1]);

        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), $body)->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => 'X Y']))->assertJsonValidationErrors('plan_id');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => 'OK-2', 'cost_price' => '1.234']))->assertJsonValidationErrors('cost_price');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => 'OK-3', 'sell_price' => '-5']))->assertJsonValidationErrors('sell_price');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => 'OK-4', 'scopes' => ['NOPE']]))->assertJsonValidationErrors('scopes.0');

        $this->actingAs($admin)->putJson(route('admin.settings.vocus.plans.update', $id), array_merge($body, ['sell_price' => '89.95', 'active' => false]))
            ->assertOk()->assertJsonPath('plan.sell_price', '89.95')->assertJsonPath('plan.active', false);
        $this->actingAs($admin)->getJson(route('admin.settings.vocus.plans'))->assertOk()->assertJsonCount(1, 'plans');

        $this->actingAs($admin)->deleteJson(route('admin.settings.vocus.plans.destroy', $id))->assertOk();
        $this->assertDatabaseCount('vocus_plans', 0);
        foreach (['vocus.plan.create', 'vocus.plan.update', 'vocus.plan.delete'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), $action);
        }
    }

    public function test_plan_endpoints_reject_non_administrators(): void
    {
        $plan = $this->plan();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('admin.settings.vocus.plans.update', $plan), [])->assertForbidden();
        $this->actingAs($user)->deleteJson(route('admin.settings.vocus.plans.destroy', $plan))->assertForbidden();
        $this->assertDatabaseCount('vocus_plans', 1);
    }

    public function test_order_form_lists_only_active_plans_and_tags_scopes(): void
    {
        $admin = $this->admin();
        $this->plan(['plan_id' => 'ACTIVE-1', 'name' => 'Active Plan', 'scopes' => ['NETWORK-CONNECT']]);
        $this->plan(['plan_id' => 'OFF-1', 'name' => 'Retired Plan', 'active' => false]);

        $this->actingAs($admin)->get(route('admin.services.internet.order'))->assertOk()
            ->assertSee('Active Plan')->assertSee('$79.95/mo', false)->assertSee('data-scopes="NETWORK-CONNECT"', false)->assertDontSee('Retired Plan');

        $this->fakeClient([]);
        $retired = VocusPlan::where('plan_id', 'OFF-1')->first();
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData(['vocus_plan_id' => $retired->id]))->assertSessionHasErrors('vocus_plan_id');
        $nc = VocusPlan::where('plan_id', 'ACTIVE-1')->first();
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData(['vocus_plan_id' => $nc->id, 'scope' => 'RESELLER-CONNECT']))->assertSessionHasErrors('vocus_plan_id');
    }

    // ------------------------------------------------------------ pricing snapshot and client view

    public function test_order_snapshots_plan_and_prices_and_admin_sees_margin(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $admin = $this->admin();
        $plan = $this->plan();

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData(['vocus_plan_id' => $plan->id]))->assertSessionHasNoErrors();
        $plan->update(['cost_price' => '60.00', 'sell_price' => '99.00']); // later price change must not rewrite history

        $service = InternetService::firstOrFail();
        $this->assertSame($plan->id, $service->vocus_plan_id);
        $this->assertSame('RC-100-20', $service->plan_id);
        $this->assertSame('50.00', $service->cost_price);
        $this->assertSame('79.95', $service->sell_price);
        $this->assertSame('29.95', $service->margin);
        $this->assertStringContainsString('<PlanID>RC-100-20</PlanID>', $this->xmlOf($soap));

        $this->actingAs($admin)->get(route('admin.services.internet', ['status' => '']))->assertOk()->assertSee('$50.00', false)->assertSee('$79.95', false)->assertSee('$29.95', false);
        $this->actingAs($admin)->get(route('admin.services.internet.show', $service))->assertOk()->assertSee('Margin')->assertSee('$29.95', false);
    }

    public function test_client_sees_the_sell_price_but_never_cost_or_margin_and_only_their_own_services(): void
    {
        $mine = Client::create(['business_name' => 'My Co']);
        $other = Client::create(['business_name' => 'Other Co']);
        $user = User::factory()->create();
        $user->clients()->attach($mine->id);

        InternetService::create(['vocus_service_id' => 'DD-A', 'client_id' => $mine->id, 'address_long' => '1 MINE ST MELBOURNE VIC', 'service_type' => 'FTTC',
            'plan_id' => 'RC-100-20', 'cost_price' => '41.37', 'sell_price' => '79.95', 'service_status' => 'ACTIVE']);
        InternetService::create(['vocus_service_id' => 'DD-B', 'client_id' => $other->id, 'address_long' => '2 THEIRS RD PERTH WA', 'service_type' => 'FTTP',
            'plan_id' => 'RC-1000', 'cost_price' => '88.88', 'sell_price' => '149.00', 'service_status' => 'ACTIVE']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('1 MINE ST MELBOURNE VIC')->assertSee('$79.95 / month', false)
            ->assertDontSee('41.37')->assertDontSee('Margin')->assertDontSee('2 THEIRS RD')->assertDontSee('149.00')->assertDontSee('88.88')->assertDontSee('DD-A');
    }

    // ------------------------------------------------------------ order payload and form shape

    public function test_create_request_carries_the_catalogue_parameters(): void
    {
        $soap = $this->fakeClient([$this->ok()]);

        $this->actingAs($this->admin())->post(route('admin.services.internet.order.store'), $this->orderData(['avc_id' => 'AVC1', 'cvc_id' => 'CVC1', 'order_type' => 'TRANSFER', 'reference' => 'CUST-0042', 'auth_type' => 'PPPoE', 'ip_assignment' => 'STATIC']))
            ->assertSessionHasNoErrors();

        $xml = $this->xmlOf($soap);
        $this->assertStringContainsString('<ProductID>FIBRE</ProductID>', $xml);
        $this->assertStringContainsString('<Scope>RESELLER-CONNECT</Scope>', $xml);
        $this->assertMatchesRegularExpression('/<std:Param id="ServiceID">DD-001122334455-[A-Z2-9]{4}<\/std:Param>/', $xml);
        foreach ([
            'CustomerName' => 'Yogesh Shinde', 'Phone' => '0433111222', 'DirectoryID' => 'LOC001122334455', 'LocationReference' => '3000',
            'ServiceType' => 'FTTC', 'ServiceLevel' => 'STANDARD', 'CopperPairID' => 'CPI300010022999',
            // FIBRE catalogue 4.1.1: OrderType NEW, Reference, AuthType PPPoE | IPoE and IPAssignment CGNAT | STATIC | DYNAMIC.
            'OrderType' => 'NEW', 'Reference' => 'CUST-0042', 'AuthType' => 'PPPoE', 'IPAssignment' => 'STATIC',
        ] as $id => $value) {
            $this->assertStringContainsString('<std:Param id="' . $id . '">' . $value . '</std:Param>', $xml, $id);
        }
        // A NEW order never carries the transfer-only params; there is no param called IPType / CustomerReference.
        foreach (['AVCID', 'CVCID', 'CADate', 'CentralSplitter', 'IPType', 'CustomerReference', 'AuthenticationType'] as $absent) {
            $this->assertStringNotContainsString($absent, $xml, $absent);
        }
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,30}$/', InternetService::firstOrFail()->vocus_service_id);
    }

    public function test_generated_service_ids_are_valid_and_unique(): void
    {
        $ids = array_map(fn () => VocusOptions::generateServiceId('LOC001122334455'), range(1, 50));
        $this->assertCount(50, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^DD-001122334455-[A-Z2-9]{4}$/', $id);
        }
        $this->assertMatchesRegularExpression('/^DD-[A-Z2-9]{8}-[A-Z2-9]{4}$/', VocusOptions::generateServiceId('FFF6A2EBBC31021'));
    }

    public function test_transfer_form_shows_avc_and_order_type_and_new_order_form_does_not(): void
    {
        $admin = $this->admin();
        $this->plan();
        $q = ['directory_id' => 'LOC001122334455', 'service_type' => 'FTTC', 'location_reference' => '3000', 'address_long' => '1 EXAMPLE ST MELBOURNE VIC'];

        $order = $this->actingAs($admin)->get(route('admin.services.internet.order', $q))->assertOk();
        $order->assertDontSee('AVC ID')->assertDontSee('Transfer type')->assertDontSee('name="vocus_service_id"', false)->assertDontSee('<option value="FTTP"', false);
        $order->assertSee('name="auth_type"', false)->assertSee('name="ip_assignment"', false)->assertSee('name="reference"', false);
        $order->assertSee('name="service_type" value="FTTC"', false)->assertSee('readonly', false);

        $this->actingAs($admin)->get(route('admin.services.internet.transfer', $q + ['avc_id' => 'AVC000340636851']))->assertOk()
            ->assertSee('AVC ID')->assertSee('Transfer type')->assertSee('OUTSTANDING')->assertSee('CVC (AggCVC) ID')->assertSee('AVC000340636851');
    }

    public function test_qualify_page_has_loc_search_and_no_layer_two_section(): void
    {
        $this->actingAs($this->admin())->get(route('admin.services.internet.qualify'))->assertOk()
            ->assertSee('NBN LOC ID')->assertDontSee('AggCVC')->assertDontSee('Layer 2 / Network-Connect (NCL2)');
    }

    // ------------------------------------------------------------ LOC ID qualify

    private function qualifyResponse(): object
    {
        $list = [];
        foreach (['Result' => 'PASS', 'ServiceType' => 'FTTC', 'ServiceClass' => '32', 'Zone' => 'Urban'] as $id => $v) {
            $list[] = (object) ['id' => $id, '_' => $v];
        }

        return (object) ['TransactionID' => 'Q1', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => $list]];
    }

    public function test_qualify_by_loc_id_skips_address_lookup_and_uses_it_as_directory_id(): void
    {
        $soap = $this->fakeClient([$this->qualifyResponse()]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.qualify.check'), ['loc_id' => ' loc001122334455 '])
            ->assertOk()->assertJsonPath('result', 'PASS')->assertJsonPath('service_type', 'FTTC')->assertJsonPath('directory_id', 'LOC001122334455');

        $this->assertCount(1, $soap->calls);
        $xml = $this->xmlOf($soap);
        $this->assertStringContainsString('<ProductID>FIBRE</ProductID>', $xml);
        $this->assertStringContainsString('<Scope>QUALIFY</Scope>', $xml);
        $this->assertStringContainsString('<std:Param id="DirectoryID">LOC001122334455</std:Param>', $xml);
        $this->assertStringNotContainsString('AVCID', $xml);
        $this->assertStringNotContainsString('CVCID', $xml);
    }

    public function test_invalid_loc_ids_are_rejected_before_any_vocus_call(): void
    {
        $soap = $this->fakeClient([]);
        $admin = $this->admin();

        foreach (['LOC12345', 'LOC0011223344556', 'XYZ001122334455', 'LOC00112233445A', 'LOC 001122334455'] as $bad) {
            $this->actingAs($admin)->postJson(route('admin.services.internet.qualify.check'), ['loc_id' => $bad])->assertStatus(422)->assertJsonValidationErrors('loc_id');
        }
        $this->actingAs($admin)->postJson(route('admin.services.internet.qualify.check'), [])->assertStatus(422);
        $this->assertSame([], $soap->calls);
        $this->assertSame('LOC001122334455', VocusOptions::normalizeLocId(' loc001122334455'));
        $this->assertNull(VocusOptions::normalizeLocId('LOC1'));
    }

    public function test_address_lookup_returns_postcode_suburb_and_state_for_the_order_form(): void
    {
        $this->fakeClient([(object) ['TransactionID' => 'T1', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => (object) ['id' => 'FibreAddressRecord', '_' => '<BroadbandAddressRecord><DirectoryID>LOC000000000001</DirectoryID><Carrier>NBN</Carrier><AddressLong>4 CUMMINS DR SUMMERTOWN SA</AddressLong></BroadbandAddressRecord>']]]]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.qualify.lookup'), ['address' => '4 cummins dr, summertown sa 5141'])
            ->assertOk()->assertJsonPath('postcode', '5141')->assertJsonPath('state', 'SA')->assertJsonPath('suburb', 'SUMMERTOWN');
    }
}
