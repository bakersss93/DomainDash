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
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueClient;
use Illuminate\Support\Facades\Bus;

/**
 * Single entry point used by controllers, the console command and the
 * scheduler. Creates the SyncRun row, dispatches the job and returns at once.
 * If an identical run is genuinely queued/running, that run is returned
 * instead. Orphaned runs (queued with no job, running with no progress) are
 * failed first (SyncRun::active) so they can never block a fresh dispatch.
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
        if ($domainIds !== null && ($run = $this->identicalActiveRun('halo', 'domains', $clientId, $domainIds))) {
            return $run;
        }

        $run = SyncRun::queue('halo', 'domains', $clientId, $userId);
        $this->remember($run, $domainIds);
        SyncHaloDomainsJob::dispatch($domainIds, $clientId, $force, $mode, $run->id);
        $this->audit('halo', 'push-domains', ['domains' => $domainIds === null ? 'all' : count($domainIds), 'mode' => $mode]);

        return $run;
    }

    public function pushItGlueDomains(?array $domainIds = null, ?int $clientId = null, bool $force = true, ?int $userId = null): SyncRun
    {
        if ($domainIds === null && ($run = SyncRun::active('itglue', 'configurations', $clientId))) {
            return $run;
        }
        if ($domainIds !== null && ($run = $this->identicalActiveRun('itglue', 'configurations', $clientId, $domainIds))) {
            return $run;
        }

        $run = SyncRun::queue('itglue', 'configurations', $clientId, $userId);
        $this->remember($run, $domainIds);
        SyncItGlueConfigurationsJob::dispatch($domainIds, $clientId, $force, $run->id);
        $this->audit('itglue', 'push-domains', ['domains' => $domainIds === null ? 'all' : count($domainIds)]);

        return $run;
    }

    /**
     * An explicit-selection push with exactly the same ids that is still alive:
     * return it instead of queueing a duplicate (a double click must not spawn
     * two runs).
     */
    private function identicalActiveRun(string $provider, string $scope, ?int $clientId, array $ids): ?SyncRun
    {
        $run = SyncRun::active($provider, $scope, $clientId);
        if (! $run) {
            return null;
        }

        $key = 'sync-run-ids:' . $run->id;
        $stored = \Illuminate\Support\Facades\Cache::get($key);

        return $stored !== null && $stored === $this->fingerprint($ids) ? $run : null;
    }

    private function remember(SyncRun $run, ?array $ids): void
    {
        if ($ids !== null) {
            \Illuminate\Support\Facades\Cache::put('sync-run-ids:' . $run->id, $this->fingerprint($ids), 3600);
        }
    }

    private function fingerprint(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return md5(json_encode($ids));
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

    /** Latest status per provider for "Last synced X ago" widgets, plus worker health. */
    public function status(): array
    {
        SyncRun::recoverStale();

        $at = fn (string $p, string $s) => SyncRun::lastSyncedAt($p, $s);
        $active = fn (string $p, string $s) => SyncRun::active($p, $s)?->toStatusArray();
        $latest = fn (string $p, string $s) => SyncRun::query()->where('provider', $p)->where('scope', $s)->whereNull('client_id')->latest('id')->first()?->toStatusArray();

        $haloLast = collect([$at('halo', 'assets'), $at('halo', 'clients')])->filter()->max();

        return [
            'worker' => app(QueueHealth::class)->status(),
            'halo' => [
                'configured' => HaloPsaClient::isConfigured(),
                'configuration_problem' => HaloPsaClient::configurationProblem(),
                'clients_synced_at' => $at('halo', 'clients')?->toIso8601String(),
                'assets_synced_at' => $at('halo', 'assets')?->toIso8601String(),
                'domains_synced_at' => $at('halo', 'domains')?->toIso8601String(),
                'last_synced_at' => $haloLast?->toIso8601String(),
                'last_synced_human' => $haloLast?->diffForHumans(),
                'active' => $active('halo', 'assets') ?? $active('halo', 'clients') ?? $active('halo', 'domains'),
                'active_by_scope' => [
                    'clients' => $active('halo', 'clients'),
                    'assets' => $active('halo', 'assets'),
                    'domains' => $active('halo', 'domains'),
                ],
                'latest_by_scope' => [
                    'clients' => $latest('halo', 'clients'),
                    'assets' => $latest('halo', 'assets'),
                    'domains' => $latest('halo', 'domains'),
                ],
            ],
            'itglue' => [
                'configured' => ItGlueClient::isConfigured(),
                'last_synced_at' => $at('itglue', 'organizations')?->toIso8601String(),
                'last_synced_human' => $at('itglue', 'organizations')?->diffForHumans(),
                'configurations_synced_at' => $at('itglue', 'configurations')?->toIso8601String(),
                'active' => $active('itglue', 'organizations') ?? $active('itglue', 'configurations'),
                'active_by_scope' => [
                    'organizations' => $active('itglue', 'organizations'),
                    'configurations' => $active('itglue', 'configurations'),
                ],
                'latest_by_scope' => [
                    'organizations' => $latest('itglue', 'organizations'),
                    'configurations' => $latest('itglue', 'configurations'),
                ],
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
