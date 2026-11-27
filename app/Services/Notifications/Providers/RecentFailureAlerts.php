<?php

namespace App\Services\Notifications\Providers;

use App\Models\IntegrationRequest;
use App\Models\SyncRun;
use App\Services\Notifications\AlertProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sync runs whose latest outcome (last 24h) is failed, and outbound integration errors in the last 24h. */
class RecentFailureAlerts implements AlertProvider
{
    public function alerts(): array
    {
        $out = [];
        $since = now()->subDay();

        try {
            if (Schema::hasTable('sync_runs')) {
                $latest = SyncRun::query()
                    ->where('created_at', '>=', $since)
                    ->whereIn('status', [SyncRun::OK, SyncRun::PARTIAL, SyncRun::FAILED])
                    ->orderBy('id')
                    ->get()
                    ->groupBy(fn ($r) => $r->provider . '/' . $r->scope . '/' . ($r->client_id ?? 0))
                    ->map(fn ($g) => $g->last())
                    ->filter(fn ($r) => $r->status === SyncRun::FAILED)
                    ->groupBy('provider');

                foreach ($latest as $provider => $runs) {
                    $first = $runs->sortByDesc('id')->first();
                    $out[] = [
                        'key' => "sync-failed:{$provider}",
                        'severity' => 'error',
                        'title' => ucfirst((string) $provider) . ' sync failed',
                        'text' => $runs->count() > 1
                            ? $runs->count() . ' sync runs failed in the last 24 hours. Latest: ' . \App\Support\SyncErrorSanitizer::clean((string) $first->error, 160)
                            : \App\Support\SyncErrorSanitizer::clean((string) ($first->error ?: 'The run failed.'), 200),
                        'url' => route('admin.audit.index'),
                        'at' => ($first->finished_at ?? $first->updated_at)?->toIso8601String(),
                    ];
                }
            }

            if (Schema::hasTable('integration_requests')) {
                $errors = IntegrationRequest::query()
                    ->where('direction', 'outbound')
                    ->where('is_error', true)
                    ->where('created_at', '>=', $since)
                    ->select('provider', DB::raw('count(*) as n'), DB::raw('max(created_at) as last_at'))
                    ->groupBy('provider')
                    ->get();

                foreach ($errors as $row) {
                    if ((int) $row->n < 3) {
                        continue;
                    }
                    $out[] = [
                        'key' => "integration-errors:{$row->provider}",
                        'severity' => 'warning',
                        'title' => ucfirst((string) $row->provider) . ' request errors',
                        'text' => "{$row->n} outbound requests to {$row->provider} failed in the last 24 hours.",
                        'url' => route('admin.audit.index'),
                        'at' => $row->last_at ? \Illuminate\Support\Carbon::parse($row->last_at)->toIso8601String() : null,
                    ];
                }
            }
        } catch (\Throwable) {
            // Alerts must never break a page.
        }

        return $out;
    }
}
