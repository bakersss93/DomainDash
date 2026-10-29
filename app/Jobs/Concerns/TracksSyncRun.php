<?php

namespace App\Jobs\Concerns;

use App\Models\SyncRun;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Support\SyncErrorSanitizer;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Shared behaviour for the Halo / IT Glue sync jobs.
 *
 *  - a SyncRun row (created by the dispatcher so the UI can poll it, or lazily
 *    by the scheduler path)
 *  - every outcome is written to the run promptly: success, skipped (with the
 *    reason), failed (sanitized error). Config/auth errors never retry;
 *    429/5xx/timeouts retry a limited number of times and record
 *    "retrying: <reason>" on every attempt.
 *  - no ShouldBeUnique: the dispatcher de-duplicates by run, and
 *    WithoutOverlapping (self-expiring, releases instead of dropping) stops two
 *    executions of the same job from running together, so a duplicate dispatch
 *    can never leave an orphan "queued" run behind.
 *
 * Jobs using this trait define: uniqueId(), $timeout via timeoutKey().
 */
trait TracksSyncRun
{
    public ?int $runId = null;

    /** Releases (rate limit / overlap / transient errors) do not count as tries; time-boxed by retryUntil. */
    public int $tries = 0;

    /** The first unexpected exception fails the job (and the run) at once. */
    public int $maxExceptions = 1;

    /** A timeout fails the job (calling failed()) instead of being retried by retry_after. */
    public bool $failOnTimeout = true;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('integrations.sync.retry_minutes', 30));
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    /** Seconds this job may run (must stay below the connection's retry_after). */
    protected function configuredTimeout(string $kind): int
    {
        return (int) config("integrations.sync.timeouts.{$kind}", 240);
    }

    /** Identity of "the same work" for overlap protection. */
    abstract public function uniqueId(): string;

    /** @return array<int,object> */
    protected function syncMiddleware(?string $rateLimiter): array
    {
        $middleware = [
            (new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter($this->timeout + 60),
        ];

        if ($rateLimiter) {
            $middleware[] = new RateLimited($rateLimiter);
        }

        return $middleware;
    }

    protected function syncRun(string $provider, string $scope, ?int $clientId = null): SyncRun
    {
        if ($this->runId && ($run = SyncRun::find($this->runId))) {
            return $run;
        }

        $run = SyncRun::queue($provider, $scope, $clientId);
        $this->runId = $run->id;

        return $run;
    }

    /**
     * Handle any throwable raised by the remote work: record it on the run and
     * decide between fail-fast, limited retry and rethrow.
     */
    protected function handleFailure(SyncRun $run, \Throwable $e): void
    {
        $message = SyncErrorSanitizer::describe($e);

        if ($this->isConfigurationProblem($e)) {
            $hint = $e instanceof IntegrationApiException && $e->isAuthFailure()
                ? ' Check the credentials in Settings.'
                : '';
            $run->fail($message . $hint);

            return;
        }

        if ($e instanceof IntegrationApiException && $e->retryable) {
            $max = (int) config('integrations.sync.max_remote_attempts', 5);

            if ($this->job && $this->attempts() < $max) {
                $delay = max(10, min(300, $e->retryAfter ?? (30 * $this->attempts())));
                $run->recordRetry($message . " [attempt {$this->attempts()}/{$max}]", $delay);
                $this->release($delay);

                return;
            }

            Log::warning('Sync gave up after transient errors', ['run' => $run->id, 'error' => $message]);
            $run->fail('Gave up after repeated errors: ' . $message);

            return;
        }

        // Unexpected failure: show it now, and let the queue record it in failed_jobs.
        $run->fail($message);

        throw $e;
    }

    /** Called by the queue when the job fails for good (exception, timeout, max attempts). */
    public function failed(\Throwable $e): void
    {
        if (! $this->runId) {
            return;
        }

        $run = SyncRun::find($this->runId);
        if (! $run || $run->isFinished()) {
            return;
        }

        $name = class_basename($e);
        $message = str_contains($name, 'Timeout')
            ? 'Job timed out after ' . ($this->timeout ?? '?') . 's and was stopped.'
            : (str_contains($name, 'MaxAttempts') ? 'Job exceeded its retry limit.' : SyncErrorSanitizer::describe($e));

        $run->fail($message);
    }

    protected function isConfigurationProblem(\Throwable $e): bool
    {
        return $e instanceof IntegrationConfigurationException || ($e instanceof IntegrationApiException && $e->isAuthFailure());
    }
}
