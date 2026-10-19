<?php

namespace App\Services\Sync;

use App\Jobs\SyncHaloAssetsJob;
use App\Jobs\SyncHaloClientsJob;
use App\Jobs\SyncHaloDomainsJob;
use App\Jobs\SyncItGlueConfigurationsJob;
use App\Jobs\SyncItGlueOrganizationsJob;
use App\Models\Client;
use App\Models\SyncRun;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Bus;

/**
 * Single entry point used by controllers, the console command and the
 * scheduler. Creates the SyncRun row, dispatches the job and returns at once.
 * If an identical run is already queued/running, that run is returned instead.
 */
class SyncDispatcher
{
    /** @return SyncRun[] */
    public function refreshHalo(?int $userId = null): array
    {
        $runs = [];

        if (! ($clients = SyncRun::active('halo', 'clients'))) {
            $clients = SyncRun::queue('halo', 'clients', null, $userId);
            SyncHaloClientsJob::dispatch($clients->id);
        }
        $runs[] = $clients;

        if (! ($assets = SyncRun::active('halo', 'assets'))) {
            $assets = SyncRun::queue('halo', 'assets', null, $userId);
            SyncHaloAssetsJob::dispatch(null, true, $assets->id);
        }
        $runs[] = $assets;

        $this->audit('halo', 'refresh');

        return $runs;
    }

    public function refreshHaloClient(Client $client, ?int $userId = null): SyncRun
    {
        if ($run = SyncRun::active('halo', 'assets', $client->id)) {
            return $run;
        }

        $run = SyncRun::queue('halo', 'assets', $client->id, $userId);
        SyncHaloAssetsJob::dispatch($client->id, true, $run->id);
        $this->audit('halo', 'refresh-client', ['client_id' => $client->id]);

        return $run;
    }

    public function refreshItGlue(?int $userId = null): SyncRun
    {
        if ($run = SyncRun::active('itglue', 'organizations')) {
            return $run;
        }

        $run = SyncRun::queue('itglue', 'organizations', null, $userId);
        SyncItGlueOrganizationsJob::dispatch($run->id);
        $this->audit('itglue', 'refresh');

        return $run;
    }

    public function pushHaloDomains(?array $domainIds = null, ?int $clientId = null, bool $force = true, string $mode = 'asset', ?int $userId = null): SyncRun
    {
        if ($domainIds === null && ($run = SyncRun::active('halo', 'domains', $clientId))) {
            return $run;
        }

        $run = SyncRun::queue('halo', 'domains', $clientId, $userId);
        SyncHaloDomainsJob::dispatch($domainIds, $clientId, $force, $mode, $run->id);
        $this->audit('halo', 'push-domains', ['domains' => $domainIds === null ? 'all' : count($domainIds), 'mode' => $mode]);

        return $run;
    }

    public function pushItGlueDomains(?array $domainIds = null, ?int $clientId = null, bool $force = true, ?int $userId = null): SyncRun
    {
        if ($domainIds === null && ($run = SyncRun::active('itglue', 'configurations', $clientId))) {
            return $run;
        }

        $run = SyncRun::queue('itglue', 'configurations', $clientId, $userId);
        SyncItGlueConfigurationsJob::dispatch($domainIds, $clientId, $force, $run->id);
        $this->audit('itglue', 'push-domains', ['domains' => $domainIds === null ? 'all' : count($domainIds)]);

        return $run;
    }

    /** Scheduled "sync-halo-assets": pull clients + assets, then push changed domains. */
    public function scheduleHaloPipeline(): void
    {
        Bus::chain([
            new SyncHaloClientsJob(),
            new SyncHaloAssetsJob(),
            new SyncHaloDomainsJob(null, null, false),
        ])->dispatch();
    }

    /** Scheduled "sync-itglue": pull organisations, then push changed domains. */
    public function scheduleItGluePipeline(): void
    {
        Bus::chain([
            new SyncItGlueOrganizationsJob(),
            new SyncItGlueConfigurationsJob(null, null, false),
        ])->dispatch();
    }

    /** Latest status per provider for "Last synced X ago" widgets. */
    public function status(): array
    {
        $at = fn (string $p, string $s) => SyncRun::lastSyncedAt($p, $s);
        $active = fn (string $p, string $s) => SyncRun::active($p, $s)?->toStatusArray();

        return [
            'halo' => [
                'clients_synced_at' => $at('halo', 'clients')?->toIso8601String(),
                'assets_synced_at' => $at('halo', 'assets')?->toIso8601String(),
                'last_synced_at' => collect([$at('halo', 'assets'), $at('halo', 'clients')])->filter()->max()?->toIso8601String(),
                'last_synced_human' => collect([$at('halo', 'assets'), $at('halo', 'clients')])->filter()->max()?->diffForHumans(),
                'active' => $active('halo', 'assets') ?? $active('halo', 'clients'),
            ],
            'itglue' => [
                'last_synced_at' => $at('itglue', 'organizations')?->toIso8601String(),
                'last_synced_human' => $at('itglue', 'organizations')?->diffForHumans(),
                'active' => $active('itglue', 'organizations'),
            ],
        ];
    }

    private function audit(string $service, string $function, array $extra = []): void
    {
        try {
            AuditLogger::logSystem('sync.queued', "Queued {$service} {$function}.", [
                'service' => $service,
                'function' => "sync-{$function}",
            ], ['new_values' => $extra]);
        } catch (\Throwable) {
            // audit table unavailable must never block dispatching
        }
    }
}
