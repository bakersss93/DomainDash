<?php

namespace Tests\Feature\Monitoring;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MonitoringHelpers;
use Tests\TestCase;

class AuditLogsSearchTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private function poll(string $tx, array $extra = []): void
    {
        $this->makeRequest($extra + [
            'provider' => 'vocus', 'method' => 'SOAP', 'operation' => 'Get', 'url' => 'https://wsm.example.test/WSM', 'product_id' => 'TCAS', 'scope' => 'RESPONSE',
            'environment' => 'sandbox', 'transaction_id' => $tx, 'reference' => 'SVC-TEST-0001', 'entity_type' => 'internet_service', 'result' => 'OK',
        ]);
    }

    public function test_the_page_is_called_audit_logs_and_keeps_its_url(): void
    {
        $this->assertSame('/admin/audit', parse_url(route('admin.audit.index'), PHP_URL_PATH));

        $html = $this->actingAs($this->admin())->get(route('admin.audit.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="ui-page-title">Audit Logs</h1>', $html);
        $this->assertStringNotContainsString('>Monitoring<', $html);
        $this->assertStringContainsString('Transaction ID, service ID or domain name', $html);
    }

    public function test_repeated_identical_requests_are_grouped_and_expandable(): void
    {
        foreach (['70001', '70002', '70003'] as $tx) {
            $this->poll($tx);
        }
        $this->poll('70009', ['reference' => 'SVC-OTHER-0002']);
        $this->actingAs($this->admin());

        $grouped = $this->getJson(route('admin.audit.data', 'outbound'))->json();
        $this->assertSame(2, $grouped['total']);
        $poll = collect($grouped['rows'])->firstWhere('reference', 'SVC-TEST-0001');
        $this->assertSame(3, $poll['count']);
        $this->assertSame('TCAS::Get RESPONSE', $poll['description']);
        $this->assertNotNull($poll['fp']);

        $flat = $this->getJson(route('admin.audit.data', ['outbound', 'group' => 0]))->json();
        $this->assertSame(4, $flat['total']);

        $children = $this->getJson(route('admin.audit.data', ['outbound', 'fp' => json_encode($poll['fp'])]))->json();
        $this->assertSame(3, $children['total']);
        $this->assertEqualsCanonicalizing(['70001', '70002', '70003'], array_column($children['rows'], 'transaction_id'));
    }

    public function test_search_by_transaction_id_service_and_reference(): void
    {
        $this->poll('70001');
        $this->poll('80555', ['reference' => 'other-ref', 'service_id' => 'SVC-FIND-9']);
        $this->makeRequest(['reference' => 'example.com']);
        $this->actingAs($this->admin());

        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'q' => '70001']))->json('total'));
        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'q' => 'SVC-FIND-9']))->json('total'));
        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'q' => 'example.com']))->json('total'));
        $this->assertSame(0, $this->getJson(route('admin.audit.data', ['outbound', 'q' => 'nothing-here']))->json('total'));

        $found = $this->getJson(route('admin.audit.find', ['q' => '70001']))->assertOk()->json();
        $this->assertSame('outbound', $found['best']);
        $this->assertSame(1, $found['counts']['outbound']);
    }

    public function test_filters_by_entity_status_and_provider(): void
    {
        $this->poll('70001');
        $this->makeRequest(['provider' => 'halo', 'entity_type' => 'domain', 'reference' => 'example.com', 'is_error' => true, 'response_status' => 500]);
        $this->actingAs($this->admin());

        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'entity' => 'domain']))->json('total'));
        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'status' => 'failed']))->json('total'));
        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'provider' => 'vocus']))->json('total'));
        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'provider' => 'halo']))->json('total'));
    }

    public function test_activity_hides_routine_system_entries_unless_requested_but_always_shows_failures(): void
    {
        $admin = $this->admin();
        AuditLog::create(['user_id' => $admin->id, 'action' => 'client.update', 'auditable_type' => 'x', 'auditable_id' => 1, 'description' => 'Updated client A']);
        AuditLog::create(['action' => 'sync.completed', 'auditable_type' => 'x', 'auditable_id' => 1, 'description' => 'Sync finished: 12 items']);
        AuditLog::create(['action' => 'sync.failed', 'auditable_type' => 'x', 'auditable_id' => 1, 'description' => 'Sync failed: timeout']);
        $this->actingAs($admin);

        $default = $this->getJson(route('admin.audit.data', 'activity'))->json();
        $this->assertSame(2, $default['total']);
        $this->assertEqualsCanonicalizing(['Updated client A', 'Sync failed: timeout'], array_column($default['rows'], 'description'));

        $this->assertSame(3, $this->getJson(route('admin.audit.data', ['activity', 'system' => 1]))->json('total'));
    }

    public function test_activity_search_finds_a_transaction_id_in_the_recorded_values(): void
    {
        $admin = $this->admin();
        AuditLog::create(['user_id' => $admin->id, 'action' => 'internet.order', 'auditable_type' => 'x', 'auditable_id' => 1,
            'description' => 'NBN service Order submitted', 'new_values' => ['transaction_id' => '99887766']]);
        $this->actingAs($admin);

        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['activity', 'q' => '99887766']))->json('total'));
    }
}
