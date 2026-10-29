<?php

namespace Tests\Feature;

use App\Casts\EncryptedSettingValue;
use App\Jobs\PushDomainsToHaloJob;
use App\Jobs\PushDomainsToItGlueJob;
use App\Jobs\QueueHeartbeatJob;
use App\Jobs\SyncHaloAssetsJob;
use App\Jobs\SyncHaloClientsJob;
use App\Jobs\SyncHaloDomainsJob;
use App\Jobs\SyncItGlueConfigurationsJob;
use App\Jobs\SyncItGlueOrganizationsJob;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Halo\HaloPsaClient;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Sync\HaloDomainPusher;
use App\Services\Sync\QueueHealth;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SyncReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        EncryptedSettingValue::$decryptFailures = [];
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key']);
        Setting::put('itglue', ['api_key' => 'k', 'flexible_asset_type_id' => 1]);
    }

    private function admin(bool $withSyncPermission = true): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('Administrator');
        if ($withSyncPermission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function useDatabaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    private function insertJobRow(int $createdSecondsAgo, ?int $runId = null, ?int $reservedAt = null): void
    {
        $command = $runId ? 'O:8:"stdClass":1:{s:5:"runId";i:' . $runId . ';}' : 'O:8:"stdClass":0:{}';
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'X', 'data' => ['command' => $command]]),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => time() - $createdSecondsAgo,
            'created_at' => time() - $createdSecondsAgo,
        ]);
    }

    private function fakeHaloOk(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype*' => Http::response(['assettypes' => [['id' => 7, 'name' => 'Domain']]]),
        ]);
    }

    // ------------------------------------------------------------------
    // 1. Stale / orphan recovery, duplicate dispatch
    // ------------------------------------------------------------------

    public function test_orphaned_queued_run_is_failed_and_a_fresh_run_is_dispatched(): void
    {
        $this->useDatabaseQueue();
        Queue::fake();

        $orphan = SyncRun::queue('halo', 'clients');
        SyncRun::whereKey($orphan->id)->update(['created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)]);

        $runs = app(SyncDispatcher::class)->refreshHalo();

        $orphan->refresh();
        $this->assertSame(SyncRun::FAILED, $orphan->status);
        $this->assertStringContainsString('Worker did not pick up the job', $orphan->error);
        $this->assertNotSame($orphan->id, $runs[0]->id);
        $this->assertSame(SyncRun::QUEUED, $runs[0]->status);
        Queue::assertPushed(SyncHaloClientsJob::class, 1);
    }

    public function test_queued_run_with_a_pending_job_is_not_orphaned_and_young_runs_are_left_alone(): void
    {
        $this->useDatabaseQueue();

        $waiting = SyncRun::queue('halo', 'assets');
        SyncRun::whereKey($waiting->id)->update(['updated_at' => now()->subMinutes(30)]);
        $this->insertJobRow(1800, $waiting->id);

        $young = SyncRun::queue('halo', 'clients');

        $this->assertSame(0, SyncRun::recoverStale());
        $this->assertSame(SyncRun::QUEUED, $waiting->fresh()->status);
        $this->assertSame(SyncRun::QUEUED, $young->fresh()->status);
        $this->assertSame($waiting->id, SyncRun::active('halo', 'assets')->id);
    }

    public function test_stalled_running_run_without_a_job_is_failed(): void
    {
        $this->useDatabaseQueue();

        $run = SyncRun::queue('halo', 'domains')->markRunning(10);
        SyncRun::whereKey($run->id)->update(['updated_at' => now()->subMinutes(45)]);

        $this->assertNull(SyncRun::active('halo', 'domains'));

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('Run stalled', $run->error);
    }

    public function test_duplicate_dispatch_does_not_create_an_orphan_run(): void
    {
        Queue::fake();
        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10']);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id]);

        $dispatcher = app(SyncDispatcher::class);
        $first = $dispatcher->pushHaloDomains([$domain->id]);
        $second = $dispatcher->pushHaloDomains([$domain->id]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SyncRun::where('scope', 'domains')->count());
        Queue::assertPushed(SyncHaloDomainsJob::class, 1);
    }

    public function test_sync_jobs_are_not_unique_and_use_self_expiring_overlap_protection(): void
    {
        foreach ([new SyncHaloClientsJob(), new SyncHaloAssetsJob(), new SyncHaloDomainsJob(), new SyncItGlueOrganizationsJob(), new SyncItGlueConfigurationsJob()] as $job) {
            $this->assertNotInstanceOf(ShouldBeUnique::class, $job, get_class($job));
            $this->assertNotEmpty(array_filter($job->middleware(), fn ($m) => $m instanceof WithoutOverlapping), get_class($job));
        }
    }

    public function test_every_job_timeout_is_below_retry_after_and_retries_are_capped(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $jobs = [
            new SyncHaloClientsJob(), new SyncHaloAssetsJob(), new SyncHaloDomainsJob(), new SyncItGlueOrganizationsJob(),
            new SyncItGlueConfigurationsJob(), new PushDomainsToHaloJob([1]), new PushDomainsToItGlueJob([1]), new QueueHeartbeatJob(),
        ];
        foreach ($jobs as $job) {
            $this->assertGreaterThan(0, $job->timeout, get_class($job));
            $this->assertLessThan($retryAfter, $job->timeout, get_class($job));
            if (method_exists($job, 'retryUntil')) {
                $this->assertLessThanOrEqual(now()->addMinutes(31)->timestamp, $job->retryUntil()->getTimestamp(), get_class($job));
            }
        }
    }

    // ------------------------------------------------------------------
    // 2. Failure visibility
    // ------------------------------------------------------------------

    public function test_config_missing_is_skipped_with_an_explicit_reason(): void
    {
        Setting::put('halo', []);

        (new SyncHaloClientsJob())->handle();

        $run = SyncRun::first();
        $this->assertSame(SyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('HaloPSA is not configured', $run->error);
        $this->assertStringContainsString('Settings', $run->error);
    }

    public function test_settings_decrypt_failure_is_reported_as_such(): void
    {
        DB::table('settings')->where('key', 'halo')->update(['value' => EncryptedSettingValue::PREFIX . 'not-a-valid-ciphertext']);
        EncryptedSettingValue::$decryptFailures = [];

        (new SyncHaloDomainsJob([1]))->handle();

        $run = SyncRun::first();
        $this->assertSame(SyncRun::SKIPPED, $run->status);
        $this->assertStringContainsString('could not be decrypted', $run->error);
    }

    public function test_auth_failure_fails_the_run_at_once_without_retrying(): void
    {
        $this->useDatabaseQueue();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['halo.example.com/auth/token*' => Http::response(['error' => 'invalid_client', 'client_secret' => 'secret-api-key'], 401)]);

        $run = SyncRun::queue('halo', 'clients');
        SyncHaloClientsJob::dispatch($run->id);
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('HTTP 401', $run->error);
        $this->assertStringNotContainsString('secret-api-key', $run->error);
        $this->assertSame(0, DB::table('jobs')->count(), 'auth errors must not be re-queued');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_transient_error_records_retrying_on_the_run_and_releases_the_job(): void
    {
        $this->useDatabaseQueue();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/client*' => Http::response('upstream down', 503),
        ]);

        $run = SyncRun::queue('halo', 'clients');
        SyncHaloClientsJob::dispatch($run->id);
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $run->refresh();
        $this->assertSame(SyncRun::RUNNING, $run->status);
        $this->assertStringStartsWith('retrying:', $run->error);
        $this->assertStringContainsString('503', $run->error);
        $this->assertSame(1, DB::table('jobs')->count(), 'the job is released for a later attempt');
        $this->assertTrue($run->toStatusArray()['retrying']);
    }

    public function test_transient_errors_give_up_after_the_attempt_cap_with_the_error_recorded(): void
    {
        $this->useDatabaseQueue();
        config(['integrations.sync.max_remote_attempts' => 1]);
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/client*' => Http::response('upstream down', 503),
        ]);

        $run = SyncRun::queue('halo', 'clients');
        SyncHaloClientsJob::dispatch($run->id);
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('Gave up after repeated errors', $run->error);
    }

    public function test_push_job_transient_errors_requeue_with_a_recorded_error_then_fail_visibly(): void
    {
        Queue::fake();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype*' => Http::response('boom', 503),
        ]);

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10']);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01']);
        $run = SyncRun::queue('halo', 'domains')->markRunning(1);

        app()->call([new PushDomainsToHaloJob([$domain->id], $run->id), 'handle']);

        $run->refresh();
        $this->assertSame(SyncRun::RUNNING, $run->status);
        $this->assertStringStartsWith('retrying:', $run->error);
        Queue::assertPushed(PushDomainsToHaloJob::class, fn ($j) => $j->round === 2);

        // Final round: gives up, the error lands on the domain and the run.
        app()->call([new PushDomainsToHaloJob([$domain->id], $run->id, true, 'asset', PushDomainsToHaloJob::MAX_ROUNDS), 'handle']);

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('Gave up after repeated HaloPSA errors', $run->error);
        $this->assertStringContainsString('503', $domain->fresh()->halo_sync_error);
    }

    public function test_push_job_auth_failure_fails_every_domain_fast_and_shows_the_error(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['halo.example.com/auth/token*' => Http::response('nope', 401)]);

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10']);
        $a = Domain::create(['name' => 'a.com', 'client_id' => $client->id]);
        $b = Domain::create(['name' => 'b.com', 'client_id' => $client->id]);
        $run = SyncRun::queue('halo', 'domains')->markRunning(2);

        app()->call([new PushDomainsToHaloJob([$a->id, $b->id], $run->id), 'handle']);

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertSame(2, $run->failed);
        $this->assertStringContainsString('401', $run->error);
        $this->assertStringContainsString('401', $a->fresh()->halo_sync_error);
        $this->assertStringContainsString('401', $b->fresh()->halo_sync_error);
        Queue::assertNothingPushed();
    }

    public function test_job_timeout_marks_the_run_failed_with_a_clear_message(): void
    {
        $run = SyncRun::queue('halo', 'assets')->markRunning();
        $job = new SyncHaloAssetsJob(null, true, $run->id);

        $job->failed(new TimeoutExceededException('timed out'));

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('timed out after 240s', $run->error);
    }

    public function test_unexpected_exception_fails_the_run_immediately(): void
    {
        $this->fakeHaloOk();
        Http::fake(['halo.example.com/api/client*' => fn () => throw new \LogicException('kaboom with Bearer abcdef123456')]);

        $run = SyncRun::queue('halo', 'clients');
        try {
            (new SyncHaloClientsJob($run->id))->handle();
            $this->fail('expected rethrow');
        } catch (\LogicException) {
        }

        $run->refresh();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('kaboom', $run->error);
        $this->assertStringNotContainsString('abcdef123456', $run->error);
    }

    // ------------------------------------------------------------------
    // 4/5. Worker detection + status
    // ------------------------------------------------------------------

    public function test_status_endpoint_includes_domains_scope_and_worker_staleness(): void
    {
        $this->useDatabaseQueue();
        SyncRun::create(['provider' => 'halo', 'scope' => 'domains', 'status' => 'ok', 'finished_at' => now()->subMinutes(3), 'total' => 2, 'done' => 2]);
        $running = SyncRun::queue('halo', 'domains')->markRunning(5);
        $this->insertJobRow(600); // waiting 10 minutes, nobody picked it up

        $json = $this->actingAs($this->admin())->getJson('/admin/sync/status')->assertOk()->json();

        $this->assertNotNull($json['halo']['domains_synced_at']);
        $this->assertSame($running->id, $json['halo']['active_by_scope']['domains']['id']);
        $this->assertArrayHasKey('clients', $json['halo']['latest_by_scope']);
        $this->assertArrayHasKey('configurations', $json['itglue']['latest_by_scope']);
        $this->assertTrue($json['worker']['stale']);
        $this->assertGreaterThanOrEqual(600, $json['worker']['oldest_pending_job_age_seconds']);
        $this->assertStringContainsString('No queue worker is processing jobs (oldest job waiting 10 min)', $json['worker']['message']);
        $this->assertStringContainsString('README', $json['worker']['message']);
    }

    public function test_worker_is_healthy_with_a_fresh_heartbeat_and_no_old_jobs(): void
    {
        $this->useDatabaseQueue();

        $this->assertTrue(app(QueueHealth::class)->status()['stale'], 'no heartbeat yet');

        (new QueueHeartbeatJob())->handle();
        $status = app(QueueHealth::class)->status();

        $this->assertFalse($status['stale']);
        $this->assertNull($status['message']);
        $this->assertNotNull($status['heartbeat_at']);

        $this->insertJobRow(30); // young jobs are fine
        $this->assertFalse(app(QueueHealth::class)->status()['stale']);
    }

    public function test_scheduler_registers_heartbeat_and_stale_recovery(): void
    {
        Artisan::call('schedule:list');
        $names = Artisan::output();

        $this->assertStringContainsString('Queue job: worker heartbeat', $names);
        $this->assertStringContainsString('Fail orphaned or stalled sync runs', $names);
    }

    // ------------------------------------------------------------------
    // 6. Polling endpoint
    // ------------------------------------------------------------------

    public function test_run_endpoint_returns_sanitized_error_and_per_domain_failures(): void
    {
        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10']);
        $run = SyncRun::queue('halo', 'domains')->markRunning(2);
        $domain = Domain::create(['name' => 'bad.com', 'client_id' => $client->id]);
        $domain->forceFill(['halo_sync_error' => 'Halo said: client_secret=hunter2 <script>x</script>'])->save();
        $run->forceFill(['failed' => 1, 'error' => 'Auth failed: Bearer abc123TOKEN client_secret=hunter2'])->save();
        $run->fail('Auth failed: Bearer abc123TOKEN client_secret=hunter2');

        $json = $this->actingAs($this->admin())->getJson('/admin/sync/runs/' . $run->id)->assertOk()->json();

        $this->assertSame('failed', $json['status']);
        $this->assertStringNotContainsString('abc123TOKEN', $json['error']);
        $this->assertStringNotContainsString('hunter2', $json['error']);
        $this->assertSame('bad.com', $json['failures'][0]['name']);
        $this->assertStringNotContainsString('hunter2', $json['failures'][0]['error']);
        $this->assertStringNotContainsString('<script>', $json['failures'][0]['error']);
        $this->assertArrayHasKey('worker', $json);
    }

    public function test_run_endpoint_fails_an_orphan_on_read(): void
    {
        $this->useDatabaseQueue();
        $run = SyncRun::queue('halo', 'clients');
        SyncRun::whereKey($run->id)->update(['updated_at' => now()->subMinutes(20)]);

        $json = $this->actingAs($this->admin())->getJson('/admin/sync/runs/' . $run->id)->assertOk()->json();

        $this->assertSame('failed', $json['status']);
        $this->assertStringContainsString('Worker did not pick up the job', $json['error']);
    }

    public function test_preflight_rejects_domain_sync_when_halo_is_not_configured(): void
    {
        Queue::fake();
        Setting::put('halo', []);

        $this->actingAs($this->admin())
            ->postJson('/admin/sync/halo/domains/sync', ['domain_ids' => [1]])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // 7. Run now
    // ------------------------------------------------------------------

    public function test_run_now_requires_the_sync_permission(): void
    {
        $this->actingAs($this->admin(false))->postJson('/admin/sync/halo/domains/run-now', ['domain_ids' => [1]])->assertForbidden();
        $this->actingAs($this->admin(false))->getJson('/admin/sync/status')->assertForbidden();
    }

    public function test_run_now_is_refused_while_a_worker_is_healthy(): void
    {
        $this->useDatabaseQueue();
        (new QueueHeartbeatJob())->handle();

        $this->actingAs($this->admin())->postJson('/admin/sync/halo/domains/run-now', ['domain_ids' => [1]])
            ->assertStatus(409);
    }

    public function test_run_now_processes_within_its_limit_hands_the_rest_to_the_queue_and_is_audited(): void
    {
        $this->useDatabaseQueue();
        Queue::fake();
        $this->fakeHaloOk();
        config(['integrations.sync.run_now_max_domains' => 2]);

        $unlinked = Client::create(['business_name' => 'NoHalo']);
        $ids = [];
        foreach (['a.com', 'b.com', 'c.com'] as $name) {
            $ids[] = Domain::create(['name' => $name, 'client_id' => $unlinked->id])->id;
        }

        $json = $this->actingAs($this->admin())->postJson('/admin/sync/halo/domains/run-now', ['domain_ids' => $ids])
            ->assertOk()->json();

        $this->assertSame(1, $json['handed_to_queue']);
        $this->assertSame(2, $json['run']['failed']);
        $this->assertSame('Client not linked to HaloPSA', Domain::where('name', 'a.com')->value('halo_sync_error'));
        Queue::assertPushed(PushDomainsToHaloJob::class, fn ($j) => $j->domainIds === [$ids[2]]);
        $this->assertTrue(AuditLog::where('action', 'sync.run-now')->exists());
    }

    public function test_run_now_hands_everything_back_when_the_time_budget_is_spent(): void
    {
        $this->useDatabaseQueue();
        Queue::fake();
        $this->fakeHaloOk();
        config(['integrations.sync.run_now_budget_seconds' => 0]);

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10']);
        $ids = [
            Domain::create(['name' => 'a.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01'])->id,
            Domain::create(['name' => 'b.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01'])->id,
        ];

        $json = $this->actingAs($this->admin())->postJson('/admin/sync/halo/domains/run-now', ['domain_ids' => $ids])
            ->assertOk()->json();

        $this->assertSame(0, $json['run']['done'] + $json['run']['failed']);
        Queue::assertPushed(PushDomainsToHaloJob::class, fn ($j) => $j->domainIds === $ids);
    }

    public function test_run_now_cannot_bypass_the_halo_rate_limit(): void
    {
        $this->useDatabaseQueue();
        Queue::fake();
        $this->fakeHaloOk();
        config(['integrations.halo.rate_limit_per_minute' => 1]);

        $unlinked = Client::create(['business_name' => 'NoHalo']);
        $id = Domain::create(['name' => 'a.com', 'client_id' => $unlinked->id])->id;
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/sync/halo/domains/run-now', ['domain_ids' => [$id]])->assertOk();
        $this->actingAs($admin)->postJson('/admin/sync/halo/domains/run-now', ['domain_ids' => [$id]])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    // ------------------------------------------------------------------
    // 8. Route authorization
    // ------------------------------------------------------------------

    public function test_every_sync_route_requires_the_sync_run_permission(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'admin/sync/')) {
                $this->assertContains('permission:sync.run', $route->gatherMiddleware(), $route->uri());
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(15, $checked);
    }

    // ------------------------------------------------------------------
    // 9. Halo payload configuration + 4xx logging
    // ------------------------------------------------------------------

    public function test_asset_custom_field_ids_are_configurable_with_defaults(): void
    {
        $this->assertSame(['domain' => 148, 'expiry' => 149, 'nameservers' => 150, 'whois' => 165], HaloPsaClient::assetFieldIds());

        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'k', 'asset_field_ids' => ['domain' => '201', 'whois' => '', 'expiry' => 'abc']]);

        $this->assertSame(['domain' => 201, 'expiry' => 149, 'nameservers' => 150, 'whois' => 165], HaloPsaClient::assetFieldIds());
    }

    public function test_pusher_sends_the_configured_field_ids(): void
    {
        $this->fakeHaloOk();
        Http::fake([
            'halo.example.com/api/client/*' => Http::response(['id' => 10, 'site_id' => 3, 'site_name' => 'Main']),
            'halo.example.com/api/site*' => Http::response(['sites' => [['id' => 3, 'name' => 'Main']]]),
            'halo.example.com/api/asset*' => Http::response([['id' => 900]]),
        ]);
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'k', 'asset_field_ids' => ['domain' => 301, 'expiry' => 302, 'nameservers' => 303, 'whois' => 304]]);

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'halo_default_site_id' => 3, 'halo_synced_at' => now()]);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id, 'expiry_date' => '2030-01-01', 'name_servers' => ['ns1.x.com']]);
        $domain->forceFill(['whois_synced_at' => now(), 'whois_data' => ['registrar' => 'X']])->save();

        app(HaloDomainPusher::class)->push($domain->fresh(), app(HaloPsaClient::class), 7, true);

        Http::assertSent(function ($r) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/api/asset')) {
                return false;
            }
            $ids = array_column($r->data()[0]['fields'] ?? [], 'id');

            return $ids === [301, 302, 303, 304];
        });
    }

    public function test_halo_4xx_response_body_is_logged_sanitized_and_truncated(): void
    {
        Log::spy();
        $this->fakeHaloOk();
        Http::fake(['halo.example.com/api/asset*' => Http::response('{"message":"Field 148 invalid","token":"s3cr3tvalue"}' . str_repeat('x', 2000), 422)]);

        try {
            app(HaloPsaClient::class)->createDomainAsset(['client_id' => 1]);
            $this->fail('expected exception');
        } catch (IntegrationApiException $e) {
            $this->assertSame(422, $e->status);
        }

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return $message === 'HaloPSA rejected request'
                && $context['status'] === 422
                && str_contains($context['response'], 'Field 148 invalid')
                && ! str_contains($context['response'], 's3cr3tvalue')
                && mb_strlen($context['response']) <= 500;
        })->once();
    }

    // ------------------------------------------------------------------
    // Ops command
    // ------------------------------------------------------------------

    public function test_sync_reset_fails_stale_runs_and_flushes_failed_jobs_of_one_class(): void
    {
        $this->useDatabaseQueue();

        $stale = SyncRun::queue('halo', 'clients');
        SyncRun::whereKey($stale->id)->update(['updated_at' => now()->subHours(2)]);
        $fresh = SyncRun::queue('halo', 'assets');

        foreach (['App\\Jobs\\SyncSynergyDomainsJob', 'App\\Jobs\\SyncSynergyDomainsJob', 'App\\Jobs\\Other'] as $class) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['displayName' => $class, 'data' => ['commandName' => $class]]),
                'exception' => 'x',
                'failed_at' => now(),
            ]);
        }

        $this->assertSame(0, Artisan::call('domaindash:sync-reset', ['--flush-failed' => 'App\\Jobs\\SyncSynergyDomainsJob']));

        $this->assertSame(SyncRun::FAILED, $stale->fresh()->status);
        $this->assertSame(SyncRun::QUEUED, $fresh->fresh()->status);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        Artisan::call('domaindash:sync-reset', ['--all' => true]);
        $this->assertSame(SyncRun::FAILED, $fresh->fresh()->status);
    }

    public function test_settings_page_and_clients_page_render_with_live_panel_and_banner(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('data-dd-worker-banner', false)
            ->assertSee('DDSync', false)
            ->assertSee('halo[asset_field_ids][domain]', false);

        $this->actingAs($admin)->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('data-dd-worker-banner', false)
            ->assertSee('dd-sync-runs', false);
    }
}
