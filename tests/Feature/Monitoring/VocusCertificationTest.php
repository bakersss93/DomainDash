<?php

namespace Tests\Feature\Monitoring;

require_once __DIR__ . '/../../Support/soap_polyfill.php';

use App\Models\AuditLog;
use App\Models\IntegrationRequest;
use App\Models\InternetService;
use App\Services\Monitoring\VocusJourneys;
use App\Services\Monitoring\VocusTransactionBackfill;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SoapFault;
use Tests\Support\FakeSoap;
use Tests\Support\MonitoringHelpers;
use Tests\TestCase;

class VocusCertificationTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['domaindash.mfa.enforce' => true, 'domaindash.mfa.required_roles' => ['Administrator']]);
    }

    private const SECRET = 'AK-never-store-this-9f3a';

    private function client(FakeSoap $soap, string $env = 'sandbox'): VocusWsmClient
    {
        Cache::flush();
        $client = new VocusWsmClient(fn () => $soap, ['access_key' => self::SECRET, 'alias_key' => 'ALIAS-never-store', 'cert_path' => 'c.p12', 'environment' => $env], fn () => 'SID1');
        $now = 1000.0;
        $client->setRateLimiter(new VocusRateLimiter(50, 10, 15, function (float $s) use (&$now) { $now += $s; }, function () use (&$now) { return $now; }, 'cert_rate_' . uniqid()));

        return $client;
    }

    private function resp(string $tx, string $type, array $params = []): object
    {
        return (object) ['TransactionID' => $tx, 'ResponseType' => $type, 'Parameters' => (object) ['Param' => array_map(
            fn ($id, $v) => (object) ['id' => $id, '_' => $v], array_keys($params), array_values($params)
        )]];
    }

    private function addressRecord(string $loc): string
    {
        return "<![CDATA[<FibreAddressRecord><DirectoryID>{$loc}</DirectoryID><Carrier>NBN</Carrier><AddressLong>1 EXAMPLE ST TESTVILLE</AddressLong></FibreAddressRecord>]]>";
    }

    /** lookup -> SQ -> order -> two polls -> service get, all against the sandbox. */
    private function runJourney(string $loc = 'LOC000000000001', string $service = 'SVC-TEST-0001', string $orderTx = '90001'): VocusWsmClient
    {
        $soap = new FakeSoap([
            $this->resp('80001', 'SYNC', ['FibreAddressRecord' => $this->addressRecord($loc)]),
            $this->resp('80002', 'SYNC', ['ServiceQualified' => 'YES']),
            $this->resp($orderTx, 'ASYNC'),
            $this->resp('90002', 'SYNC', ['TransactionState' => 'PROCESSING']),
            $this->resp('90003', 'SYNC', ['TransactionState' => 'SUCCESS', 'ServiceID' => $service]),
            $this->resp('90004', 'SYNC', ['ServiceStatus' => 'ACTIVE', 'ServiceID' => $service, 'AVCID' => 'AVC000000001']),
        ]);
        $c = $this->client($soap);
        $c->lookupAddress(['Main.StreetName' => 'EXAMPLE', 'Main.StreetType' => 'ST', 'Main.Suburb' => 'TESTVILLE', 'Main.State' => 'NSW']);
        $c->qualifyAddress($loc);
        $c->orderService('PLAN-TEST', 'RESELLER-CONNECT', ['OrderType' => 'NEW', 'ServiceID' => $service, 'DirectoryID' => $loc]);
        $c->pollTransaction($orderTx);
        $c->pollTransaction($orderTx);
        $c->getService($service);

        return $c;
    }

    public function test_every_vocus_call_is_recorded_at_errors_only_level_with_structured_fields(): void
    {
        $this->monitoring(['outbound_level' => 'errors']);
        $this->runJourney();

        $rows = IntegrationRequest::where('provider', 'vocus')->orderBy('id')->get();
        $this->assertCount(6, $rows);

        $order = $rows[2];
        $this->assertSame('sandbox', $order->environment);
        $this->assertSame('Create', $order->operation);
        $this->assertSame('FIBRE', $order->product_id);
        $this->assertSame('PLAN-TEST', $order->plan_id);
        $this->assertSame('RESELLER-CONNECT', $order->scope);
        $this->assertSame('90001', $order->transaction_id);
        $this->assertSame('ASYNC', $order->response_type);
        $this->assertSame('OK', $order->result);
        $this->assertSame('SVC-TEST-0001', $order->service_id);
        $this->assertSame('LOC000000000001', $order->directory_id);
        $this->assertSame('NEW', $order->meta['order_type']);

        $poll = $rows[3];
        $this->assertSame('90001', $poll->related_transaction_id);
        $this->assertSame('PROCESSING', $poll->meta['transaction_state']);
        $this->assertSame(['LOC000000000001'], $rows[0]->meta['directory_ids']);
        $this->assertSame('AVC000000001', $rows[5]->avc_id);
    }

    public function test_faults_keep_the_transaction_id_and_fault_code(): void
    {
        $detail = (object) ['FaultResponse' => (object) ['TransactionID' => '70001', 'ErrorCode' => 'WSM-4220']];
        $c = $this->client(new FakeSoap([new SoapFault('env:Server', 'Nope', null, $detail)]));
        try {
            $c->qualifyAddress('LOC000000000009');
        } catch (\Throwable) {
        }

        $row = IntegrationRequest::where('provider', 'vocus')->sole();
        $this->assertTrue($row->is_error);
        $this->assertSame('70001', $row->transaction_id);
        $this->assertSame('WSM-4220', $row->result);
        $this->assertNull($row->response_type);
    }

    public function test_secrets_are_never_stored(): void
    {
        $this->runJourney();

        $dump = json_encode(IntegrationRequest::all()->toArray());
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString('ALIAS-never-store', $dump);
    }

    public function test_journey_is_grouped_by_directory_id_service_id_and_create_transaction(): void
    {
        $this->runJourney();
        // An unrelated single call (OPER AUTH-LOG) and a second, separate location.
        $c = $this->client(new FakeSoap([$this->resp('60001', 'SYNC'), $this->resp('60002', 'SYNC', ['ServiceQualified' => 'YES'])]));
        $c->getAuthLog('SVC-OTHER-0002', 'PLAN-TEST');
        $c->qualifyAddress('LOC000000000777');

        $data = app(VocusJourneys::class)->build(['environment' => 'sandbox']);

        $this->assertCount(2, $data['journeys']);
        $full = collect($data['journeys'])->firstWhere('service_id', 'SVC-TEST-0001');
        $this->assertTrue($full['complete']);
        $this->assertSame(['lookup', 'qualify', 'order', 'poll', 'poll', 'service'], array_column($full['steps'], 'kind'));
        $this->assertSame('SUCCESS', $full['final_state']);
        $this->assertContains('FIBRE::Create NEW', array_column($full['steps'], 'method'));
        $this->assertContains('DIR::Get (address lookup)', array_column($full['steps'], 'label'));

        $sqOnly = collect($data['journeys'])->firstWhere('type', 'qualification');
        $this->assertFalse($sqOnly['complete']);
        $this->assertContains('order', $sqOnly['missing']);

        $this->assertSame(['OPER::Get AUTH-LOG'], array_column($data['singles'], 'method'));
        $this->assertSame('60001', $data['singles'][0]['calls'][0]['transaction_id']);
    }

    public function test_production_calls_are_kept_out_of_the_sandbox_view(): void
    {
        $this->client(new FakeSoap([$this->resp('50001', 'SYNC')], ), 'production')->qualifyAddress('LOC000000000005');

        $this->assertSame(0, app(VocusJourneys::class)->build(['environment' => 'sandbox'])['stats']['calls']);
        $this->assertSame(1, app(VocusJourneys::class)->build(['environment' => 'production'])['stats']['calls']);
    }

    public function test_csv_export_lists_journey_steps_and_single_calls_and_is_admin_only(): void
    {
        $this->runJourney();
        $this->client(new FakeSoap([$this->resp('60001', 'SYNC')]))->getAuthLog('SVC-OTHER-0002', 'PLAN-TEST');

        $this->actingAs($this->technician())->get(route('admin.audit.vocus.csv'))->assertForbidden();
        auth()->logout();

        $response = $this->actingAs($this->admin())->get(route('admin.audit.vocus.csv', ['environment' => 'sandbox']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();

        $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $this->assertSame(VocusJourneys::CSV_HEADER, $lines[0]);
        $this->assertStringContainsString('FIBRE::Create NEW', $csv);
        $this->assertStringContainsString('90001', $csv);
        $this->assertStringContainsString('OPER::Get AUTH-LOG', $csv);
        $this->assertStringContainsString('single call', $csv);
        $this->assertStringContainsString('complete', $csv);
        $this->assertStringNotContainsString(self::SECRET, $csv);
        $this->assertCount(1 + 6 + 1, $lines);
    }

    public function test_pages_and_summary_are_admin_only(): void
    {
        foreach (['admin.audit.vocus', 'admin.audit.vocus.summary', 'admin.audit.find'] as $route) {
            $this->actingAs($this->technician())->get(route($route))->assertForbidden();
            auth()->logout();
        }
        $this->get(route('admin.audit.vocus.csv'))->assertRedirect(route('login'));
    }

    public function test_printable_summary_lists_transaction_ids_per_method(): void
    {
        $this->runJourney();

        $html = $this->actingAs($this->admin())->get(route('admin.audit.vocus.summary', ['environment' => 'sandbox']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('DomainDash &mdash; Vocus WSM sandbox transactions', $html);
        $this->assertStringContainsString('TransactionIDs by API method', $html);
        $this->assertStringContainsString('FIBRE::Create NEW', $html);
        $this->assertStringContainsString('TCAS::Get RESPONSE (transaction poll)', $html);
        foreach (['80001', '80002', '90001', '90002', '90003', '90004'] as $tx) {
            $this->assertStringContainsString($tx, $html);
        }
        $this->assertStringContainsString('Complete', $html);
        $this->assertStringNotContainsString(self::SECRET, $html);
    }

    public function test_certification_page_renders_journeys_and_the_checklist(): void
    {
        $this->runJourney();

        $this->actingAs($this->admin())->get(route('admin.audit.vocus'))
            ->assertOk()->assertSee('Vocus transactions')->assertSee('SVC-TEST-0001')->assertSee('90001')
            ->assertSee('Complete journey recorded')->assertSee('Download CSV');
    }

    public function test_sandbox_checklist_ticks_steps_as_transactions_are_recorded(): void
    {
        $this->assertSame([false, false, false, false], array_column(app(VocusJourneys::class)->checklist()['steps'], 'done'));

        $c = $this->client(new FakeSoap([$this->resp('80002', 'SYNC', ['ServiceQualified' => 'YES'])]));
        $c->qualifyAddress('LOC000000000001');
        $this->assertSame([true, false, false, false], array_column(app(VocusJourneys::class)->checklist()['steps'], 'done'));

        $this->runJourney('LOC000000000002', 'SVC-TEST-0002', '91001');
        $check = app(VocusJourneys::class)->checklist();
        $this->assertSame([true, true, true, true], array_column($check['steps'], 'done'));
        $this->assertTrue($check['complete']);

        $this->actingAs($this->admin())->get(route('admin.settings.area', ['area' => 'vocus', 'tab' => 'sandbox']))
            ->assertOk()->assertSee('Sandbox certification checklist')->assertSee('Complete journey recorded');
    }

    public function test_backfill_shows_transaction_ids_stored_on_service_records(): void
    {
        config(['services.vocus.environment' => 'sandbox']);
        InternetService::create(['vocus_service_id' => 'SVC-OLD-0001', 'plan_id' => 'PLAN-OLD', 'service_scope' => 'RESELLER-CONNECT', 'order_type' => 'NEW',
            'directory_id' => 'LOC000000000042', 'last_transaction_id' => '10001', 'last_transaction_state' => 'SUCCESS', 'last_polled_at' => now()]);
        AuditLog::create(['action' => 'internet.set_status', 'auditable_type' => 'x', 'auditable_id' => 0, 'description' => 'status', 'new_values' => ['status' => 'SUSPEND', 'transaction_id' => '10002']]);

        $data = app(VocusJourneys::class)->build(['environment' => 'sandbox']);

        $this->assertSame(1, count($data['journeys']));
        $journey = $data['journeys'][0];
        $this->assertSame('SVC-OLD-0001', $journey['service_id']);
        $this->assertSame('service record', $journey['steps'][0]['source']);
        $this->assertTrue($journey['has']['followup']);
        $this->assertFalse($journey['complete']); // no recorded SQ
        $this->assertSame(['FIBRE::Set STATUS'], array_column($data['singles'], 'method'));
        $this->assertGreaterThanOrEqual(2, $data['stats']['from_service_record']);

        $this->actingAs($this->admin())->get(route('admin.audit.vocus'))->assertOk()->assertSee('From service record')->assertSee('10001');
    }

    public function test_backfill_fills_structured_columns_of_rows_recorded_before_the_migration(): void
    {
        $row = IntegrationRequest::create([
            'direction' => 'outbound', 'provider' => 'vocus', 'method' => 'SOAP', 'operation' => 'Create', 'url' => 'https://wsm.example.test/WSM',
            'request_body' => '<CreateRequest><AccessKey>[REDACTED]</AccessKey><ProductID>FIBRE</ProductID><PlanID>PLAN-X</PlanID><Scope>RESELLER-CONNECT</Scope>'
                . '<Parameters><std:Param id="ServiceID">SVC-X-1</std:Param><std:Param id="DirectoryID">LOC1</std:Param><std:Param id="OrderType">NEW</std:Param></Parameters></CreateRequest>',
            'response_body' => json_encode(['TransactionID' => '33001', 'ResponseType' => 'ASYNC']), 'is_error' => false, 'created_at' => now(),
        ]);

        $this->assertSame(1, (new VocusTransactionBackfill())->run());
        $row->refresh();
        $this->assertSame('FIBRE', $row->product_id);
        $this->assertSame('33001', $row->transaction_id);
        $this->assertSame('SVC-X-1', $row->service_id);
        $this->assertSame('LOC1', $row->directory_id);
        $this->assertSame('NEW', $row->meta['order_type']);
        $this->assertSame(0, (new VocusTransactionBackfill())->run()); // idempotent
    }
}
