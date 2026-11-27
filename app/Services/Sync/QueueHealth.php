<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is anything processing the queue? Combines the scheduled heartbeat job
 * (QueueHeartbeatJob stamps the cache every minute) with the age of the oldest
 * ready job in the database queue.
 */
class QueueHealth
{
    public const HEARTBEAT_KEY = 'domaindash:queue-heartbeat';

    public static function stamp(): void
    {
        Cache::put(self::HEARTBEAT_KEY, time(), 86400);
    }

    public function status(): array
    {
        $driver = (string) config('queue.default');
        $now = time();

        $heartbeat = null;
        try {
            $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        } catch (\Throwable) {
        }
        $heartbeat = is_numeric($heartbeat) ? (int) $heartbeat : null;
        $heartbeatAge = $heartbeat ? max(0, $now - $heartbeat) : null;

        [$pending, $oldestAge, $failedJobs] = $this->databaseQueue($driver, $now);

        $stale = false;
        $message = null;

        if ($driver === 'sync') {
            // Jobs run inline: there is no worker to detect.
        } elseif ($oldestAge !== null && $oldestAge > (int) config('integrations.sync.worker_stale_after', 120)) {
            $stale = true;
            $minutes = max(1, (int) floor($oldestAge / 60));
            $message = "No queue worker is processing jobs (oldest job waiting {$minutes} min). See README → Queue worker.";
        } elseif ($heartbeatAge === null || $heartbeatAge > (int) config('integrations.sync.heartbeat_stale_after', 180)) {
            $stale = true;
            $message = $heartbeatAge === null
                ? 'No queue worker heartbeat has been seen yet. Check that the queue worker and the scheduler (cron schedule:run) are running. See README → Queue worker.'
                : 'No queue worker is processing jobs (last heartbeat ' . max(1, (int) floor($heartbeatAge / 60)) . ' min ago). See README → Queue worker.';
        }

        return [
            'ok' => ! $stale,
            'stale' => $stale,
            'message' => $message,
            'driver' => $driver,
            'heartbeat_at' => $heartbeat ? gmdate('c', $heartbeat) : null,
            'heartbeat_age_seconds' => $heartbeatAge,
            'pending_jobs' => $pending,
            'oldest_pending_job_age_seconds' => $oldestAge,
            'failed_jobs' => $failedJobs,
        ];
    }

    public function isStale(): bool
    {
        return $this->status()['stale'];
    }

    /** @return array{0:?int,1:?int,2:?int} pending count, age (s) of the oldest ready job, failed_jobs count */
    private function databaseQueue(string $driver, int $now): array
    {
        if ($driver !== 'database') {
            return [null, null, null];
        }

        try {
            $connection = config('queue.connections.database.connection');
            $table = (string) config('queue.connections.database.table', 'jobs');
            $db = DB::connection($connection);

            if (! $db->getSchemaBuilder()->hasTable($table)) {
                return [null, null, null];
            }

            $pending = (int) $db->table($table)->count();
            $oldest = $db->table($table)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->min('created_at');

            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;

            return [$pending, $oldest ? max(0, $now - (int) $oldest) : 0, $failed];
        } catch (\Throwable) {
            return [null, null, null];
        }
    }
}
