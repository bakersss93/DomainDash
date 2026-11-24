<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationRequest;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Monitoring\MonitoringFeed;
use App\Services\Monitoring\MonitoringPruner;
use App\Services\Monitoring\MonitoringSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin > Monitoring (formerly "Audit & Logging"): Activity, Integration Outbound / Inbound / Errors, Jobs & Schedules.
 * Administrator-only (role middleware on the route group + MFA policy); the checks below are defence in depth.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly MonitoringFeed $feed)
    {
    }

    public function index(Request $request, MonitoringSettings $settings)
    {
        $this->ensureAdmin($request);

        $tab = MonitoringFeed::isTab((string) $request->query('tab')) ? (string) $request->query('tab') : 'activity';
        $functionOptions = $this->distinctContextValues('function');
        $serviceOptions = $this->distinctContextValues('service');
        $monitoring = $settings->all();
        $retentionDays = $monitoring['retention']['activity'];
        $dbBytes = $this->estimateTableSizeBytes((new AuditLog())->getTable(), ['description', 'old_values', 'new_values', 'context', 'user_agent']);
        $requestsBytes = $this->estimateTableSizeBytes('integration_requests', ['url', 'request_headers', 'request_body', 'response_headers', 'response_body', 'error_message']);
        $logFileBytes = $this->logFileSizeBytes();

        $providers = collect(IntegrationRequest::PROVIDERS)->except('api')->all();
        $tabs = [
            'activity' => ['label' => 'Activity', 'providers' => $serviceOptions->mapWithKeys(fn ($s) => [$s => MonitoringFeed::moduleLabel($s)])->all(), 'providerLabel' => 'Module', 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed'], 'hint' => 'Changes users and the system made: who did what, with old and new values.'],
            'outbound' => ['label' => 'Integration Outbound', 'providers' => $providers, 'providerLabel' => 'Provider', 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed'], 'hint' => 'Requests DomainDash sent to HaloPSA, IT Glue, Synergy Wholesale, Vocus and IP2WHOIS. Level: ' . $monitoring['outbound_level'] . '.'],
            'inbound' => ['label' => 'Integration Inbound', 'providers' => [], 'providerLabel' => null, 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed'], 'hint' => 'API requests received with an API key (for example from an automation platform). Level: ' . $monitoring['inbound_level'] . '.'],
            'errors' => ['label' => 'Integration Errors', 'providers' => $providers, 'providerLabel' => 'Module', 'statuses' => [], 'hint' => 'Failed integration requests and records whose last sync failed.'],
            'jobs' => ['label' => 'Jobs & Schedules', 'providers' => collect($providers)->only(['halo', 'itglue', 'vocus'])->all(), 'providerLabel' => 'Provider', 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed or partial', 'active' => 'Queued or running'], 'hint' => 'Background sync runs (scheduled and manual) with their item counts.'],
        ];

        return view('admin.audit.index', compact(
            'tab', 'tabs', 'monitoring', 'retentionDays', 'dbBytes', 'requestsBytes', 'logFileBytes', 'functionOptions', 'serviceOptions'
        ));
    }

    /** JSON list for one tab: server-side search, filters and "1-50 of N" paging. */
    public function data(Request $request, string $tab): JsonResponse
    {
        $this->ensureAdmin($request);
        abort_unless(MonitoringFeed::isTab($tab), 404);

        $filters = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 120),
            'status' => (string) $request->query('status', ''),
            'provider' => (string) $request->query('provider', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'debug' => $request->boolean('debug'),
        ];

        return response()->json($this->feed->page($tab, $filters, (int) $request->query('page', 1)));
    }

    public function show(Request $request, string $tab, string $id): JsonResponse
    {
        $this->ensureAdmin($request);
        abort_unless(MonitoringFeed::isTab($tab), 404);

        $detail = $this->feed->detail($tab, $id);
        abort_if($detail === null, 404);

        return response()->json($detail);
    }

    public function export(Request $request, string $tab, string $id)
    {
        $this->ensureAdmin($request);
        abort_unless(MonitoringFeed::isTab($tab), 404);

        $payload = $this->feed->export($tab, $id);
        abort_if($payload === null, 404);

        AuditLogger::logSystem('monitoring.entry_exported', "Exported {$tab} log entry {$id} to JSON: {$payload['title']}", [
            'service' => 'monitoring', 'function' => 'export', 'reference' => "{$tab}:{$id}",
        ]);

        $filename = 'monitoring-' . preg_replace('/[^A-Za-z0-9_-]/', '-', "{$tab}-{$id}") . '.json';
        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, $filename, ['Content-Type' => 'application/json; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, string $tab, string $id): JsonResponse
    {
        $this->ensureAdmin($request);
        abort_unless(MonitoringFeed::isTab($tab), 404);

        $result = $this->feed->delete($tab, $id);
        if ($result === null) {
            return response()->json(['message' => 'This entry cannot be deleted (the activity trail is only removed by retention) or no longer exists.'], 404);
        }

        AuditLogger::logSystem('monitoring.entry_deleted', "Deleted {$tab} log entry: {$result['summary']}", [
            'service' => 'monitoring', 'function' => 'delete', 'reference' => "{$tab}:{$id}",
        ], ['old_values' => ['entry' => $result['summary']]]);

        return response()->json(['ok' => true]);
    }

    public function updateRetention(Request $request, MonitoringSettings $settings): RedirectResponse
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'retention_days' => 'required|integer|min:1|max:3650',
            'retention_days_outbound' => 'nullable|integer|min:1|max:3650',
            'retention_days_inbound' => 'nullable|integer|min:1|max:3650',
            'retention_days_jobs' => 'nullable|integer|min:1|max:3650',
            'outbound_level' => 'nullable|in:errors,all',
            'inbound_level' => 'nullable|in:errors,all',
            'verbose' => 'nullable|boolean',
            'mask_pii' => 'nullable|boolean',
            'prune_now' => 'nullable|boolean',
        ]);

        $current = Setting::get('audit', []);
        $current = is_array($current) ? $current : [];

        $next = array_merge($current, array_filter([
            'retention_days' => (int) $validated['retention_days'],
            'retention_days_outbound' => isset($validated['retention_days_outbound']) ? (int) $validated['retention_days_outbound'] : null,
            'retention_days_inbound' => isset($validated['retention_days_inbound']) ? (int) $validated['retention_days_inbound'] : null,
            'retention_days_jobs' => isset($validated['retention_days_jobs']) ? (int) $validated['retention_days_jobs'] : null,
            'outbound_level' => $validated['outbound_level'] ?? null,
            'inbound_level' => $validated['inbound_level'] ?? null,
        ], fn ($v) => $v !== null));
        foreach (['verbose', 'mask_pii'] as $flag) {
            if ($request->has($flag)) {
                $next[$flag] = $request->boolean($flag);
            }
        }

        Setting::put('audit', $next);
        $settings->flush();

        AuditLogger::logSystem('monitoring.settings', 'Changed monitoring settings.', ['service' => 'monitoring', 'function' => 'settings'], [
            'old_values' => array_intersect_key($current, $next),
            'new_values' => $next,
        ]);

        $deleted = $request->boolean('prune_now') ? array_sum(app(MonitoringPruner::class)->prune()) : 0;

        return back()->with('status', $deleted > 0
            ? "Monitoring settings saved. Pruned {$deleted} older log entries."
            : 'Monitoring settings saved.');
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Administrator'), 403);
    }

    /**
     * Distinct values of one context JSON key, computed in the database (the
     * audit table can hold hundreds of thousands of rows) and cached briefly.
     */
    private function distinctContextValues(string $key): \Illuminate\Support\Collection
    {
        return Cache::remember(
            "audit:filter-options:{$key}",
            now()->addMinutes(10),
            fn () => AuditLog::query()
                ->toBase()
                ->whereNotNull("context->{$key}")
                ->select("context->{$key} as value")
                ->distinct()
                ->orderBy('value')
                ->limit(500)
                ->pluck('value')
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->values()
        );
    }

    /**
     * Size of a table, computed by the database rather than by loading every row into PHP.
     *
     * @param string[] $textColumns used for the portable estimate on non-MySQL drivers
     */
    private function estimateTableSizeBytes(string $table, array $textColumns): int
    {
        return (int) Cache::remember("monitoring:size:{$table}", now()->addMinutes(10), function () use ($table, $textColumns): int {
            $connection = DB::connection();
            $prefixed = $connection->getTablePrefix() . $table;

            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $row = $connection->selectOne(
                    'SELECT data_length + index_length AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                    [$prefixed]
                );

                if ($row !== null && $row->bytes !== null) {
                    return (int) $row->bytes;
                }
            }

            $sum = implode(' + ', array_map(fn ($c) => "COALESCE(LENGTH({$c}), 0)", $textColumns));

            return (int) DB::table($table)->selectRaw("COALESCE(SUM(200 + {$sum}), 0) AS bytes")->value('bytes');
        });
    }

    private function logFileSizeBytes(): int
    {
        $path = storage_path('logs/laravel.log');

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
