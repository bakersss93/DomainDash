<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Client;
use App\Models\ItglueOrganization;
use App\Models\SyncRun;
use App\Services\ItGlue\ItGlueClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Mirrors IT Glue organisations into itglue_organizations (1000 per request).
 * Always a full listing: the cost is ceil(orgs/1000) calls, so incremental
 * filtering (whose organisation filter syntax is unverified) is not worth it.
 */
class SyncItGlueOrganizationsJob implements ShouldQueue, ShouldBeUnique
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
        return 'itglue-organizations';
    }

    public function middleware(): array
    {
        return [new RateLimited('itglue-api')];
    }

    public function handle(): void
    {
        $run = $this->syncRun('itglue', 'organizations')->markRunning();

        if (! ItGlueClient::isConfigured()) {
            $run->skip('IT Glue is not configured.');

            return;
        }

        try {
            $itglue = app(ItGlueClient::class);
            $started = now();
            $count = 0;
            $names = [];

            foreach ($itglue->organizationPages() as $rows) {
                $batch = [];
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    $name = (string) ($row['attributes']['name'] ?? 'Unknown Organization');
                    $updated = $row['attributes']['updated-at'] ?? $row['attributes']['updated_at'] ?? null;
                    $ts = $updated ? strtotime((string) $updated) : false;

                    $names[$id] = $name;
                    $batch[] = [
                        'itglue_id' => $id,
                        'name' => $name,
                        'remote_updated_at' => $ts ? gmdate('Y-m-d H:i:s', $ts) : null,
                        'synced_at' => $started,
                        'created_at' => $started,
                        'updated_at' => $started,
                    ];
                }

                if ($batch !== []) {
                    ItglueOrganization::upsert($batch, ['itglue_id'], ['name', 'remote_updated_at', 'synced_at', 'updated_at']);
                    $count += count($batch);
                }
            }

            ItglueOrganization::where('synced_at', '<', $started)->delete();

            // Keep the cached organisation name on linked clients current.
            foreach (Client::whereNotNull('itglue_org_id')->get(['id', 'itglue_org_id', 'itglue_org_name']) as $client) {
                $name = $names[(int) $client->itglue_org_id] ?? null;
                if ($name !== null && $client->itglue_org_name !== $name) {
                    $client->forceFill(['itglue_org_name' => $name])->save();
                }
            }

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
}
