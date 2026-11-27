<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HaloAsset;
use App\Models\SyncRun;
use App\Services\Halo\HaloPsaClient;
use App\Support\DomainNameExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Mirrors Halo "Domain" assets into halo_assets and links domains.halo_asset_id.
 *
 * Global run: ONE paged, server-filtered call stream for all clients
 * (ceil(assets/100) requests), incremental after the first run. Per-client
 * run: the same filtered by client_id (used by "Refresh now").
 */
class SyncHaloAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $timeout = 240;

    public function __construct(
        public ?int $clientId = null,
        public bool $full = false,
        ?int $runId = null,
    ) {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'halo-assets-' . ($this->clientId ?? 'all');
    }

    public function middleware(): array
    {
        return $this->syncMiddleware('halo-api');
    }

    public function handle(): void
    {
        $run = $this->syncRun('halo', 'assets', $this->clientId)->markRunning();

        if (! HaloPsaClient::isConfigured()) {
            $run->skip(HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.');

            return;
        }

        $haloClientRef = null;
        $dashClient = null;
        if ($this->clientId) {
            $dashClient = Client::find($this->clientId);
            $haloClientRef = $dashClient?->halopsa_reference ? (int) $dashClient->halopsa_reference : null;
            if (! $haloClientRef) {
                $run->skip('Client is not linked to HaloPSA.');

                return;
            }
        }

        try {
            $halo = app(HaloPsaClient::class);

            $typeId = $halo->getDomainAssetTypeId();
            if (! $typeId) {
                $run->fail('No HaloPSA asset type is selected and none named "Domain" exists. Choose one in Settings → HaloPSA → Domain asset type.');

                return;
            }

            $since = $this->incrementalSince();
            $started = now();
            $count = 0;

            foreach ($halo->assetPages($haloClientRef, $typeId, $since) as $rows) {
                $count += $this->storePage($rows, $haloClientRef, $started);
                $run->heartbeat();
            }

            // A complete (non-incremental) listing is authoritative: forget assets Halo no longer has.
            if ($since === null) {
                $this->pruneMissing($started, $haloClientRef);
            }

            if ($dashClient) {
                $dashClient->forceFill(['halo_synced_at' => now()])->save();
            } else {
                Client::whereNotNull('halopsa_reference')->update(['halo_synced_at' => now()]);
            }

            $run->forceFill(['total' => $count, 'done' => $count])->save();
            $run->finish();
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }

    /** Global, non-forced runs only ask Halo for what changed since the last good run. */
    private function incrementalSince(): ?string
    {
        if ($this->full || $this->clientId !== null) {
            return null;
        }

        $last = SyncRun::lastSuccessful('halo', 'assets', null);
        if (! $last || ! $last->cursor_at) {
            return null;
        }

        return $last->cursor_at->copy()->utc()->subMinutes(5)->format('Y-m-d\TH:i:s');
    }

    private function storePage(array $rows, ?int $haloClientRef, $syncedAt): int
    {
        $mirror = [];
        $byName = [];

        foreach ($rows as $row) {
            $asset = HaloPsaClient::normalizeAsset($row);
            if ($asset['halo_id'] <= 0) {
                continue;
            }
            if ($haloClientRef && ! $asset['halo_client_id']) {
                $asset['halo_client_id'] = $haloClientRef;
            }

            $mirror[] = $asset + ['synced_at' => $syncedAt, 'created_at' => $syncedAt, 'updated_at' => $syncedAt];

            if ($domainName = DomainNameExtractor::fromAsset($row)) {
                $byName[$domainName] = $asset;
            }
        }

        if ($mirror === []) {
            return 0;
        }

        HaloAsset::upsert($mirror, ['halo_id'], [
            'halo_client_id', 'assettype_id', 'assettype_name', 'name', 'inventory_number',
            'remote_updated_at', 'synced_at', 'updated_at',
        ]);

        if ($byName !== []) {
            $clientMap = Client::query()
                ->whereIn('halopsa_reference', array_values(array_unique(array_map(
                    fn ($a) => (string) $a['halo_client_id'],
                    $byName
                ))))
                ->pluck('id', 'halopsa_reference');

            foreach (Domain::whereIn('name', array_keys($byName))->get() as $domain) {
                $asset = $byName[$domain->name] ?? null;
                if (! $asset) {
                    continue;
                }

                if ((int) $domain->halo_asset_id !== $asset['halo_id']) {
                    $domain->halo_asset_id = $asset['halo_id'];
                }
                if (! $domain->client_id && isset($clientMap[(string) $asset['halo_client_id']])) {
                    $domain->client_id = $clientMap[(string) $asset['halo_client_id']];
                }
                if ($domain->isDirty()) {
                    $domain->save();
                }
            }
        }

        return count($mirror);
    }

    private function pruneMissing($started, ?int $haloClientRef): void
    {
        $stale = HaloAsset::query()
            ->where('synced_at', '<', $started)
            ->when($haloClientRef, fn ($q) => $q->where('halo_client_id', $haloClientRef));

        $ids = (clone $stale)->pluck('halo_id')->all();
        if ($ids === []) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            Domain::whereIn('halo_asset_id', $chunk)->update([
                'halo_asset_id' => null,
                'halo_sync_hash' => null,
                'halo_synced_at' => null,
            ]);
            HaloAsset::whereIn('halo_id', $chunk)->delete();
        }
    }
}
