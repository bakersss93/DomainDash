<?php

namespace App\Services\Monitoring;

use App\Models\IntegrationRequest;
use App\Models\SyncRun;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes monitoring rows older than the retention of their category:
 *   activity (audit_logs) | outbound + inbound (integration_requests, api_access_logs) | jobs (finished sync_runs).
 * Deletes run in chunks so a large backlog never holds one long lock.
 */
class MonitoringPruner
{
    private const CHUNK = 5000;

    public function __construct(private readonly MonitoringSettings $settings)
    {
    }

    /** @return array{activity:int, outbound:int, inbound:int, jobs:int} rows deleted per category */
    public function prune(): array
    {
        $this->settings->flush();

        $deleted = [
            'activity' => AuditLogger::pruneOlderThanDays($this->settings->retentionDays('activity')),
            'outbound' => $this->chunked(
                fn () => IntegrationRequest::query()
                    ->where('direction', IntegrationRequest::OUTBOUND)
                    ->where('created_at', '<', now()->subDays($this->settings->retentionDays('outbound')))
            ),
            'inbound' => $this->chunked(
                fn () => IntegrationRequest::query()
                    ->where('direction', IntegrationRequest::INBOUND)
                    ->where('created_at', '<', now()->subDays($this->settings->retentionDays('inbound')))
            ),
            'jobs' => $this->chunked(
                fn () => SyncRun::query()
                    ->whereIn('status', [SyncRun::OK, SyncRun::PARTIAL, SyncRun::FAILED, SyncRun::SKIPPED])
                    ->where(fn ($q) => $q->where('finished_at', '<', now()->subDays($this->settings->retentionDays('jobs')))
                        ->orWhere(fn ($q2) => $q2->whereNull('finished_at')->where('created_at', '<', now()->subDays($this->settings->retentionDays('jobs')))))
            ),
        ];

        // The legacy per-request access table follows the inbound retention.
        if (Schema::hasTable('api_access_logs')) {
            $cutoff = now()->subDays($this->settings->retentionDays('inbound'));
            do {
                $n = DB::table('api_access_logs')->where('requested_at', '<', $cutoff)->limit(self::CHUNK)->delete();
                $deleted['inbound'] += $n;
            } while ($n >= self::CHUNK);
        }

        if (array_sum($deleted) > 0) {
            AuditLogger::logSystem('monitoring.pruned', 'Monitoring retention applied: ' . implode(', ', array_map(
                fn ($k, $v) => "{$k} {$v}", array_keys($deleted), $deleted
            )) . ' row(s) removed.', ['service' => 'monitoring', 'function' => 'prune', 'automated' => true], ['new_values' => $deleted]);
        }

        return $deleted;
    }

    private function chunked(\Closure $query): int
    {
        $total = 0;
        do {
            $n = $query()->limit(self::CHUNK)->delete();
            $total += $n;
        } while ($n >= self::CHUNK);

        return $total;
    }
}
