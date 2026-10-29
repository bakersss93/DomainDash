<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\SyncErrorSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncRun extends Model
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const OK = 'ok';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'provider', 'scope', 'client_id', 'status', 'total', 'done', 'failed', 'skipped',
        'cursor_at', 'triggered_by', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'cursor_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function queue(string $provider, string $scope, ?int $clientId = null, ?int $userId = null): self
    {
        return static::create([
            'provider' => $provider,
            'scope' => $scope,
            'client_id' => $clientId,
            'status' => self::QUEUED,
            'triggered_by' => $userId,
            'error' => null,
        ]);
    }

    public const ERR_NOT_PICKED_UP = 'Worker did not pick up the job (is a queue worker running? See README → Queue worker).';
    public const ERR_STALLED = 'Run stalled: no progress and no pending job (the worker was stopped or killed).';

    /**
     * A queued/running run that is genuinely alive. Orphaned / stalled runs
     * are marked failed first, so they can never block a fresh dispatch.
     */
    public static function active(string $provider, string $scope, ?int $clientId = null): ?self
    {
        static::recoverStale($provider, $scope, $clientId);

        return static::query()
            ->where('provider', $provider)
            ->where('scope', $scope)
            ->where('client_id', $clientId)
            ->whereIn('status', [self::QUEUED, self::RUNNING])
            ->latest('id')
            ->first();
    }

    /**
     * Fail stale queued/running runs (optionally limited to one provider/scope/client).
     * Returns the number of runs recovered.
     */
    public static function recoverStale(?string $provider = null, ?string $scope = null, ?int $clientId = null, bool $clientScoped = true): int
    {
        $runs = static::query()
            ->whereIn('status', [self::QUEUED, self::RUNNING])
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->when($scope, fn ($q) => $q->where('scope', $scope))
            ->when($provider && $scope && $clientScoped, fn ($q) => $q->where('client_id', $clientId))
            ->get();

        $count = 0;
        foreach ($runs as $run) {
            if ($run->recoverIfStale()) {
                $count++;
            }
        }

        return $count;
    }

    /** Mark this run failed when it is an orphan (queued) or stalled (running). */
    public function recoverIfStale(): bool
    {
        if ($this->isFinished()) {
            return false;
        }

        $reason = $this->staleReason();
        if ($reason === null) {
            return false;
        }

        $this->fail($reason);

        return true;
    }

    public function staleReason(): ?string
    {
        if ($this->isFinished()) {
            return null;
        }

        $queued = $this->status === self::QUEUED;
        $minutes = $queued
            ? (int) config('integrations.sync.queued_stale_minutes', 5)
            : (int) config('integrations.sync.running_stale_minutes', 10);
        $reference = $queued ? ($this->updated_at ?? $this->created_at) : ($this->updated_at ?? $this->started_at);

        if (! $reference || $reference->gt(now()->subMinutes($minutes))) {
            return null;
        }

        $hasJob = $this->hasPendingJob();
        if ($hasJob === true) {
            // A job (possibly delayed for a retry) still exists: not orphaned,
            // unless it is absurdly old (fallback window).
            return $reference->lt(now()->subMinutes((int) config('integrations.sync.stale_run_minutes', 120)))
                ? ($queued ? self::ERR_NOT_PICKED_UP : self::ERR_STALLED)
                : null;
        }

        if ($hasJob === null) {
            // Queue cannot be inspected: only the long fallback window applies.
            return $reference->lt(now()->subMinutes((int) config('integrations.sync.stale_run_minutes', 120)))
                ? ($queued ? self::ERR_NOT_PICKED_UP : self::ERR_STALLED)
                : null;
        }

        return $queued ? self::ERR_NOT_PICKED_UP : self::ERR_STALLED;
    }

    /**
     * Is there a row in the jobs table for this run? null = cannot tell (non-database queue).
     */
    public function hasPendingJob(): ?bool
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $connection = config('queue.connections.database.connection');
            $table = (string) config('queue.connections.database.table', 'jobs');
            $db = DB::connection($connection);

            if (! $db->getSchemaBuilder()->hasTable($table)) {
                return null;
            }

            // The run id is a public property of the serialized job command.
            $needle = 's:5:"runId";i:' . $this->id . ';';
            $found = false;
            $db->table($table)->select('payload')->orderBy('id')->chunk(200, function ($rows) use ($needle, &$found) {
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    $command = is_array($payload) ? (string) ($payload['data']['command'] ?? '') : (string) $row->payload;
                    if (str_contains($command, $needle) || str_contains((string) $row->payload, str_replace('"', '\\"', $needle))) {
                        $found = true;

                        return false;
                    }
                }
            });

            return $found;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Most recent successful (ok/partial) run, optionally for a client. */
    public static function lastSuccessful(string $provider, string $scope, ?int $clientId = null): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('scope', $scope)
            ->where('client_id', $clientId)
            ->whereIn('status', [self::OK, self::PARTIAL])
            ->latest('finished_at')
            ->first();
    }

    public static function lastSyncedAt(string $provider, string $scope, ?int $clientId = null): ?Carbon
    {
        return static::lastSuccessful($provider, $scope, $clientId)?->finished_at;
    }

    public function markRunning(?int $total = null): self
    {
        $this->forceFill([
            'status' => self::RUNNING,
            'started_at' => $this->started_at ?? now(),
            'total' => $total ?? $this->total ?? 0,
        ])->save();

        return $this;
    }

    /** Progress heartbeat for long single-job runs (bumps updated_at). */
    public function heartbeat(): void
    {
        $this->forceFill(['updated_at' => now()])->save();
    }

    public function setTotal(int $total): self
    {
        $this->forceFill(['total' => $total])->save();

        return $this;
    }

    /**
     * Count one finished item (atomic) and close the run when all are in.
     */
    public function advance(bool $success = true, bool $skipped = false): void
    {
        if ($skipped) {
            $this->increment('skipped');
        } elseif ($success) {
            $this->increment('done');
        } else {
            $this->increment('failed');
        }

        $this->refresh();

        if ($this->total > 0 && ($this->done + $this->failed + $this->skipped) >= $this->total) {
            $this->finish();
        }
    }

    public function finish(?string $error = null): self
    {
        if (in_array($this->status, [self::OK, self::PARTIAL, self::FAILED, self::SKIPPED], true) && $this->finished_at) {
            return $this;
        }

        $status = match (true) {
            $error !== null && $this->done === 0 => self::FAILED,
            $this->failed > 0 && $this->done === 0 && $this->skipped === 0 && $this->total > 0 => self::FAILED,
            $this->failed > 0 || $error !== null => self::PARTIAL,
            default => self::OK,
        };

        $this->forceFill([
            'status' => $status,
            'error' => $error !== null ? SyncErrorSanitizer::clean($error, 1000) : ($status !== self::FAILED && (str_starts_with((string) $this->error, 'retrying:') || str_starts_with((string) $this->error, 'Partly processed')) ? null : $this->error),
            'finished_at' => now(),
            'cursor_at' => $this->started_at ?? now(),
        ])->save();

        return $this;
    }

    public function fail(string $error): self
    {
        $this->forceFill([
            'status' => self::FAILED,
            'error' => SyncErrorSanitizer::clean($error, 1000),
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    /** Record a transient error while the job is being retried; the run stays running. */
    public function recordRetry(string $reason, ?int $delaySeconds = null): self
    {
        $text = 'retrying: ' . SyncErrorSanitizer::clean($reason, 400);
        if ($delaySeconds) {
            $text .= " (next attempt in {$delaySeconds}s)";
        }

        $this->forceFill(['status' => self::RUNNING, 'started_at' => $this->started_at ?? now(), 'error' => $text])->save();

        return $this;
    }

    /** Keep an error message on a still-running run (e.g. before failing its items). */
    public function noteError(string $error): self
    {
        $this->forceFill(['error' => SyncErrorSanitizer::clean($error, 1000)])->save();

        return $this;
    }

    public function skip(string $reason): self
    {
        $this->forceFill([
            'status' => self::SKIPPED,
            'error' => SyncErrorSanitizer::clean($reason, 1000),
            'started_at' => $this->started_at ?? now(),
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::OK, self::PARTIAL, self::FAILED, self::SKIPPED], true);
    }

    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'scope' => $this->scope,
            'status' => $this->status,
            'total' => $this->total,
            'done' => $this->done,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'error' => $this->error !== null ? SyncErrorSanitizer::clean($this->error, 1000) : null,
            'finished' => $this->isFinished(),
            'retrying' => $this->status === self::RUNNING && str_starts_with((string) $this->error, 'retrying:'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
