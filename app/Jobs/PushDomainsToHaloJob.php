<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Halo\HaloPsaClient;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\Sync\HaloDomainPusher;
use App\Services\Sync\SyncItemFailed;
use App\Support\SyncErrorSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a chunk of domains to Halo. Each item is isolated: one failure is
 * recorded on the domain (halo_sync_error) and counted in the SyncRun, the rest
 * continue. Transient errors / the time budget hand only the not-yet-processed
 * remainder back to the queue (a short, capped number of rounds, each recorded
 * on the run as "retrying: ..."), so counters are never double counted and a
 * dead Halo is reported in minutes, not hours.
 */
class PushDomainsToHaloJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Releases (rate limit / overlap) are not tries; retryUntil bounds the job's life. */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    /** Must stay below the connection retry_after and the worker --timeout. */
    public int $timeout = 180;

    /** Transient-error rounds before giving up (30s, 60s, 120s ...). */
    public const MAX_ROUNDS = 4;

    /** Seconds of work before the rest of the chunk goes back to the queue. */
    public ?int $budgetSeconds = null;

    public function __construct(
        public array $domainIds,
        public ?int $runId = null,
        public bool $force = false,
        public string $mode = 'asset',
        public int $round = 1,
    ) {
        $this->timeout = (int) config('integrations.sync.timeouts.push', 180);
        $this->onQueue(config('integrations.sync.queue'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('integrations.sync.retry_minutes', 30));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('halo-push-' . md5(json_encode($this->domainIds))))->releaseAfter(60)->expireAfter($this->timeout + 60),
            new RateLimited('halo-api'),
        ];
    }

    /** Execute inside a web request with a small budget (admin "Run now"). */
    public function runInline(HaloDomainPusher $pusher, int $budgetSeconds): void
    {
        $this->budgetSeconds = $budgetSeconds;
        $this->handle($pusher);
    }

    public function handle(HaloDomainPusher $pusher): void
    {
        $started = microtime(true);
        $budget = $this->budgetSeconds ?? (int) config('integrations.sync.push_budget_seconds', 120);
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $remaining = array_values($this->domainIds);

        if (! HaloPsaClient::isConfigured()) {
            $this->failAll($remaining, $run, HaloPsaClient::configurationProblem() ?? 'HaloPSA is not configured.');

            return;
        }

        try {
            $halo = app(HaloPsaClient::class);
            $typeId = $this->mode === HaloDomainPusher::MODE_ASSET ? $halo->getDomainAssetTypeId() : 0;
        } catch (\Throwable $e) {
            if ($e instanceof IntegrationApiException && $e->retryable) {
                $this->requeue($remaining, $run, $e);

                return;
            }
            $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

            return;
        }

        if ($this->mode === HaloDomainPusher::MODE_ASSET && ! $typeId) {
            $this->failAll($remaining, $run, 'No "Domain" asset type exists in HaloPSA (Halo → Config → Asset Types).');

            return;
        }

        while ($remaining !== []) {
            if ((microtime(true) - $started) >= $budget) {
                // Out of time: hand the rest back to the queue (no round penalty).
                static::dispatch($remaining, $this->runId, $this->force, $this->mode, $this->round);
                $run?->noteError('Partly processed; ' . count($remaining) . ' domain(s) continue in the background queue.');

                return;
            }

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
                if ($e->isAuthFailure()) {
                    // Credentials rejected: every remaining domain would fail the same way.
                    $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e) . ' Check the HaloPSA credentials in Settings.');

                    return;
                }
                $this->recordFailure($domain, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            } catch (IntegrationConfigurationException $e) {
                $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

                return;
            } catch (\Throwable $e) {
                if (! $e instanceof SyncItemFailed) {
                    Log::error('Halo domain push failed', ['domain_id' => $id, 'error' => SyncErrorSanitizer::describe($e)]);
                }
                $this->recordFailure($domain, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            }

            array_shift($remaining);
        }
    }

    public function failed(\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;

        $name = class_basename($e);
        $message = str_contains($name, 'Timeout')
            ? "Job timed out after {$this->timeout}s and was stopped."
            : (str_contains($name, 'MaxAttempts') ? 'Job exceeded its retry limit.' : SyncErrorSanitizer::describe($e));

        // Only fail what was not already counted (timeouts happen mid-chunk).
        $ids = $this->domainIds;
        if ($run) {
            $room = max(0, (int) $run->total - ((int) $run->done + (int) $run->failed + (int) $run->skipped));
            $ids = Domain::whereIn('id', $this->domainIds)
                ->whereNull('halo_sync_error')
                ->where(fn ($q) => $q->whereNull('halo_synced_at')->orWhere('halo_synced_at', '<', $run->created_at))
                ->pluck('id')->take($room)->all();
        }

        $this->failAll($ids, $run, $message);
    }

    private function requeue(array $remaining, ?SyncRun $run, IntegrationApiException $e): void
    {
        $message = SyncErrorSanitizer::describe($e);

        if ($this->round >= self::MAX_ROUNDS) {
            $this->failAll($remaining, $run, 'Gave up after repeated HaloPSA errors: ' . $message);

            return;
        }

        $delay = max(15, min($e->retryAfter ?? (30 * (2 ** ($this->round - 1))), 300));
        $run?->recordRetry($message . ' [round ' . $this->round . '/' . self::MAX_ROUNDS . ']', $delay);

        static::dispatch($remaining, $this->runId, $this->force, $this->mode, $this->round + 1)
            ->delay(now()->addSeconds($delay));
    }

    private function failAll(array $ids, ?SyncRun $run, string $message): void
    {
        if ($run && ! $run->isFinished()) {
            $run->noteError($message);
        }

        foreach ($ids as $id) {
            $this->recordFailure(Domain::find($id), $message);
            $run?->advance(false);
        }

        if ($run && $ids === []) {
            $run->refresh();
            if (! $run->isFinished()) {
                $run->fail($message);
            }
        }
    }

    private function recordFailure(?Domain $domain, string $message): void
    {
        $domain?->forceFill(['halo_sync_error' => mb_substr($message, 0, 1000)])->save();
    }
}
