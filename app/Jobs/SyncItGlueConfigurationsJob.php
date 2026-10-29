<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Client;
use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Integrations\IntegrationApiException;
use App\Services\ItGlue\ItGlueClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fan-out for IT Glue: lists the existing flexible assets of each affected
 * organisation ONCE (1 call per 1000 assets instead of 1 find per domain),
 * records the ids on the domains, then queues chunked PushDomainsToItGlueJobs.
 */
class SyncItGlueConfigurationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $timeout = 120;

    public function __construct(
        public ?array $domainIds = null,
        public ?int $clientId = null,
        public bool $force = false,
        ?int $runId = null,
    ) {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'itglue-configurations-' . ($this->clientId ?? 'all') . '-' . md5(json_encode($this->domainIds));
    }

    public function middleware(): array
    {
        return $this->syncMiddleware('itglue-api');
    }

    public function handle(): void
    {
        $run = $this->syncRun('itglue', 'configurations', $this->clientId)->markRunning();

        if (! ItGlueClient::isConfigured()) {
            $run->skip('IT Glue is not configured. Set the API key in Settings → IT Glue.');

            return;
        }

        $requested = $this->domainIds !== null ? array_values(array_unique(array_map('intval', $this->domainIds))) : null;

        $domains = Domain::query()
            ->with('client:id,itglue_org_id')
            ->whereHas('client', fn ($q) => $q->whereNotNull('itglue_org_id'))
            ->when($requested !== null, fn ($q) => $q->whereIn('id', $requested))
            ->when($this->clientId, fn ($q) => $q->where('client_id', $this->clientId))
            ->get(['id', 'client_id', 'name', 'itglue_id']);

        $run->setTotal($requested !== null ? count($requested) : $domains->count());

        if ($requested !== null) {
            foreach (array_diff($requested, $domains->pluck('id')->all()) as $unlinked) {
                Domain::whereKey($unlinked)->update(['itglue_sync_error' => 'Domain is not linked to an ITGlue organization']);
                $run->advance(false);
            }
        }

        if ($domains->isEmpty()) {
            if ($run->total === 0) {
                $run->finish();
            }

            return;
        }

        try {
            $itglue = app(ItGlueClient::class);
            $typeId = $itglue->configuredFlexibleAssetTypeId();

            $chunks = [];
            $failures = [];

            // Nothing is dispatched until every org listed OK, so a rate-limit
            // release re-runs only these cheap list calls.
            foreach ($domains->groupBy(fn ($d) => (int) $d->client->itglue_org_id) as $orgId => $orgDomains) {
                try {
                    $existing = $itglue->flexibleAssetsByName($typeId, (int) $orgId);
                } catch (IntegrationApiException $e) {
                    if ($e->retryable) {
                        throw $e;
                    }
                    $failures[$orgId] = $e->getMessage();
                    continue;
                }

                foreach ($orgDomains as $domain) {
                    $remoteId = isset($existing[strtolower($domain->name)]) ? (int) $existing[strtolower($domain->name)]['id'] : null;
                    if ($remoteId !== null && (int) $domain->itglue_id !== $remoteId) {
                        Domain::whereKey($domain->id)->update(['itglue_id' => $remoteId]);
                    }
                }

                $chunks[] = $orgDomains->pluck('id')->all();
            }

            foreach ($failures as $orgId => $message) {
                foreach ($domains->filter(fn ($d) => (int) $d->client->itglue_org_id === (int) $orgId) as $domain) {
                    Domain::whereKey($domain->id)->update(['itglue_sync_error' => mb_substr($message, 0, 1000)]);
                    $run->advance(false);
                }
            }

            $size = (int) config('integrations.sync.push_chunk_size', 25);
            foreach ($chunks as $orgIds) {
                foreach (array_chunk($orgIds, $size) as $chunk) {
                    PushDomainsToItGlueJob::dispatch($chunk, $run->id, $this->force, true);
                }
            }

            $clientIds = $domains->pluck('client_id')->unique()->all();
            Client::whereIn('id', $clientIds)->update(['itglue_synced_at' => now()]);
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }
}
