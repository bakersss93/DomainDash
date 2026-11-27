<?php

namespace App\Services\Sync;

use App\Jobs\PushDomainsToHaloJob;
use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\AuditLogger;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Cache\RateLimiter;

/**
 * Admin fallback when no queue worker is detected: pushes the first few
 * selected domains to Halo inside the web request under a hard time budget, and
 * hands everything else to the queue. It reuses PushDomainsToHaloJob (same
 * code path as the worker) and charges the shared "halo-api" rate limiter, so
 * it cannot be used to exceed the Halo request budget.
 */
class HaloRunNow
{
    public function __construct(private RateLimiter $limiter, private QueueHealth $health)
    {
    }

    /**
     * @param int[] $domainIds
     * @return array{status:int,body:array}
     */
    public function run(array $domainIds, ?int $userId): array
    {
        if (($problem = HaloPsaClient::configurationProblem()) !== null) {
            return ['status' => 422, 'body' => ['error' => $problem]];
        }

        if (! $this->health->isStale()) {
            return ['status' => 409, 'body' => ['error' => 'A queue worker is running. Use the normal sync instead of "Run now".']];
        }

        $ids = array_values(array_unique(array_map('intval', $domainIds)));
        if ($ids === []) {
            return ['status' => 422, 'body' => ['error' => 'Select at least one domain.']];
        }

        // Rate limit: the same budget the queue workers use.
        if ($wait = $this->rateLimited()) {
            return ['status' => 429, 'body' => ['error' => "HaloPSA rate limit reached; try again in {$wait}s."], 'headers' => ['Retry-After' => $wait]];
        }

        $eligible = Domain::query()->whereNotNull('client_id')->whereIn('id', $ids)->pluck('id')->all();
        $run = SyncRun::queue('halo', 'domains', null, $userId);
        $run->markRunning(count($ids));

        foreach (array_diff($ids, $eligible) as $missing) {
            Domain::whereKey($missing)->update(['halo_sync_error' => 'No client assigned']);
            $run->advance(false);
        }

        $max = (int) config('integrations.sync.run_now_max_domains', 25);
        $now = array_slice($eligible, 0, $max);
        $later = array_slice($eligible, $max);

        // Everything beyond this request's capacity goes to the queue.
        foreach (array_chunk($later, (int) config('integrations.sync.push_chunk_size', 25)) as $chunk) {
            PushDomainsToHaloJob::dispatch($chunk, $run->id, true, HaloDomainPusher::MODE_ASSET);
        }

        if ($now !== []) {
            @set_time_limit((int) config('integrations.sync.run_now_budget_seconds', 20) + 40);
            $job = new PushDomainsToHaloJob($now, $run->id, true, HaloDomainPusher::MODE_ASSET);
            $job->runInline(app(HaloDomainPusher::class), (int) config('integrations.sync.run_now_budget_seconds', 20));
        }

        $run->refresh();

        try {
            AuditLogger::logSystem('sync.run-now', 'Halo domain sync run in-request (no worker detected).', [
                'service' => 'halo',
                'function' => 'sync-run-now',
            ], ['new_values' => ['domains' => count($ids), 'processed_inline_max' => count($now), 'handed_to_queue' => count($later), 'run_id' => $run->id, 'user_id' => $userId]]);
        } catch (\Throwable) {
        }

        return ['status' => 200, 'body' => [
            'success' => true,
            'run' => $run->toStatusArray(),
            'handed_to_queue' => count($later),
            'note' => 'Domains not finished within the time budget continue in the background and will only complete once a queue worker runs.',
        ]];
    }

    /** Seconds to wait when the shared halo-api limiter is exhausted, else null (and one hit is charged). */
    private function rateLimited(): ?int
    {
        $resolver = $this->limiter->limiter('halo-api');
        if (! $resolver) {
            return null;
        }

        $limit = $resolver(null);
        $limit = is_array($limit) ? ($limit[0] ?? null) : $limit;
        if (! $limit instanceof \Illuminate\Cache\RateLimiting\Limit) {
            return null;
        }

        // Same key derivation as Illuminate\Queue\Middleware\RateLimited.
        $key = md5('halo-api' . $limit->key);

        if ($this->limiter->tooManyAttempts($key, $limit->maxAttempts)) {
            return max(1, $this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $limit->decaySeconds);

        return null;
    }
}
