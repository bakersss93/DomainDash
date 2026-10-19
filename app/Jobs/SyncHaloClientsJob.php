<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\HaloClient;
use App\Models\SyncRun;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Mirrors the Halo client list into halo_clients (all pages, one call per 100).
 */
class SyncHaloClientsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $uniqueFor = 1800;

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
        return [new RateLimited('halo-api')];
    }

    public function handle(): void
    {
        $run = $this->syncRun('halo', 'clients')->markRunning();

        if (! HaloPsaClient::isConfigured()) {
            $run->skip('HaloPSA is not configured.');

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
            if ($this->isConfigurationProblem($e)) {
                $run->fail($e->getMessage());

                return;
            }

            $this->releaseOrThrow($e);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->runId) {
            SyncRun::find($this->runId)?->fail($e->getMessage());
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
