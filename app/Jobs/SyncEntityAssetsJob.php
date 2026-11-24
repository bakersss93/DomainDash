<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\SyncRun;
use App\Services\Assets\AssetEntity;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueAssetMapping;
use App\Services\ItGlue\ItGlueClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fan-out for the non-domain asset syncs (internet services, hosting services, SSL certificates) to HaloPSA or
 * IT Glue: checks the entity mapping is enabled, splits the records into chunks (one PushEntityAssetsJob each) and
 * returns. The SyncRun (provider + AssetEntity::scope) drives DDSync / the notification centre exactly like the
 * domain runs. Records without a linked client are counted as failed with a clear message.
 */
class SyncEntityAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $timeout = 120;

    public function __construct(
        public string $provider,
        public string $entity,
        public ?array $ids = null,
        public ?int $clientId = null,
        public bool $force = false,
        ?int $runId = null,
    ) {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return "{$this->provider}-assets-{$this->entity}-" . ($this->clientId ?? 'all') . '-' . md5(json_encode($this->ids));
    }

    public function middleware(): array
    {
        return $this->syncMiddleware(null);
    }

    public function handle(): void
    {
        abort_unless(in_array($this->provider, AssetEntity::PROVIDERS, true) && AssetEntity::valid($this->entity) && $this->entity !== AssetEntity::DOMAIN, 404);

        $run = $this->syncRun($this->provider, AssetEntity::scope($this->entity, $this->provider), $this->clientId)->markRunning();

        $halo = $this->provider === 'halo';
        if ($halo ? ! HaloPsaClient::isConfigured() : ! ItGlueClient::isConfigured()) {
            $run->skip($halo ? (HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.') : 'IT Glue is not configured. Set the API key in Settings → IT Glue.');

            return;
        }

        $mapping = $halo ? HaloAssetMapping::forEntity($this->entity) : ItGlueAssetMapping::forEntity($this->entity);
        if (! $mapping->enabled || ! $mapping->typeId) {
            $run->skip(AssetEntity::label($this->entity) . ' asset sync is ' . (! $mapping->enabled ? 'turned off' : 'missing an asset type') . ' in Settings → ' . ($halo ? 'HaloPSA' : 'IT Glue') . ' → Assets.');

            return;
        }

        try {
            $model = AssetEntity::model($this->entity);
            $cols = AssetEntity::columns($this->entity, $this->provider);
            $linkColumn = $halo ? 'halopsa_reference' : 'itglue_org_id';
            $requested = $this->ids !== null ? array_values(array_unique(array_map('intval', $this->ids))) : null;

            $records = $model::query()
                ->with('client:id,' . $linkColumn)
                ->whereNotNull('client_id')
                ->when($requested !== null, fn ($q) => $q->whereIn('id', $requested))
                ->when($this->clientId, fn ($q) => $q->where('client_id', $this->clientId))
                ->get(['id', 'client_id']);

            $linked = $records->filter(fn ($r) => $r->client && (string) $r->client->{$linkColumn} !== '');
            $run->setTotal($requested !== null ? count($requested) : $records->count());

            // Selected records that cannot be pushed: say why, once, on the record.
            $unlinkedIds = $requested !== null
                ? array_diff($requested, $linked->pluck('id')->all())
                : $records->diff($linked)->pluck('id')->all();
            foreach ($unlinkedIds as $id) {
                $message = ! $records->contains('id', $id)
                    ? 'No client assigned'
                    : ($halo ? 'Client not linked to HaloPSA' : 'Client not linked to an IT Glue organization');
                $model::whereKey($id)->update([$cols['error'] => $message]);
                $run->advance(false);
            }

            if ($linked->isEmpty()) {
                $run->refresh();
                if ((int) $run->total === 0) {
                    $run->finish();
                }

                return;
            }

            foreach (array_chunk($linked->pluck('id')->all(), (int) config('integrations.sync.push_chunk_size', 25)) as $chunk) {
                PushEntityAssetsJob::dispatch($this->provider, $this->entity, $chunk, $run->id, $this->force);
            }
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }
}
