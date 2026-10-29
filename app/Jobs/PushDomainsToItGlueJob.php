<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Sync\ItGlueDomainPusher;
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
 * Pushes a chunk of domains to IT Glue (create / PATCH with full trait set).
 * Same contract as PushDomainsToHaloJob: per-item failure isolation, capped
 * transient retries recorded on the run, auth/config errors fail fast, and a
 * time budget that hands the remainder back to the queue.
 */
class PushDomainsToItGlueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout = 180;

    public const MAX_ROUNDS = 4;

    public ?int $budgetSeconds = null;

    public function __construct(
        public array $domainIds,
        public ?int $runId = null,
        public bool $force = false,
        public bool $listed = false,
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
            (new WithoutOverlapping('itglue-push-' . md5(json_encode($this->domainIds))))->releaseAfter(60)->expireAfter($this->timeout + 60),
            new RateLimited('itglue-api'),
        ];
    }

    public function handle(ItGlueDomainPusher $pusher): void
    {
        $started = microtime(true);
        $budget = $this->budgetSeconds ?? (int) config('integrations.sync.push_budget_seconds', 120);
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $remaining = array_values($this->domainIds);

        if (! ItGlueClient::isConfigured()) {
            $this->failAll($remaining, $run, 'IT Glue is not configured. Set the API key in Settings → IT Glue.');

            return;
        }

        try {
            $itglue = app(ItGlueClient::class);
        } catch (\Throwable $e) {
            $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

            return;
        }

        while ($remaining !== []) {
            if ((microtime(true) - $started) >= $budget) {
                static::dispatch($remaining, $this->runId, $this->force, $this->listed, $this->round);
                $run?->noteError('Partly processed; ' . count($remaining) . ' domain(s) continue in the background queue.');

                return;
            }

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
                if ($e->isAuthFailure()) {
                    $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e) . ' Check the IT Glue API key in Settings.');

                    return;
                }
                $this->recordFailure($domain, SyncErrorSanitizer::describe($e));
                $run?->advance(false);
            } catch (IntegrationConfigurationException $e) {
                $this->failAll($remaining, $run, SyncErrorSanitizer::describe($e));

                return;
            } catch (\Throwable $e) {
                if (! $e instanceof SyncItemFailed) {
                    Log::error('IT Glue domain push failed', ['domain_id' => $id, 'error' => SyncErrorSanitizer::describe($e)]);
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

        $ids = $this->domainIds;
        if ($run) {
            $room = max(0, (int) $run->total - ((int) $run->done + (int) $run->failed + (int) $run->skipped));
            $ids = Domain::whereIn('id', $this->domainIds)
                ->whereNull('itglue_sync_error')
                ->where(fn ($q) => $q->whereNull('itglue_synced_at')->orWhere('itglue_synced_at', '<', $run->created_at))
                ->pluck('id')->take($room)->all();
        }

        $this->failAll($ids, $run, $message);
    }

    private function requeue(array $remaining, ?SyncRun $run, IntegrationApiException $e): void
    {
        $message = SyncErrorSanitizer::describe($e);

        if ($this->round >= self::MAX_ROUNDS) {
            $this->failAll($remaining, $run, 'Gave up after repeated IT Glue errors: ' . $message);

            return;
        }

        $delay = max(15, min($e->retryAfter ?? (30 * (2 ** ($this->round - 1))), 300));
        $run?->recordRetry($message . ' [round ' . $this->round . '/' . self::MAX_ROUNDS . ']', $delay);

        static::dispatch($remaining, $this->runId, $this->force, $this->listed, $this->round + 1)
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
        $domain?->forceFill(['itglue_sync_error' => mb_substr($message, 0, 1000)])->save();
    }
}
