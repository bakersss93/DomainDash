<?php

namespace Tests\Feature;

require_once __DIR__ . '/../Support/soap_polyfill.php';

use App\Jobs\SyncVocusServicesJob;
use App\Models\InternetService;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\VocusPlan;
use App\Models\User;
use App\Services\Sync\SyncDispatcher;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use SoapFault;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeSoap;
use Tests\TestCase;

/**
 * Vocus WSM audit (docs/review/13-vocus-wsm-audit.md): transfer payload, queued sync,
 * account-level abort, address validation, Layer 2 actions, fault display, username length.
 * Not runnable in the build environment (no vendor/, no ext-soap); run on the server:
 *   php artisan test --filter=VocusInternetTest
 */
class VocusInternetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        \App\Casts\EncryptedSettingValue::$decryptFailures = [];
        Setting::put('vocus', ['access_key' => 'AK-SECRET-1', 'cert_path' => 'vocus/client.p12', 'environment' => 'sandbox', 'realms' => ['wba.samplewsm.com.au']]);
    }

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('Administrator');
        $user->givePermissionTo($permission);

        return $user;
    }

    /** Bind a client that talks to a scripted FakeSoap (settings are loaded from the Setting row, as in production). */
    private function fakeClient(array $queue): FakeSoap
    {
        $soap = new FakeSoap($queue);
        $client = new VocusWsmClient(fn () => $soap, null, fn () => 'SID');
        $client->setRateLimiter(new VocusRateLimiter(1000, 10, 15, fn () => null, fn () => 1000.0, 'feat_rate_' . uniqid()));
        app()->instance(VocusWsmClient::class, $client);

        return $soap;
    }

    private function fault(string $code, string $message = 'Some message'): SoapFault
    {
        return new SoapFault('env:Server', $message, null, (object) ['FaultResponse' => (object) ['TransactionID' => 'TX1', 'ErrorCode' => $code]]);
    }

    private function serviceResponse(array $extra = []): object
    {
        $params = array_merge(['ServiceStatus' => 'ACTIVE', 'PlanID' => 'RC-DEFAULT-MAP', 'ServiceScope' => 'RESELLER-CONNECT'], $extra);
        $list = [];
        foreach ($params as $id => $value) {
            $list[] = (object) ['id' => $id, '_' => $value];
        }

        return (object) ['TransactionID' => 'T1', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => $list]];
    }

    private function xmlOf(FakeSoap $soap, int $call = 0): string
    {
        $payload = $soap->calls[$call]['args'][0];

        return (string) ($payload->enc_value ?? $payload->data);
    }

    private function plan(string $planId = 'NCL2-DSCP-MAP'): VocusPlan
    {
        return VocusPlan::firstOrCreate(['plan_id' => $planId], ['name' => 'Test plan', 'cost_price' => '50.00', 'sell_price' => '79.95', 'active' => true]);
    }

    private function transferData(array $override = []): array
    {
        return array_merge([
            'vocus_plan_id' => $this->plan()->id, 'scope' => 'RESELLER-CONNECT',
            'customer_name' => 'Yogesh Shinde', 'phone' => '0433111222', 'directory_id' => 'LOC000340636851',
            'location_reference' => '3000', 'service_type' => 'FTTC', 'copper_pair_id' => 'CPI300010022999',
            'ca_date' => now()->subDays(3)->format('Y-m-d'), 'order_type' => 'TRANSFER', 'realm' => 'wba.samplewsm.com.au', 'service_level' => 'STANDARD',
            'avc_id' => 'AVC000340636851', 'cvc_id' => 'AggCVC000000999',
        ], $override);
    }

    // ------------------------------------------------------------------ VW-01 / VW-12

    public function test_transfer_order_sends_order_type_transfer_with_the_documented_parameters(): void
    {
        $soap = $this->fakeClient([(object) ['TransactionID' => 'AG99182AC', 'ResponseType' => 'ASYNC']]);

        $this->actingAs($this->admin())->post(route('admin.services.internet.transfer.store'), $this->transferData())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $xml = $this->xmlOf($soap);
        $this->assertStringContainsString('<ProductID>FIBRE</ProductID>', $xml);
        $this->assertStringContainsString('<PlanID>NCL2-DSCP-MAP</PlanID>', $xml);
        $this->assertStringContainsString('<Scope>RESELLER-CONNECT</Scope>', $xml);
        $this->assertStringContainsString('<std:Param id="OrderType">TRANSFER</std:Param>', $xml);
        $this->assertStringNotContainsString('CHURN', $xml);
        $this->assertMatchesRegularExpression('/<std:Param id="ServiceID">DD-000340636851-[A-Z2-9]{4}<\/std:Param>/', $xml);
        $this->assertStringNotContainsString('id="Username"', $xml); // blank username: Vocus applies its default
        foreach ([
            'CustomerName' => 'Yogesh Shinde', 'Phone' => '0433111222', 'CVCID' => 'AggCVC000000999',
            'LocationReference' => '3000', 'DirectoryID' => 'LOC000340636851', 'AVCID' => 'AVC000340636851',
            'Realm' => 'wba.samplewsm.com.au', 'ServiceLevel' => 'STANDARD', 'ServiceType' => 'FTTC',
            'CopperPairID' => 'CPI300010022999', 'CADate' => now()->subDays(3)->format('Ymd'),
        ] as $id => $value) {
            $this->assertStringContainsString('<std:Param id="' . $id . '">' . $value . '</std:Param>', $xml, $id);
        }

        $row = InternetService::where('order_type', 'TRANSFER')->firstOrFail();
        $this->assertSame('NCL2-DSCP-MAP', $row->plan_id);
        $this->assertSame('TRANSFER', $row->order_type);
        $this->assertSame('AG99182AC', $row->last_transaction_id);
        $this->assertSame('QUEUED', $row->last_transaction_state);
        $this->assertSame('AggCVC000000999', $row->cvc_id);
    }

    public function test_layer_two_transfer_requires_the_cvc_id_and_sends_nothing_without_it(): void
    {
        $soap = $this->fakeClient([]);

        $this->actingAs($this->admin())
            ->post(route('admin.services.internet.transfer.store'), $this->transferData(['scope' => 'NETWORK-CONNECT', 'cvc_id' => '']))
            ->assertSessionHasErrors('cvc_id');

        $this->assertSame([], $soap->calls);
        $this->assertDatabaseCount('internet_services', 0);
    }

    public function test_ncl2_plan_on_reseller_connect_scope_also_requires_the_cvc_id(): void
    {
        $this->fakeClient([]);

        $this->actingAs($this->admin())
            ->post(route('admin.services.internet.transfer.store'), $this->transferData(['cvc_id' => '']))
            ->assertSessionHasErrors('cvc_id');
    }

    public function test_new_ncl2_order_sends_order_type_new_and_cvc_but_never_avc_or_cadate(): void
    {
        $soap = $this->fakeClient([(object) ['TransactionID' => 'EF1', 'ResponseType' => 'ASYNC']]);
        $data = $this->transferData(['ctag' => '0']);
        unset($data['ca_date'], $data['order_type']);

        $this->actingAs($this->admin())->post(route('admin.services.internet.order.store'), $data)->assertSessionHasNoErrors();

        $xml = $this->xmlOf($soap);
        $this->assertStringContainsString('<std:Param id="OrderType">NEW</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="CVCID">AggCVC000000999</std:Param>', $xml); // compulsory for NCL2 (FIBRE 4.1.2.2)
        $this->assertStringContainsString('<std:Param id="CTAG">0</std:Param>', $xml);
        $this->assertStringNotContainsString('AVCID', $xml);
        $this->assertStringNotContainsString('CADate', $xml);
        foreach (['Username', 'Password', 'AuthType', 'IPAssignment'] as $layer3Only) {
            $this->assertStringNotContainsString($layer3Only, $xml, $layer3Only);
        }
        $this->assertSame('NEW', InternetService::firstOrFail()->order_type);

        $missing = $this->transferData(['cvc_id' => '']);
        unset($missing['ca_date'], $missing['order_type']);
        $this->actingAs($this->admin())->post(route('admin.services.internet.order.store'), $missing)->assertSessionHasErrors('cvc_id');
    }

    public function test_transfer_needs_the_avc_id_but_connect_outstanding_does_not(): void
    {
        $soap = $this->fakeClient([(object) ['TransactionID' => 'EF2', 'ResponseType' => 'ASYNC']]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.internet.transfer.store'), $this->transferData(['avc_id' => '']))->assertSessionHasErrors('avc_id');
        $this->assertSame([], $soap->calls);

        $this->actingAs($admin)->post(route('admin.services.internet.transfer.store'), $this->transferData(['avc_id' => '', 'order_type' => 'OUTSTANDING']))->assertSessionHasNoErrors();
        $xml = $this->xmlOf($soap);
        $this->assertStringContainsString('<std:Param id="OrderType">OUTSTANDING</std:Param>', $xml);
        $this->assertStringNotContainsString('AVCID', $xml);
        $this->assertSame('OUTSTANDING', InternetService::firstOrFail()->order_type);
    }

    public function test_ca_date_must_be_today_or_earlier_and_not_older_than_45_days(): void
    {
        $this->fakeClient([]);
        $admin = $this->admin();
        foreach ([now()->subDays(46), now()->addDay()] as $bad) {
            $this->actingAs($admin)->post(route('admin.services.internet.transfer.store'), $this->transferData(['ca_date' => $bad->format('Y-m-d')]))->assertSessionHasErrors('ca_date');
        }
        $this->assertDatabaseCount('internet_services', 0);
    }

    public function test_rejected_order_leaves_no_local_record_and_reports_through_the_error_flash(): void
    {
        $this->fakeClient([$this->fault('WSM-4220')]);

        $this->actingAs($this->admin())->post(route('admin.services.internet.transfer.store'), $this->transferData())
            ->assertSessionHas('error');

        $this->assertDatabaseCount('internet_services', 0);
    }

    // ------------------------------------------------------------------ VW-04

    public function test_blank_access_key_is_refused_with_a_clear_message_before_any_soap_call(): void
    {
        Setting::put('vocus', ['access_key' => '', 'cert_path' => 'vocus/client.p12']);
        $soap = $this->fakeClient([]);

        $resp = $this->actingAs($this->admin())->post(route('admin.services.internet.transfer.store'), $this->transferData());

        $resp->assertSessionHas('error', fn ($m) => str_contains($m, 'Vocus Access Key is not configured'));
        $this->assertSame([], $soap->calls);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.sync'))
            ->assertStatus(422)->assertJsonPath('queued', false);
    }

    // ------------------------------------------------------------------ VW-03

    public function test_sync_endpoint_queues_a_vocus_services_run_instead_of_calling_vocus(): void
    {
        Queue::fake();
        $soap = $this->fakeClient([]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.sync'))
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('runs.0.provider', 'vocus')
            ->assertJsonPath('runs.0.scope', 'services');

        Queue::assertPushed(SyncVocusServicesJob::class);
        $this->assertSame([], $soap->calls, 'the request itself must not call Vocus');
    }

    public function test_import_validates_ids_and_creates_rows_only_inside_the_job(): void
    {
        Queue::fake();
        $this->fakeClient([]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.import'), ['service_ids' => "GOOD-1,bad!id,GOOD_2"])
            ->assertStatus(202);

        $this->assertDatabaseCount('internet_services', 0);
        Queue::assertPushed(SyncVocusServicesJob::class, fn ($job) => $job->vocusIds === ['GOOD-1', 'GOOD_2'] && $job->create === true);
    }

    public function test_account_level_error_aborts_the_whole_run_with_a_clear_message(): void
    {
        foreach (['S1', 'S2', 'S3'] as $id) {
            InternetService::create(['vocus_service_id' => $id]);
        }
        $soap = $this->fakeClient([$this->fault('WSM-4020'), $this->serviceResponse(), $this->serviceResponse()]);
        $run = SyncRun::queue('vocus', 'services');

        (new SyncVocusServicesJob(null, false, $run->id))->handle();

        $run->refresh();
        $this->assertCount(1, $soap->calls, 'no further calls after an account-level error');
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('WSM-4020', $run->error);
        $this->assertStringContainsString('blocked', $run->error);
        $this->assertStringContainsString('2 further service(s) were not contacted', $run->error);
        $this->assertSame(0, $run->failed, 'the abort is one run-level error, not one failure per row');
    }

    public function test_account_level_error_after_some_progress_leaves_a_partial_run(): void
    {
        foreach (['S1', 'S2', 'S3'] as $id) {
            InternetService::create(['vocus_service_id' => $id]);
        }
        $soap = $this->fakeClient([$this->serviceResponse(), $this->fault('WSM-4050'), $this->serviceResponse()]);
        $run = SyncRun::queue('vocus', 'services');

        (new SyncVocusServicesJob(null, false, $run->id))->handle();

        $run->refresh();
        $this->assertCount(2, $soap->calls);
        $this->assertSame(SyncRun::PARTIAL, $run->status);
        $this->assertSame(1, $run->done);
        $this->assertStringContainsString('WSM-4050', $run->error);
    }

    public function test_item_level_errors_do_not_stop_the_run(): void
    {
        foreach (['S1', 'S2', 'S3'] as $id) {
            InternetService::create(['vocus_service_id' => $id]);
        }
        $soap = $this->fakeClient([$this->serviceResponse(['ServiceStatus' => 'SUSPEND']), $this->fault('WSM-4220'), $this->serviceResponse()]);
        $run = SyncRun::queue('vocus', 'services');

        (new SyncVocusServicesJob(null, false, $run->id))->handle();

        $run->refresh();
        $this->assertCount(3, $soap->calls);
        $this->assertSame(SyncRun::PARTIAL, $run->status);
        $this->assertSame(2, $run->done);
        $this->assertSame(1, $run->failed);
        $this->assertSame('SUSPEND', InternetService::where('vocus_service_id', 'S1')->value('service_status'));

        $failures = SyncVocusServicesJob::failuresFor($run->id);
        $this->assertSame('S2', $failures[0]['name']);
        $this->assertStringContainsString('WSM-4220', $failures[0]['error']);
    }

    public function test_run_is_skipped_with_the_configuration_message_when_the_key_is_missing(): void
    {
        Setting::put('vocus', ['access_key' => '', 'cert_path' => 'vocus/client.p12']);
        $soap = $this->fakeClient([]);
        $run = SyncRun::queue('vocus', 'services');

        (new SyncVocusServicesJob(null, false, $run->id))->handle();

        $run->refresh();
        $this->assertSame(SyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('Access Key is not configured', $run->error);
        $this->assertSame([], $soap->calls);
    }

    public function test_import_creates_the_row_only_after_vocus_returned_the_service(): void
    {
        $this->fakeClient([$this->serviceResponse(['Username' => str_repeat('u', 40) . '@wba.example.com.au']), $this->fault('WSM-4220')]);
        $run = SyncRun::queue('vocus', 'services');

        (new SyncVocusServicesJob(['OK-1', 'BAD-1'], true, $run->id))->handle();

        $this->assertNotNull(InternetService::where('vocus_service_id', 'OK-1')->first());
        $this->assertNull(InternetService::where('vocus_service_id', 'BAD-1')->first(), 'a failed import must not leave a blank row');
    }

    public function test_dispatcher_returns_the_active_run_instead_of_queueing_a_duplicate(): void
    {
        Queue::fake();

        $first = app(SyncDispatcher::class)->refreshVocus();
        $second = app(SyncDispatcher::class)->refreshVocus();

        $this->assertSame($first->id, $second->id);
        Queue::assertPushed(SyncVocusServicesJob::class, 1);
    }

    // ------------------------------------------------------------------ VW-10

    public function test_username_is_stored_in_its_own_column_and_realm_only_receives_the_realm(): void
    {
        $username = str_repeat('a', 16) . '@' . str_repeat('r', 60) . '.example.com.au'; // 92 characters; the realm part alone is 75 (> 64 column)
        $service = InternetService::create(['vocus_service_id' => 'S-LONG']);
        $service->applyLiveParams(['Username' => $username, 'ServiceStatus' => 'ACTIVE'])->save();

        $service->refresh();
        $this->assertSame($username, $service->username);
        $this->assertLessThanOrEqual(64, strlen($service->realm));
        $this->assertStringNotContainsString('@', $service->realm);
    }

    public function test_realm_longer_than_the_documented_limit_is_rejected(): void
    {
        $this->fakeClient([]);

        $this->actingAs($this->admin())
            ->post(route('admin.services.internet.transfer.store'), $this->transferData(['realm' => str_repeat('r', 62)]))
            ->assertSessionHasErrors('realm');
    }

    // ------------------------------------------------------------------ VW-07 / VW-08

    public function test_address_validation_follows_the_xsd_and_the_standard(): void
    {
        $this->fakeClient([]);
        $admin = $this->admin();

        $bad = [
            ['street_name' => str_repeat('A', 26)],
            ['street_name' => 'MAIN', 'suburb' => str_repeat('B', 41)],
            ['street_name' => 'MAIN', 'street_type' => 'LN'],
            ['street_name' => 'MAIN', 'state' => 'XX'],
            ['street_name' => 'MAIN', 'unit_number' => 'ABC'],
            ['street_name' => 'MAIN', 'street_number' => '12-14'],
            ['street_name' => 'MAIN', 'unit_type' => 'ZZ'],
        ];
        foreach ($bad as $payload) {
            $this->actingAs($admin)->postJson(route('admin.services.internet.qualify.lookup'), $payload)->assertStatus(422);
        }
    }

    public function test_alphanumeric_unit_and_street_numbers_and_full_street_type_names_are_accepted(): void
    {
        $soap = $this->fakeClient([$this->emptyAddressResponse(), $this->emptyAddressResponse(), $this->emptyAddressResponse(), $this->emptyAddressResponse()]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.qualify.lookup'), [
            'unit_number' => '2A', 'unit_type' => 'UNIT', 'street_number' => '12B', 'street_name' => 'Main',
            'street_type' => 'Avenue', 'suburb' => 'Hobart', 'state' => 'tas', 'postcode' => '7000',
        ])->assertOk();

        $xml = $this->xmlOf($soap, 1); // 2nd attempt carries the unit / number details
        $this->assertStringContainsString('<std:Param id="Main.StreetType">AV</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.Unit1stNumber">2</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.Unit1stSuffix">A</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.Unit1stType">U</std:Param>', $xml, 'DIR 5.2 names it Unit1stType');
        $this->assertStringNotContainsString('Main.AddressType', $xml);
        $this->assertStringContainsString('<std:Param id="Main.Street1stNumberSuffix">B</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.State">TAS</std:Param>', $xml);
    }

    private function emptyAddressResponse(): object
    {
        return (object) ['TransactionID' => 'T0', 'ResponseType' => 'SYNC'];
    }

    private function addressResponse(array $records): object
    {
        $params = array_map(fn ($r) => (object) ['id' => 'FibreAddressRecord', '_' => '<BroadbandAddressRecord><DirectoryID>' . $r[0]
            . '</DirectoryID><Carrier>NBN</Carrier><AddressLong>' . $r[1] . '</AddressLong></BroadbandAddressRecord>'], $records);

        return (object) ['TransactionID' => 'T1', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => count($params) === 1 ? $params[0] : $params]];
    }

    public function test_reported_address_free_text_and_manual_form_send_the_same_documented_lookup(): void
    {
        $admin = $this->admin();

        foreach ([
            ['address' => '4 cummins dr, summertown sa 5141'],
            ['street_number' => '4', 'street_name' => 'Cummins', 'street_type' => 'DR', 'suburb' => 'Summertown', 'state' => 'SA', 'postcode' => '5141'],
        ] as $payload) {
            $soap = $this->fakeClient([$this->addressResponse([['LOC000000000001', '4 CUMMINS DR SUMMERTOWN SA 5141']])]);

            $this->actingAs($admin)->postJson(route('admin.services.internet.qualify.lookup'), $payload)
                ->assertOk()
                ->assertJsonCount(1, 'addresses')
                ->assertJsonPath('addresses.0.directory_id', 'LOC000000000001');

            $xml = $this->xmlOf($soap);
            foreach (['Main.StreetName">CUMMINS', 'Main.StreetType">DR', 'Main.Suburb">SUMMERTOWN', 'Main.State">SA', 'Main.Postcode">5141', 'SearchMode">DEFAULT'] as $needle) {
                $this->assertStringContainsString($needle, $xml);
            }
            $this->assertStringContainsString('<PlanID>BROADBAND</PlanID>', $xml);
        }
    }

    public function test_no_results_response_explains_what_was_searched(): void
    {
        $this->fakeClient(array_fill(0, 4, $this->emptyAddressResponse()));

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.qualify.lookup'), ['address' => '4 cummins dr, summertown sa 5141'])
            ->assertOk()
            ->assertJsonCount(0, 'addresses')
            ->assertJsonPath('searched', '4 CUMMINS DR, SUMMERTOWN SA 5141')
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'no addresses') && str_contains($m, '4 search variant'))->etc());
    }

    public function test_lookup_fault_is_shown_as_the_actual_reason(): void
    {
        $this->fakeClient([$this->fault('WSM-4050')]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.qualify.lookup'), ['address' => '4 cummins dr, summertown sa 5141'])
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json->where('error', fn ($e) => str_contains($e, 'WSM-4050') && str_contains($e, 'Insufficient access'))->etc());
    }

    // ------------------------------------------------------------------ VW-11

    public function test_layer_two_services_do_not_support_suspend_or_resume(): void
    {
        $this->assertFalse((new InternetService(['service_scope' => 'NETWORK-CONNECT']))->supportsSuspendResume());
        $this->assertFalse((new InternetService(['service_scope' => 'RESELLER-CONNECT', 'plan_id' => 'NCL2-DSCP-MAP']))->supportsSuspendResume());
        $this->assertTrue((new InternetService(['service_scope' => 'RESELLER-CONNECT', 'plan_id' => 'RC-DEFAULT-MAP']))->supportsSuspendResume());
    }

    public function test_set_status_refuses_suspend_for_layer_two_and_while_pending(): void
    {
        $soap = $this->fakeClient([]);
        $admin = $this->admin();
        $l2 = InternetService::create(['vocus_service_id' => 'L2', 'service_scope' => 'NETWORK-CONNECT', 'service_status' => 'ACTIVE']);
        $pending = InternetService::create(['vocus_service_id' => 'P1', 'service_scope' => 'RESELLER-CONNECT', 'service_status' => 'ACTIVE', 'last_transaction_state' => 'QUEUED']);

        $this->actingAs($admin)->post(route('admin.services.internet.setStatus', $l2), ['status' => 'SUSPEND'])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.services.internet.setStatus', $pending), ['status' => 'INACTIVE'])->assertSessionHas('error');

        $this->assertSame([], $soap->calls);
    }

    public function test_show_page_hides_suspend_for_layer_two_services(): void
    {
        $this->fakeClient([$this->serviceResponse(['ServiceScope' => 'NETWORK-CONNECT'])]);
        $service = InternetService::create(['vocus_service_id' => 'L2', 'service_scope' => 'NETWORK-CONNECT', 'service_status' => 'ACTIVE']);

        $this->actingAs($this->admin())->get(route('admin.services.internet.show', $service))
            ->assertOk()
            ->assertDontSee('Suspend Service')
            ->assertSee('Layer 3 / Reseller-Connect');
    }

    // ------------------------------------------------------------------ VW-05 / VW-06

    public function test_failed_transaction_fault_is_persisted_and_shown_on_the_service_page(): void
    {
        $soap = $this->fakeClient([(object) ['TransactionID' => 'TX', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => [
            (object) ['id' => 'TransactionState', '_' => 'Failed'],
            (object) ['id' => 'faultcode', '_' => 'DIR-0000'],
            (object) ['id' => 'faultmessage', '_' => 'General error'],
        ]]]]);
        $admin = $this->admin();
        $service = InternetService::create(['vocus_service_id' => 'F1', 'last_transaction_id' => 'TX', 'last_transaction_state' => 'QUEUED', 'service_status' => 'INACTIVE']);

        $this->actingAs($admin)->postJson(route('admin.services.internet.poll', $service))
            ->assertOk()
            ->assertJsonPath('transaction_state', 'FAILED')
            ->assertJsonPath('fault_code', 'DIR-0000');

        $service->refresh();
        $this->assertSame('DIR-0000', $service->last_fault_code);
        $this->assertSame('General error', $service->last_fault_message);

        $this->fakeClient([$this->serviceResponse()]);
        $this->actingAs($admin)->get(route('admin.services.internet.show', $service))
            ->assertOk()->assertSee('DIR-0000')->assertSee('General error');
    }

    public function test_poll_stops_with_the_error_on_account_level_failures(): void
    {
        $this->fakeClient([$this->fault('WSM-4020')]);
        $service = InternetService::create(['vocus_service_id' => 'F2', 'last_transaction_id' => 'TX', 'last_transaction_state' => 'QUEUED']);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.poll', $service))
            ->assertStatus(422)
            ->assertJsonPath('stop', true)
            ->assertJsonPath('error_code', 'WSM-4020');
    }

    public function test_poll_respects_the_cooldown(): void
    {
        $soap = $this->fakeClient([]);
        $service = InternetService::create([
            'vocus_service_id' => 'F3', 'last_transaction_id' => 'TX', 'last_transaction_state' => 'QUEUED', 'last_polled_at' => now()->subSeconds(30),
        ]);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.poll', $service))
            ->assertOk()->assertJsonPath('cooldown', true);

        $this->assertSame([], $soap->calls);
    }

    public function test_expired_transaction_clears_the_pending_state(): void
    {
        $this->fakeClient([$this->fault('TCAS-4310')]);
        $service = InternetService::create(['vocus_service_id' => 'F4', 'last_transaction_id' => 'TX', 'last_transaction_state' => 'QUEUED']);

        $this->actingAs($this->admin())->postJson(route('admin.services.internet.poll', $service))
            ->assertStatus(422)->assertJsonPath('stop', true);

        $this->assertFalse($service->refresh()->isPending());
        $this->assertSame('TCAS-4310', $service->last_fault_code);
    }

    public function test_show_page_does_not_fail_when_the_live_fetch_errors_and_skips_it_while_pending(): void
    {
        $admin = $this->admin();

        // Live fetch error: page renders with stored data and a notice.
        $this->fakeClient([$this->fault('WSM-4050')]);
        $service = InternetService::create(['vocus_service_id' => 'V1', 'customer_name' => 'Stored Customer', 'service_status' => 'ACTIVE']);
        $this->actingAs($admin)->get(route('admin.services.internet.show', $service))
            ->assertOk()->assertSee('Live details unavailable')->assertSee('Insufficient access')->assertSee('Stored Customer');

        // Pending (just ordered): no Vocus call at all.
        $soap = $this->fakeClient([]);
        $pending = InternetService::create(['vocus_service_id' => 'V2', 'last_transaction_id' => 'TX', 'last_transaction_state' => 'QUEUED']);
        $this->actingAs($admin)->get(route('admin.services.internet.show', $pending))
            ->assertOk()->assertSee('Awaiting Vocus provisioning');
        $this->assertSame([], $soap->calls);
    }

    // ------------------------------------------------------------------ secrets

    public function test_access_key_is_not_rendered_in_internet_pages(): void
    {
        $this->fakeClient([]);
        $admin = $this->admin();
        $service = InternetService::create(['vocus_service_id' => 'X1', 'last_transaction_state' => 'QUEUED', 'last_transaction_id' => 'T']);

        foreach ([
            route('admin.services.internet'),
            route('admin.services.internet.qualify'),
            route('admin.services.internet.order'),
            route('admin.services.internet.transfer'),
            route('admin.services.internet.show', $service),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertDontSee('AK-SECRET-1');
        }
    }
}
