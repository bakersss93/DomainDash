<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ClientsController;
use App\Http\Controllers\Admin\SyncController;
use App\Jobs\PushDomainsToHaloJob;
use App\Jobs\SyncHaloAssetsJob;
use App\Jobs\SyncHaloClientsJob;
use App\Jobs\SyncHaloDomainsJob;
use App\Jobs\SyncItGlueConfigurationsJob;
use App\Jobs\SyncItGlueOrganizationsJob;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HaloAsset;
use App\Models\HaloClient;
use App\Models\ItglueOrganization;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret']);
        Setting::put('itglue', ['api_key' => 'k', 'flexible_asset_type_id' => 1]);
    }

    public function test_pages_read_local_tables_and_make_no_http_calls(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'itglue_org_id' => '500']);
        Domain::create(['name' => 'acme.com', 'client_id' => $client->id]);
        Domain::create(['name' => 'other.com']);
        HaloClient::create(['halo_id' => 10, 'name' => 'Acme Pty', 'synced_at' => now()]);
        HaloAsset::create(['halo_id' => 99, 'halo_client_id' => 10, 'inventory_number' => 'other.com', 'synced_at' => now()]);
        ItglueOrganization::create(['itglue_id' => 500, 'name' => 'Acme IT', 'synced_at' => now()]);
        SyncRun::create(['provider' => 'halo', 'scope' => 'clients', 'status' => 'ok', 'finished_at' => now()->subMinutes(12)]);

        $sync = app(SyncController::class);

        $domains = $sync->getHaloDomains()->getData(true);
        $byName = collect($domains['domains'])->keyBy('name');
        $this->assertTrue($byName['other.com']['exists_in_halo']);
        $this->assertFalse($byName['acme.com']['exists_in_halo']);
        $this->assertNotNull($domains['sync']['clients_synced_at']);

        $this->assertCount(1, $sync->getHaloClients()->getData(true)['clients']);
        $this->assertCount(1, $sync->getItGlueClients()->getData(true)['clients']);
        $this->assertSame('500', $sync->suggestItGlueOrg($client->id)->getData(true)['suggested_org_id']);
        $this->assertArrayHasKey('items', $sync->getItGlueConfigurations()->getData(true));

        $clients = app(ClientsController::class);
        $this->assertCount(1, $clients->itglueSearch(new Request())->getData(true)['data']);
        $this->assertCount(1, $clients->haloClients(new Request(['show_all' => 1]))->getData(true));

        Http::assertNothingSent();
    }

    public function test_write_endpoints_only_queue_jobs(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10', 'itglue_org_id' => '500']);
        $domain = Domain::create(['name' => 'acme.com', 'client_id' => $client->id]);

        $resp = app(SyncController::class)->syncHaloDomains(new Request(['domain_ids' => [$domain->id]]));
        $this->assertSame(202, $resp->status());
        Queue::assertPushed(SyncHaloDomainsJob::class);

        $resp = app(SyncController::class)->syncItGlueConfigurations(new Request(['items' => [['id' => $domain->id, 'type' => 'domain']]]));
        $this->assertSame(202, $resp->status());
        Queue::assertPushed(SyncItGlueConfigurationsJob::class);

        $resp = app(ClientsController::class)->syncDomainsToItglue($client, new Request());
        $this->assertSame(202, $resp->status());

        Http::assertNothingSent();
    }

    public function test_refresh_dispatches_once_while_a_run_is_active(): void
    {
        Queue::fake();

        $dispatcher = app(SyncDispatcher::class);
        $dispatcher->refreshHalo();
        $dispatcher->refreshHalo();

        Queue::assertPushed(SyncHaloClientsJob::class, 1);
        Queue::assertPushed(SyncHaloAssetsJob::class, 1);
        $this->assertSame(2, SyncRun::where('provider', 'halo')->count());
    }

    public function test_run_sync_task_command_dispatches_chains_and_returns(): void
    {
        Bus::fake();

        $this->assertSame(0, Artisan::call('domaindash:sync-task', ['task' => 'sync-halo-assets']));
        Bus::assertChained([SyncHaloClientsJob::class, SyncHaloAssetsJob::class, SyncHaloDomainsJob::class]);

        $this->assertSame(0, Artisan::call('domaindash:sync-task', ['task' => 'sync-itglue']));
        Bus::assertChained([SyncItGlueOrganizationsJob::class, SyncItGlueConfigurationsJob::class]);
    }

    public function test_halo_asset_job_mirrors_assets_and_links_domains_with_one_filtered_paged_call(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype*' => Http::response(['assettypes' => [['id' => 7, 'name' => 'Domain']]]),
            'halo.example.com/api/asset*' => Http::response([
                'record_count' => 1,
                'assets' => [['id' => 555, 'client_id' => 10, 'assettype_id' => 7, 'assettype_name' => 'Domain', 'inventory_number' => 'Acme.com']],
            ]),
        ]);

        $client = Client::create(['business_name' => 'Acme', 'halopsa_reference' => '10']);
        $domain = Domain::create(['name' => 'acme.com']);

        (new SyncHaloAssetsJob())->handle();

        $this->assertSame(555, (int) $domain->fresh()->halo_asset_id);
        $this->assertSame($client->id, $domain->fresh()->client_id);
        $this->assertDatabaseHas('halo_assets', ['halo_id' => 555, 'halo_client_id' => 10]);
        $this->assertNotNull($client->fresh()->halo_synced_at);
        $this->assertSame(SyncRun::OK, SyncRun::first()->status);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/asset?')
            && str_contains($r->url(), 'assettype_id=7')
            && ! str_contains($r->url(), 'client_id='));
    }

    public function test_halo_push_job_isolates_failures_and_counts_the_run(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/assettype*' => Http::response(['assettypes' => [['id' => 7, 'name' => 'Domain']]]),
        ]);

        $unlinked = Client::create(['business_name' => 'NoHalo']);
        $domain = Domain::create(['name' => 'nolink.com', 'client_id' => $unlinked->id]);
        $run = SyncRun::queue('halo', 'domains')->markRunning(1);

        app()->call([new PushDomainsToHaloJob([$domain->id], $run->id), 'handle']);

        $this->assertSame('Client not linked to HaloPSA', $domain->fresh()->halo_sync_error);
        $run->refresh();
        $this->assertSame(1, $run->failed);
        $this->assertTrue($run->isFinished());
    }

    public function test_unconfigured_integration_skips_quietly(): void
    {
        Http::preventStrayRequests();
        Setting::put('halo', []);

        (new SyncHaloClientsJob())->handle();

        $this->assertSame(SyncRun::SKIPPED, SyncRun::first()->status);
        Http::assertNothingSent();
    }
}
