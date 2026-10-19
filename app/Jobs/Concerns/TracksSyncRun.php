<?php

namespace App\Jobs\Concerns;

use App\Models\SyncRun;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;

/**
 * Shared behaviour for the Halo / IT Glue sync jobs: a SyncRun row (created by
 * the dispatcher so the UI can poll it, or lazily by the scheduler path) and
 * rate-limit aware retry.
 */
trait TracksSyncRun
{
    public ?int $runId = null;

    /** Retry until this long after first dispatch rather than a fixed count. */
    public int $tries = 0;

    public int $maxExceptions = 5;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function backoff(): array
    {
        return [10, 60, 300];
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
     * Retryable remote failure (429 / 5xx / timeout): put the job back for
     * Retry-After (or a back-off) instead of burning an attempt; everything
     * else is rethrown.
     */
    protected function releaseOrThrow(\Throwable $e): void
    {
        if (
            $e instanceof IntegrationApiException
            && $e->retryable
            && $this->job
            && $this->attempts() < 12
        ) {
            $this->release(max(10, $e->retryAfter ?? (30 * $this->attempts())));

            return;
        }

        throw $e;
    }

    protected function isConfigurationProblem(\Throwable $e): bool
    {
        return $e instanceof IntegrationConfigurationException || ($e instanceof IntegrationApiException && $e->isAuthFailure());
    }
}
