<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
        ]);
    }

    /** A queued/running run that has not gone stale. */
    public static function active(string $provider, string $scope, ?int $clientId = null): ?self
    {
        $stale = now()->subMinutes((int) config('integrations.sync.stale_run_minutes', 120));

        return static::query()
            ->where('provider', $provider)
            ->where('scope', $scope)
            ->where('client_id', $clientId)
            ->whereIn('status', [self::QUEUED, self::RUNNING])
            ->where('updated_at', '>=', $stale)
            ->latest('id')
            ->first();
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
            'error' => $error ?? $this->error,
            'finished_at' => now(),
            'cursor_at' => $this->started_at ?? now(),
        ])->save();

        return $this;
    }

    public function fail(string $error): self
    {
        $this->forceFill([
            'status' => self::FAILED,
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function skip(string $reason): self
    {
        $this->forceFill([
            'status' => self::SKIPPED,
            'error' => $reason,
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
            'error' => $this->error,
            'finished' => $this->isFinished(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
