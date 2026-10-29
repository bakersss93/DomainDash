<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncRun;
use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fan-out: splits the domains to push to Halo into chunks, each handled by a
 * PushDomainsToHaloJob. Returns immediately after dispatching.
 */
class SyncHaloDomainsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksSyncRun;

    public int $timeout = 120;

    public function __construct(
        public ?array $domainIds = null,
        public ?int $clientId = null,
        public bool $force = false,
        public string $mode = 'asset',
        ?int $runId = null,
    ) {
        $this->runId = $runId;
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function uniqueId(): string
    {
        return 'halo-domains-' . ($this->clientId ?? 'all') . '-' . md5(json_encode($this->domainIds)) . '-' . $this->mode;
    }

    public function middleware(): array
    {
        return $this->syncMiddleware(null);
    }

    public function handle(): void
    {
        $run = $this->syncRun('halo', 'domains', $this->clientId)->markRunning();

        if (! HaloPsaClient::isConfigured()) {
            $run->skip(HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.');

            return;
        }

        try {
            $requested = $this->domainIds !== null ? array_values(array_unique(array_map('intval', $this->domainIds))) : null;

            $eligible = Domain::query()
                ->whereNotNull('client_id')
                ->when($requested !== null, fn ($q) => $q->whereIn('id', $requested))
                ->when($this->clientId, fn ($q) => $q->where('client_id', $this->clientId))
                ->pluck('id')
                ->all();

            $total = $requested !== null ? count($requested) : count($eligible);
            $run->setTotal($total);

            if ($requested !== null) {
                foreach (array_diff($requested, $eligible) as $missing) {
                    Domain::whereKey($missing)->update(['halo_sync_error' => 'No client assigned']);
                    $run->advance(false);
                }
            }

            if ($eligible === []) {
                $run->refresh();
                if ($total === 0) {
                    $run->finish();
                }

                return;
            }

            foreach (array_chunk($eligible, (int) config('integrations.sync.push_chunk_size', 25)) as $chunk) {
                PushDomainsToHaloJob::dispatch($chunk, $run->id, $this->force, $this->mode);
            }
        } catch (\Throwable $e) {
            $this->handleFailure($run, $e);
        }
    }
}
