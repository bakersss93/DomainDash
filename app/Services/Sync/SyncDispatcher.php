<?php

namespace App\Services\Sync;

use App\Jobs\SyncHaloAssetsJob;
use App\Jobs\SyncHaloClientsJob;
use App\Jobs\SyncHaloDomainsJob;
use App\Jobs\SyncItGlueConfigurationsJob;
use App\Jobs\SyncItGlueOrganizationsJob;
use App\Jobs\SyncEntityAssetsJob;
use App\Services\Assets\AssetEntity;
use App\Services\Halo\HaloAssetMapping;
use App\Services\ItGlue\ItGlueAssetMapping;
use App\Jobs\PushInternetServicesToHaloJob;
use App\Jobs\SyncVocusServicesJob;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\SyncRun;
use App\Services\AuditLogger;
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Vocus\VocusWsmClient;
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

    /** Microsoft partner (CSP/GDAP) customer tenant mirror. One run at a time: a live run is returned instead. */
    public function refreshPartnerTenants(?int $userId = null, bool $automated = false): \App\Models\SyncRun
    {
        if ($run = SyncRun::active(\App\Jobs\SyncPartnerTenantsJob::PROVIDER, \App\Jobs\SyncPartnerTenantsJob::SCOPE)) {
            return $run;
        }

        $run = SyncRun::queue(\App\Jobs\SyncPartnerTenantsJob::PROVIDER, \App\Jobs\SyncPartnerTenantsJob::SCOPE, null, $userId);
        \App\Jobs\SyncPartnerTenantsJob::dispatch($run->id, $automated);

        return $run;
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

    /**
     * Queue a Vocus internet service refresh (live panel scope "vocus/services").
     * With $vocusIds = null every locally known service is refreshed; with ids and
     * $create = true (import) rows are created only after Vocus returned the service.
     * One run per provider/scope at a time: a genuinely running run is returned instead.
     *
     * @param string[]|null $vocusIds
     */
    public function refreshVocus(?array $vocusIds = null, bool $create = false, ?int $userId = null): SyncRun
    {
        if ($run = SyncRun::active('vocus', 'services')) {
            return $run;
        }

        $run = SyncRun::queue('vocus', 'services', null, $userId);
        SyncVocusServicesJob::dispatch($vocusIds, $create, $run->id);
        $this->audit('vocus', $create ? 'import-services' : 'refresh-services', ['services' => $vocusIds === null ? 'all' : count($vocusIds)]);

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

    /**
     * Bill internet services in Halo (subscription + recurring invoice line), live panel scope "halo/subscriptions".
     * With $serviceIds = null every service that has a client is pushed; an identical running selection is returned
     * instead of queueing a duplicate.
     *
     * @param int[]|null $serviceIds
     */
    public function pushHaloInternetServices(?array $serviceIds = null, bool $force = true, ?int $userId = null): SyncRun
    {
        if ($serviceIds === null && ($run = SyncRun::active('halo', 'subscriptions'))) {
            return $run;
        }
        if ($serviceIds !== null && ($run = $this->identicalActiveRun('halo', 'subscriptions', null, $serviceIds))) {
            return $run;
        }

        $run = SyncRun::queue('halo', 'subscriptions', null, $userId);
        $this->remember($run, $serviceIds);
        PushInternetServicesToHaloJob::dispatch($serviceIds, $force, $run->id);
        $this->audit('halo', 'push-subscriptions', ['services' => $serviceIds === null ? 'all' : count($serviceIds)]);

        return $run;
    }

    /**
     * Automatic trigger (order, client assigned, plan / price / status change): one delayed job per service per
     * debounce window. The job reads the service when it runs, so every change inside the window is covered. No
     * SyncRun is created (the service page shows the result). Does nothing while billing sync is off.
     */
    public function queueInternetServiceBilling(InternetService $service): bool
    {
        if (! $service->client_id || ! HaloPsaClient::isConfigured() || ! \App\Services\Halo\HaloBillingSettings::fromSettings()->enabled()) {
            return false;
        }

        $window = max(5, (int) config('integrations.halo.subscription_debounce_seconds', 30));
        if (! \Illuminate\Support\Facades\Cache::add('halo-sub-debounce:' . $service->id, 1, $window)) {
            return false;
        }

        PushInternetServicesToHaloJob::dispatch([$service->id], false)->delay(now()->addSeconds($window));

        return true;
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
     * Push internet services / hosting services / SSL certificates to HaloPSA or IT Glue as assets (live panel scope
     * "{provider}/push-internet" ...). Same de-duplication as the domain pushes: with $ids = null every record that
     * has a client is pushed and a running run is returned instead of a duplicate. The caller checks the mapping is
     * enabled first (assetMappingProblem()); the job re-checks.
     *
     * @param int[]|null $ids
     */
    public function pushAssets(string $provider, string $entity, ?array $ids = null, ?int $clientId = null, bool $force = true, ?int $userId = null): SyncRun
    {
        $scope = AssetEntity::scope($entity, $provider);

        if ($ids === null && ($run = SyncRun::active($provider, $scope, $clientId))) {
            return $run;
        }
        if ($ids !== null && ($run = $this->identicalActiveRun($provider, $scope, $clientId, $ids))) {
            return $run;
        }

        $run = SyncRun::queue($provider, $scope, $clientId, $userId);
        $this->remember($run, $ids);
        SyncEntityAssetsJob::dispatch($provider, $entity, $ids, $clientId, $force, $run->id);
        $this->audit($provider, 'push-' . $entity, ['items' => $ids === null ? 'all' : count($ids)]);

        return $run;
    }

    /** Why an entity cannot be pushed right now (null = it can): not enabled, no asset type, or not configured. */
    public function assetMappingProblem(string $provider, string $entity): ?string
    {
        $halo = $provider === 'halo';
        if ($halo && ($problem = HaloPsaClient::configurationProblem())) {
            return $problem;
        }
        if (! $halo && ! ItGlueClient::isConfigured()) {
            return 'IT Glue is not configured. Save the API key first.';
        }

        $mapping = $halo ? HaloAssetMapping::forEntity($entity) : ItGlueAssetMapping::forEntity($entity);
        $where = 'Settings → ' . ($halo ? 'HaloPSA' : 'IT Glue') . ' → Assets';
        if (! $mapping->enabled) {
            return AssetEntity::label($entity) . ' asset sync is turned off. Enable it in ' . $where . '.';
        }
        if ($entity !== AssetEntity::DOMAIN && ! $mapping->typeId) {
            return 'No asset type is mapped for ' . AssetEntity::plural($entity) . '. Choose one in ' . $where . '.';
        }

        return null;
    }

    /** Non-domain entities whose mapping is enabled with a type, for one provider (scheduled runs, Sync tab cards). */
    public function enabledAssetEntities(string $provider): array
    {
        $out = [];
        foreach (AssetEntity::KEYS as $entity) {
            if ($entity === AssetEntity::DOMAIN) {
                continue;
            }
            $mapping = $provider === 'halo' ? HaloAssetMapping::forEntity($entity) : ItGlueAssetMapping::forEntity($entity);
            if ($mapping->enabled && $mapping->typeId) {
                $out[] = $entity;
            }
        }

        return $out;
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
        $jobs = [new SyncHaloClientsJob(), new SyncHaloAssetsJob()];
        if (HaloAssetMapping::forEntity(AssetEntity::DOMAIN)->enabled) {
            $jobs[] = new SyncHaloDomainsJob(null, null, false);
        }
        foreach ($this->enabledAssetEntities('halo') as $entity) {
            $jobs[] = new SyncEntityAssetsJob('halo', $entity, null, null, false);
        }

        Bus::chain($jobs)->dispatch();
    }

    /** Scheduled "sync-itglue": pull organisations, then push changed domains. */
    public function scheduleItGluePipeline(): void
    {
        $jobs = [new SyncItGlueOrganizationsJob()];
        if (ItGlueAssetMapping::forEntity(AssetEntity::DOMAIN)->enabled) {
            $jobs[] = new SyncItGlueConfigurationsJob(null, null, false);
        }
        foreach ($this->enabledAssetEntities('itglue') as $entity) {
            $jobs[] = new SyncEntityAssetsJob('itglue', $entity, null, null, false);
        }

        Bus::chain($jobs)->dispatch();
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
            'vocus' => [
                'configured' => VocusWsmClient::isConfigured(),
                'configuration_problem' => VocusWsmClient::configurationProblem(),
                'last_synced_at' => $at('vocus', 'services')?->toIso8601String(),
                'last_synced_human' => $at('vocus', 'services')?->diffForHumans(),
                'active' => $active('vocus', 'services'),
                'latest' => $latest('vocus', 'services'),
            ],
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
                    'subscriptions' => $active('halo', 'subscriptions'),
                ] + $this->entityRuns('halo', $active),
                'latest_by_scope' => [
                    'clients' => $latest('halo', 'clients'),
                    'assets' => $latest('halo', 'assets'),
                    'domains' => $latest('halo', 'domains'),
                    'subscriptions' => $latest('halo', 'subscriptions'),
                ] + $this->entityRuns('halo', $latest),
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
                ] + $this->entityRuns('itglue', $active),
                'latest_by_scope' => [
                    'organizations' => $latest('itglue', 'organizations'),
                    'configurations' => $latest('itglue', 'configurations'),
                ] + $this->entityRuns('itglue', $latest),
            ],
        ];
    }

    /** @return array<string,mixed> push-internet / push-hosting / push-ssl => run status for one provider */
    private function entityRuns(string $provider, callable $lookup): array
    {
        $out = [];
        foreach ([AssetEntity::INTERNET, AssetEntity::HOSTING, AssetEntity::SSL] as $entity) {
            $scope = AssetEntity::scope($entity, $provider);
            $out[$scope] = $lookup($provider, $scope);
        }

        return $out;
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
