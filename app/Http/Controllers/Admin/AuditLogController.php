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
use App\Services\Monitoring\VocusJourneys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin > Audit Logs (formerly "Monitoring", "Audit & Logging"): Activity, Integration Outbound / Inbound / Errors, Jobs & Schedules,
 * and the Vocus certification view (sandbox journeys, CSV and printable summary).
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

        $entityOptions = Cache::remember('audit:filter-options:entity', now()->addMinutes(10), fn () => IntegrationRequest::query()->toBase()
            ->whereNotNull('entity_type')->where('entity_type', '!=', '')->distinct()->orderBy('entity_type')->limit(12)->pluck('entity_type')
            ->mapWithKeys(fn ($t) => [$t => ucfirst(str_replace('_', ' ', (string) $t))])->all());

        $providers = collect(IntegrationRequest::PROVIDERS)->except('api')->all();
        $tabs = [
            'activity' => ['label' => 'Activity', 'providers' => $serviceOptions->mapWithKeys(fn ($s) => [$s => MonitoringFeed::moduleLabel($s)])->all(), 'providerLabel' => 'Module', 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed'], 'hint' => 'What people changed: who did what, with old and new values. Failures are always shown.', 'toggle' => ['key' => 'system', 'label' => 'Show system activity']],
            'outbound' => ['label' => 'Integration Outbound', 'providers' => $providers, 'providerLabel' => 'Provider', 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed'], 'entities' => $entityOptions, 'group' => true, 'hint' => 'Requests DomainDash sent to HaloPSA, IT Glue, Synergy Wholesale, Vocus and IP2WHOIS. Level: ' . $monitoring['outbound_level'] . ' (Vocus calls are always recorded). Repeated identical requests are grouped.'],
            'inbound' => ['label' => 'Integration Inbound', 'providers' => [], 'providerLabel' => null, 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed'], 'entities' => [], 'group' => true, 'hint' => 'API requests received with an API key (for example from an automation platform). Level: ' . $monitoring['inbound_level'] . '.'],
            'errors' => ['label' => 'Integration Errors', 'providers' => $providers, 'providerLabel' => 'Module', 'statuses' => [], 'entities' => $entityOptions, 'hint' => 'Failed integration requests and records whose last sync failed.'],
            'jobs' => ['label' => 'Jobs & Schedules', 'providers' => collect($providers)->only(['halo', 'itglue', 'vocus'])->all(), 'providerLabel' => 'Provider', 'statuses' => ['ok' => 'Succeeded', 'failed' => 'Failed or partial', 'active' => 'Queued or running'], 'hint' => 'Background sync runs (scheduled and manual) with their item counts.'],
        ];

        $vocusUrl = route('admin.audit.vocus');

        return view('admin.audit.index', compact(
            'vocusUrl',
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
            'entity' => (string) $request->query('entity', ''),
            'debug' => $request->boolean('debug'),
            'system' => $request->boolean('system'),
            // Repeated identical requests (polling) are folded into one row unless group=0.
            'group' => $request->query('group', '1') !== '0',
            'fp' => $this->decodeFingerprint($request->query('fp')),
        ];

        return response()->json($this->feed->page($tab, $filters, (int) $request->query('page', 1)));
    }

    /** Folded-row fingerprint sent back by the page when a group is expanded (equality filters only, validated by the feed). */
    private function decodeFingerprint(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '' || strlen($raw) > 2000) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** "Find by transaction ID / service / domain": which logs mention this text. */
    public function find(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json($this->feed->find((string) $request->query('q', '')));
    }

    // ------------------------------------------------------------------
    // Vocus certification (sandbox transaction journeys)
    // ------------------------------------------------------------------

    private function vocusFilters(Request $request): array
    {
        $env = $request->query('environment') === 'production' ? 'production' : 'sandbox';
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        return [
            'environment' => $env,
            'from' => strtotime($from) ? date('Y-m-d', strtotime($from)) : '',
            'to' => strtotime($to) ? date('Y-m-d', strtotime($to)) : '',
        ];
    }

    public function vocus(Request $request, VocusJourneys $journeys)
    {
        $this->ensureAdmin($request);
        $filters = $this->vocusFilters($request);

        return view('admin.audit.vocus', [
            'data' => $journeys->build($filters),
            'filters' => $filters,
            'checklist' => $journeys->checklist($filters['environment']),
        ]);
    }

    public function vocusCsv(Request $request, VocusJourneys $journeys)
    {
        $this->ensureAdmin($request);
        $filters = $this->vocusFilters($request);
        $data = $journeys->build($filters);

        AuditLogger::logSystem('monitoring.vocus_exported', "Exported Vocus {$filters['environment']} transactions to CSV.", [
            'service' => 'monitoring', 'function' => 'vocus-export', 'reference' => 'vocus:' . $filters['environment'],
        ]);

        $neutralise = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
        $rows = $journeys->csvRows($data);

        return response()->streamDownload(function () use ($rows, $neutralise) {
            $out = fopen('php://output', 'w');
            fputcsv($out, VocusJourneys::CSV_HEADER);
            foreach ($rows as $row) {
                fputcsv($out, array_map($neutralise, $row));
            }
            fclose($out);
        }, 'vocus-' . $filters['environment'] . '-transactions-' . now()->format('Ymd-Hi') . '.csv', [
            'Content-Type' => 'text/csv; charset=utf-8', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function vocusSummary(Request $request, VocusJourneys $journeys)
    {
        $this->ensureAdmin($request);
        $filters = $this->vocusFilters($request);

        AuditLogger::logSystem('monitoring.vocus_exported', "Opened the printable Vocus {$filters['environment']} transaction summary.", [
            'service' => 'monitoring', 'function' => 'vocus-summary', 'reference' => 'vocus:' . $filters['environment'],
        ]);

        return response()->view('admin.audit.vocus-summary', [
            'data' => $journeys->build($filters),
            'filters' => $filters,
            'generatedAt' => now(),
        ], 200, ['X-Content-Type-Options' => 'nosniff']);
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
            // Security events: floor of 365 days (config monitoring.security_retention_floor_days).
            'retention_days_security' => ['nullable', 'integer', 'min:' . (int) config('monitoring.security_retention_floor_days', 365), 'max:3650'],
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
            'retention_days_security' => isset($validated['retention_days_security']) ? (int) $validated['retention_days_security'] : null,
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

        AuditLogger::logSystem('monitoring.settings', 'Changed Audit Logs settings.', ['service' => 'monitoring', 'function' => 'settings'], [
            'old_values' => array_intersect_key($current, $next),
            'new_values' => $next,
        ]);

        $deleted = $request->boolean('prune_now') ? array_sum(app(MonitoringPruner::class)->prune()) : 0;

        return back()->with('status', $deleted > 0
            ? "Monitoring settings saved. Pruned {$deleted} older log entries."
            : 'Audit Logs settings saved.');
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
