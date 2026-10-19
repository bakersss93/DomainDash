<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Halo\HaloPsaClient;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Sync\HaloDomainPusher;
use App\Services\Sync\SyncItemFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a chunk of domains to Halo. Each item is isolated: one failure is
 * recorded on the domain and counted in the SyncRun, the rest continue. A
 * rate limit / outage re-queues only the not-yet-processed remainder, so
 * run counters are never double counted.
 */
class PushDomainsToHaloJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public array $domainIds,
        public ?int $runId = null,
        public bool $force = false,
        public string $mode = 'asset',
        public int $round = 1,
    ) {
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function middleware(): array
    {
        return [
            new RateLimited('halo-api'),
            (new WithoutOverlapping('halo-push-' . md5(json_encode($this->domainIds))))->releaseAfter(60)->expireAfter(1800),
        ];
    }

    public function handle(HaloDomainPusher $pusher): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $remaining = array_values($this->domainIds);

        try {
            $halo = app(HaloPsaClient::class);
            $typeId = $this->mode === HaloDomainPusher::MODE_ASSET ? $halo->getDomainAssetTypeId() : 0;
        } catch (\Throwable $e) {
            if ($e instanceof IntegrationApiException && $e->retryable) {
                $this->requeue($remaining, $run, $e);

                return;
            }
            $this->failAll($remaining, $run, $e->getMessage());

            return;
        }

        if ($this->mode === HaloDomainPusher::MODE_ASSET && ! $typeId) {
            $this->failAll($remaining, $run, 'No "Domain" asset type exists in HaloPSA.');

            return;
        }

        while ($remaining !== []) {
            $id = $remaining[0];
            $domain = Domain::with('client')->find($id);

            try {
                if (! $domain) {
                    throw new SyncItemFailed('Domain not found');
                }

                $outcome = $pusher->push($domain, $halo, (int) $typeId, $this->force, $this->mode);
                $run?->advance(true, $outcome === 'skipped');
            } catch (IntegrationApiException $e) {
                if ($e->retryable) {
                    $this->requeue($remaining, $run, $e);

                    return;
                }
                $this->recordFailure($domain, $e->getMessage());
                $run?->advance(false);
            } catch (\Throwable $e) {
                if (! $e instanceof SyncItemFailed) {
                    Log::error('Halo domain push failed', ['domain_id' => $id, 'error' => $e->getMessage()]);
                }
                $this->recordFailure($domain, $e->getMessage());
                $run?->advance(false);
            }

            array_shift($remaining);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->failAll($this->domainIds, $this->runId ? SyncRun::find($this->runId) : null, $e->getMessage());
    }

    private function requeue(array $remaining, ?SyncRun $run, IntegrationApiException $e): void
    {
        if ($this->round >= 8) {
            $this->failAll($remaining, $run, 'Gave up after repeated HaloPSA errors: ' . $e->getMessage());

            return;
        }

        $delay = max(15, $e->retryAfter ?? (30 * (2 ** ($this->round - 1))));

        static::dispatch($remaining, $this->runId, $this->force, $this->mode, $this->round + 1)
            ->delay(now()->addSeconds(min($delay, 900)));
    }

    private function failAll(array $ids, ?SyncRun $run, string $message): void
    {
        foreach ($ids as $id) {
            $this->recordFailure(Domain::find($id), $message);
            $run?->advance(false);
        }
    }

    private function recordFailure(?Domain $domain, string $message): void
    {
        $domain?->forceFill(['halo_sync_error' => mb_substr($message, 0, 1000)])->save();
    }
}
