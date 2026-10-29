<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\HaloClient;
use App\Models\SyncRun;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Mirrors the Halo client list into halo_clients (all pages, one call per 100).
 */
class SyncHaloClientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $timeout = 240;

    public function __construct(?int $runId = null)
    {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'halo-clients';
    }

    public function middleware(): array
    {
        return $this->syncMiddleware('halo-api');
    }

    public function handle(): void
    {
        $run = $this->syncRun('halo', 'clients')->markRunning();

        if (! HaloPsaClient::isConfigured()) {
            $run->skip(HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.');

            return;
        }

        try {
            $halo = app(HaloPsaClient::class);
            $started = now();
            $count = 0;

            foreach ($halo->clientPages() as $rows) {
                $batch = [];
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? $row['Id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    $modified = $row['datemodified'] ?? $row['lastupdate'] ?? null;
                    $ts = $modified ? strtotime((string) $modified) : false;

                    $batch[] = [
                        'halo_id' => $id,
                        'name' => (string) ($row['name'] ?? $row['Name'] ?? 'Unknown'),
                        'abn' => self::extractAbn($row),
                        'inactive' => (bool) ($row['inactive'] ?? false),
                        'remote_updated_at' => $ts ? gmdate('Y-m-d H:i:s', $ts) : null,
                        'synced_at' => $started,
                        'created_at' => $started,
                        'updated_at' => $started,
                    ];
                }

                $run->heartbeat();
                if ($batch !== []) {
                    HaloClient::upsert($batch, ['halo_id'], ['name', 'abn', 'inactive', 'remote_updated_at', 'synced_at', 'updated_at']);
                    $count += count($batch);
                }
            }

            // Only reached when every page was read: safe to drop clients Halo no longer lists.
            HaloClient::where('synced_at', '<', $started)->delete();

            $run->forceFill(['total' => $count, 'done' => $count])->save();
            $run->finish();
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }

    public static function extractAbn(array $row): ?string
    {
        foreach ($row['customfields'] ?? $row['CustomFields'] ?? [] as $key => $field) {
            if (is_array($field)) {
                if (strtolower((string) ($field['name'] ?? '')) === 'abn' && ! empty($field['value'])) {
                    return (string) $field['value'];
                }
            } elseif (strtolower((string) $key) === 'abn' && ! empty($field)) {
                return (string) $field;
            }
        }

        $abn = $row['abn'] ?? $row['ABN'] ?? null;

        return $abn ? (string) $abn : null;
    }
}
