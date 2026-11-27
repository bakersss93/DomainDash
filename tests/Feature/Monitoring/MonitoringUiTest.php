<?php

namespace Tests\Feature\Monitoring;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\IntegrationRequest;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\MonitoringHelpers;
use Tests\TestCase;

class MonitoringUiTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    // ---- access control ----------------------------------------------------

    public function test_every_monitoring_endpoint_is_admin_only(): void
    {
        $row = $this->makeRequest();
        $urls = [
            ['get', route('admin.audit.index')],
            ['get', route('admin.audit.data', 'outbound')],
            ['get', route('admin.audit.show', ['outbound', $row->id])],
            ['get', route('admin.audit.export', ['outbound', $row->id])],
            ['delete', route('admin.audit.destroy', ['outbound', $row->id])],
        ];

        foreach ($urls as [$method, $url]) {
            $this->actingAs($this->technician())->{$method}($url)->assertForbidden();
        }

        $this->assertDatabaseHas('integration_requests', ['id' => $row->id]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $row = $this->makeRequest();

        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
        $this->getJson(route('admin.audit.show', ['outbound', $row->id]))->assertUnauthorized();
        $this->deleteJson(route('admin.audit.destroy', ['outbound', $row->id]))->assertUnauthorized();
        $this->assertDatabaseHas('integration_requests', ['id' => $row->id]);
    }

    public function test_administrator_without_mfa_cannot_open_the_logs(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        $this->actingAs($admin)->getJson(route('admin.audit.data', 'outbound'))->assertForbidden();
    }

    public function test_index_renders_with_every_tab_and_the_size_info(): void
    {
        $this->withoutVite();
        AuditLog::create(['action' => 'client.create', 'auditable_type' => 'x', 'auditable_id' => 0, 'description' => 'Created client Example Pty Ltd']);
        $response = $this->actingAs($this->admin())->get(route('admin.audit.index'));

        $response->assertOk()->assertSee('Audit Logs')->assertSee('Vocus transactions')->assertSee('Integration Outbound')->assertSee('Integration Inbound')
            ->assertSee('Integration Errors')->assertSee('Jobs &amp; Schedules', false)->assertSee('Activity');
        $this->assertGreaterThan(0, $response->viewData('dbBytes'));
    }

    // ---- pagination / search ----------------------------------------------

    public function test_outbound_list_is_server_side_paginated_and_searchable(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->makeRequest(['reference' => $i === 7 ? 'needle.example.com' : "d{$i}.example.com"]);
        }
        $this->actingAs($this->admin());

        $page1 = $this->getJson(route('admin.audit.data', ['outbound', 'page' => 1]))->assertOk()->json();
        $this->assertSame(60, $page1['total']);
        $this->assertCount(50, $page1['rows']);
        $this->assertSame([1, 50, 2], [$page1['from'], $page1['to'], $page1['last_page']]);

        $page2 = $this->getJson(route('admin.audit.data', ['outbound', 'page' => 2]))->json();
        $this->assertCount(10, $page2['rows']);
        $this->assertSame([51, 60], [$page2['from'], $page2['to']]);

        $found = $this->getJson(route('admin.audit.data', ['outbound', 'q' => 'needle']))->json();
        $this->assertSame(1, $found['total']);
        $this->assertSame('needle.example.com', $found['rows'][0]['reference']);
    }

    public function test_status_provider_and_date_filters(): void
    {
        $this->makeRequest(['provider' => 'halo']);
        $this->makeRequest(['provider' => 'itglue', 'is_error' => true, 'response_status' => 500, 'error_message' => 'HTTP 500']);
        $this->makeRequest(['provider' => 'itglue', 'created_at' => now()->subDays(10)]);
        $this->actingAs($this->admin());

        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['outbound', 'status' => 'failed']))->json('total'));
        $this->assertSame(2, $this->getJson(route('admin.audit.data', ['outbound', 'provider' => 'itglue']))->json('total'));
        $this->assertSame(2, $this->getJson(route('admin.audit.data', ['outbound', 'from' => now()->subDay()->toDateString()]))->json('total'));
    }

    // ---- detail / export / delete -----------------------------------------

    public function test_detail_has_request_and_response_tabs_with_status_badge_and_masked_headers(): void
    {
        $row = $this->makeRequest(['reference' => 'example.com']);
        $this->actingAs($this->admin());

        $detail = $this->getJson(route('admin.audit.show', ['outbound', $row->id]))->assertOk()->json();

        $this->assertSame('success', $detail['status']);
        $this->assertSame(['request', 'response'], array_column($detail['tabs'], 'key'));
        $this->assertSame('200', $detail['tabs'][1]['badge']['text']);
        $this->assertTrue($detail['deletable']);

        $labels = array_column($detail['tabs'][0]['blocks'], 'label');
        foreach (['Timestamp', 'URL', 'Headers'] as $label) {
            $this->assertContains($label, $labels);
        }
        $headers = collect($detail['tabs'][0]['blocks'])->firstWhere('label', 'Headers')['value'];
        $this->assertSame('ITG.7474…', $headers['x-api-key']);
        $this->assertStringStartsWith('Payload', collect($detail['tabs'][0]['blocks'])->last()['label']);
    }

    public function test_detail_never_returns_stored_secrets_even_if_a_row_was_written_badly(): void
    {
        $row = $this->makeRequest([
            'request_headers' => ['Authorization' => 'Bearer very-secret-token', 'x-api-key' => 'ITG.74742222333344445555'],
            'request_body' => '{"client_secret": "leaked-secret"}',
        ]);
        $this->actingAs($this->admin());

        $json = json_encode($this->getJson(route('admin.audit.show', ['outbound', $row->id]))->json());
        $this->assertStringNotContainsString('very-secret-token', $json);
        $this->assertStringNotContainsString('leaked-secret', $json);
        $this->assertStringNotContainsString('ITG.74742222333344445555', $json);
    }

    public function test_export_downloads_json_and_is_audited(): void
    {
        $row = $this->makeRequest();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.audit.export', ['outbound', $row->id]));

        $response->assertOk()->assertHeader('content-disposition');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame('outbound', $payload['source']);
        $this->assertSame('example.com', $payload['record']['reference']);
        $this->assertArrayHasKey('detail', $payload);

        $audit = AuditLog::where('action', 'monitoring.entry_exported')->sole();
        $this->assertSame($admin->email, $audit->user_email);
    }

    public function test_delete_removes_the_entry_and_is_audited(): void
    {
        $row = $this->makeRequest();
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson(route('admin.audit.destroy', ['outbound', $row->id]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('integration_requests', ['id' => $row->id]);
        $audit = AuditLog::where('action', 'monitoring.entry_deleted')->sole();
        $this->assertSame($admin->email, $audit->user_email);
        $this->assertStringContainsString('halo', strtolower($audit->description));
    }

    public function test_activity_entries_cannot_be_deleted_from_the_ui(): void
    {
        $log = AuditLog::create(['action' => 'client.create', 'auditable_type' => 'x', 'auditable_id' => 1, 'description' => 'Created client Example Pty Ltd']);

        $this->actingAs($this->admin())->deleteJson(route('admin.audit.destroy', ['activity', $log->id]))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    // ---- tabs ---------------------------------------------------------------

    public function test_activity_tab_hides_legacy_noise_and_shows_old_new_diff(): void
    {
        foreach (['http.get', 'console.command_started', 'queue.job_finished', 'scheduler.task_finished'] as $noise) {
            AuditLog::create(['action' => $noise, 'auditable_type' => 'System', 'auditable_id' => 0, 'description' => 'noise ' . $noise]);
        }
        $log = AuditLog::create([
            'user_id' => $this->admin()->id, 'action' => 'client.update', 'auditable_type' => 'App\\Models\\Client', 'auditable_id' => 4, 'description' => 'Updated client Example Pty Ltd',
            'old_values' => ['phone' => '0400 000 000'], 'new_values' => ['phone' => '0411 111 111'], 'context' => ['service' => 'clients', 'reference' => 'Example Pty Ltd'],
        ]);
        $this->actingAs($this->admin());

        $list = $this->getJson(route('admin.audit.data', 'activity'))->json();
        $this->assertSame(1, $list['total']);
        $this->assertSame('Updated client Example Pty Ltd', $list['rows'][0]['description']);
        $this->assertSame('Clients', $list['rows'][0]['module']);

        $this->assertSame(5, $this->getJson(route('admin.audit.data', ['activity', 'debug' => 1, 'system' => 1]))->json('total'));

        $detail = $this->getJson(route('admin.audit.show', ['activity', $log->id]))->json();
        $diff = collect($detail['tabs'])->firstWhere('key', 'changes')['blocks'][0]['value'];
        $this->assertSame([['field' => 'phone', 'old' => '0400 000 000', 'new' => '0411 111 111']], $diff);
        $this->assertFalse($detail['deletable']);
    }

    public function test_inbound_tab_shows_api_key_requests_with_request_and_response_detail(): void
    {
        $key = ApiKey::generate();
        ApiKey::create(['name' => 'Automation', 'key_prefix' => $key['prefix'], 'key_hash' => $key['hash'], 'scopes' => ['domains.read'], 'active' => true, 'rate_limit_per_hour' => 100]);
        Domain::create(['name' => 'example.com']);

        $this->getJson('/api/v1/domains', ['x-api-key' => $key['plain']])->assertOk();
        $this->getJson('/api/v1/domains', ['x-api-key' => 'bad.key'])->assertUnauthorized();

        $row = IntegrationRequest::where('direction', 'inbound')->where('is_error', false)->sole();
        $this->assertSame('Automation', $row->actor);
        $this->assertSame('GET', $row->method);
        $this->assertStringNotContainsString($key['plain'], json_encode($row->toArray()));
        $this->assertStringStartsWith(substr($key['plain'], 0, 8), $row->request_headers['x-api-key']);
        $this->assertStringContainsString('example.com', $row->response_body);
        $this->assertSame(1, IntegrationRequest::where('direction', 'inbound')->where('is_error', true)->count());

        $this->actingAs($this->admin());
        $list = $this->getJson(route('admin.audit.data', 'inbound'))->json();
        $this->assertSame(2, $list['total']);
        $this->assertContains('Automation', array_column($list['rows'], 'actor'));

        $detail = $this->getJson(route('admin.audit.show', ['inbound', $row->id]))->json();
        $this->assertSame('200', $detail['tabs'][1]['badge']['text']);
    }

    public function test_inbound_errors_only_level_drops_successful_api_calls(): void
    {
        $this->monitoring(['inbound_level' => 'errors']);
        $key = ApiKey::generate();
        ApiKey::create(['name' => 'Automation', 'key_prefix' => $key['prefix'], 'key_hash' => $key['hash'], 'scopes' => ['domains.read'], 'active' => true, 'rate_limit_per_hour' => 100]);

        $this->getJson('/api/v1/domains', ['x-api-key' => $key['plain']])->assertOk();

        $this->assertSame(0, IntegrationRequest::where('direction', 'inbound')->count());
        $this->assertDatabaseCount('api_access_logs', 1); // the legacy access table keeps recording
    }

    public function test_errors_tab_combines_failed_requests_and_record_sync_errors(): void
    {
        $this->makeRequest(['provider' => 'itglue', 'is_error' => true, 'response_status' => 404, 'error_message' => 'HTTP 404 not found', 'reference' => 'organizations/9', 'entity_type' => 'organizations']);
        $this->makeRequest(['is_error' => false, 'reference' => 'fine']);
        $domain = Domain::create(['name' => 'broken.example.com']);
        $domain->forceFill(['halo_sync_error' => 'Domain is not linked to a HaloPSA client'])->save();
        $this->actingAs($this->admin());

        $list = $this->getJson(route('admin.audit.data', 'errors'))->assertOk()->json();

        $this->assertSame(2, $list['total']);
        $byRef = collect($list['rows'])->keyBy('reference');
        $this->assertSame('IT Glue', $byRef['organizations/9']['module']);
        $this->assertSame('HaloPSA', $byRef['broken.example.com']['module']);
        $this->assertSame('domain', $byRef['broken.example.com']['entity']);
        $this->assertStringContainsString('not linked', $byRef['broken.example.com']['error']);

        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['errors', 'q' => 'broken']))->json('total'));
        $this->assertSame(1, $this->getJson(route('admin.audit.data', ['errors', 'provider' => 'itglue']))->json('total'));

        $derived = $this->getJson(route('admin.audit.show', ['errors', $byRef['broken.example.com']['id']]))->assertOk()->json();
        $this->assertFalse($derived['deletable']);
        $this->deleteJson(route('admin.audit.destroy', ['errors', $byRef['broken.example.com']['id']]))->assertNotFound();
    }

    public function test_jobs_tab_lists_sync_runs_instead_of_per_job_events(): void
    {
        $run = SyncRun::queue('halo', 'clients');
        $run->markRunning(10);
        $run->done = 8;
        $run->failed = 2;
        $run->save();
        $run->finish();
        $this->makeRequest(['sync_run_id' => $run->id]);
        $this->actingAs($this->admin());

        $list = $this->getJson(route('admin.audit.data', 'jobs'))->json();
        $this->assertSame(1, $list['total']);
        $this->assertSame('HaloPSA', $list['rows'][0]['provider']);
        $this->assertSame('clients', $list['rows'][0]['scope']);
        $this->assertSame('partial', $list['rows'][0]['run_status']);
        $this->assertStringContainsString('8 done, 2 failed', $list['rows'][0]['counts']);

        $detail = $this->getJson(route('admin.audit.show', ['jobs', $run->id]))->json();
        $this->assertSame('requests', $detail['tabs'][1]['key']);
        $this->assertSame('1', $detail['tabs'][1]['badge']['text']);
    }

    // ---- settings / retention -----------------------------------------------

    public function test_settings_form_saves_levels_and_retention_and_is_audited(): void
    {
        $this->actingAs($this->admin())->post(route('admin.audit.retention'), [
            'retention_days' => 120, 'retention_days_outbound' => 14, 'retention_days_inbound' => 21, 'retention_days_jobs' => 60,
            'outbound_level' => 'all', 'inbound_level' => 'errors', 'verbose' => '1', 'mask_pii' => '0',
        ])->assertRedirect();

        $settings = app(\App\Services\Monitoring\MonitoringSettings::class);
        $this->assertSame('all', $settings->level('outbound'));
        $this->assertSame('errors', $settings->level('inbound'));
        $this->assertTrue($settings->verbose());
        $this->assertFalse($settings->maskPii());
        $this->assertSame(14, $settings->retentionDays('outbound'));
        $this->assertSame(120, $settings->retentionDays('activity'));
        $this->assertSame(1, AuditLog::where('action', 'monitoring.settings')->count());
    }

    public function test_retention_prunes_each_category_by_its_own_days(): void
    {
        $this->monitoring(['retention_days' => 90, 'retention_days_outbound' => 7, 'retention_days_inbound' => 30, 'retention_days_jobs' => 30]);

        $this->makeRequest(['created_at' => now()->subDays(10)]);                         // outbound, expired (7d)
        $this->makeRequest(['created_at' => now()->subDays(3)]);                          // outbound, kept
        $this->makeRequest(['direction' => 'inbound', 'provider' => 'api', 'created_at' => now()->subDays(10)]); // inbound, kept (30d)
        $this->makeRequest(['direction' => 'inbound', 'provider' => 'api', 'created_at' => now()->subDays(45)]); // inbound, expired
        (new AuditLog())->forceFill(['action' => 'old.event', 'auditable_type' => 'x', 'created_at' => now()->subDays(100)])->save();
        (new AuditLog())->forceFill(['action' => 'new.event', 'auditable_type' => 'x', 'created_at' => now()->subDays(5)])->save();
        $old = SyncRun::queue('halo', 'clients');
        $old->forceFill(['status' => 'ok', 'finished_at' => now()->subDays(40)])->save();
        $recent = SyncRun::queue('halo', 'assets');
        $recent->forceFill(['status' => 'ok', 'finished_at' => now()->subDays(2)])->save();
        Cache::flush();

        $this->artisan('domaindash:audit-prune')->assertSuccessful();

        $this->assertSame(1, IntegrationRequest::where('direction', 'outbound')->count());
        $this->assertSame(1, IntegrationRequest::where('direction', 'inbound')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'old.event']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'new.event']);
        $this->assertDatabaseMissing('sync_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('sync_runs', ['id' => $recent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitoring.pruned']);
    }
}
