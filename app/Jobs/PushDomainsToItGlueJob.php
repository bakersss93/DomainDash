<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Integrations\IntegrationApiException;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Sync\ItGlueDomainPusher;
use App\Services\Sync\SyncItemFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a chunk of domains to IT Glue (create / PATCH with full trait set).
 * Per-item failure isolation; rate limits re-queue only the remainder.
 */
class PushDomainsToItGlueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public array $domainIds,
        public ?int $runId = null,
        public bool $force = false,
        public bool $listed = false,
        public int $round = 1,
    ) {
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function middleware(): array
    {
        return [
            new RateLimited('itglue-api'),
            (new WithoutOverlapping('itglue-push-' . md5(json_encode($this->domainIds))))->releaseAfter(60)->expireAfter(1800),
        ];
    }

    public function handle(ItGlueDomainPusher $pusher): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $remaining = array_values($this->domainIds);

        try {
            $itglue = app(ItGlueClient::class);
        } catch (\Throwable $e) {
            $this->failAll($remaining, $run, $e->getMessage());

            return;
        }

        while ($remaining !== []) {
            $id = $remaining[0];
            $domain = Domain::with('client')->find($id);

            try {
                if (! $domain) {
                    throw new SyncItemFailed('Domain not found');
                }

                $outcome = $pusher->push($domain, $itglue, $this->force, $this->listed);
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
                    Log::error('IT Glue domain push failed', ['domain_id' => $id, 'error' => $e->getMessage()]);
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
            $this->failAll($remaining, $run, 'Gave up after repeated IT Glue errors: ' . $e->getMessage());

            return;
        }

        $delay = max(15, $e->retryAfter ?? (30 * (2 ** ($this->round - 1))));

        static::dispatch($remaining, $this->runId, $this->force, $this->listed, $this->round + 1)
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
        $domain?->forceFill(['itglue_sync_error' => mb_substr($message, 0, 1000)])->save();
    }
}
