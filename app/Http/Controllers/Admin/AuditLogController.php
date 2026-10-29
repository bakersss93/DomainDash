<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'action' => (string) $request->query('action', ''),
            'user_id' => (string) $request->query('user_id', ''),
            'client_id' => (string) $request->query('client_id', ''),
            'function' => trim((string) $request->query('function', '')),
            'service' => trim((string) $request->query('service', '')),
            'failed_only' => (bool) $request->boolean('failed_only'),
        ];

        $query = AuditLog::query()->with('user')->latest();

        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if ($filters['user_id'] !== '') {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['client_id'] !== '') {
            $query->where(function ($builder) use ($filters) {
                $builder->where('context->client_id', (int) $filters['client_id'])
                    ->orWhere('old_values->client_id', (int) $filters['client_id'])
                    ->orWhere('new_values->client_id', (int) $filters['client_id']);
            });
        }

        if ($filters['function'] !== '') {
            $query->where(function ($builder) use ($filters) {
                $builder->where('context->function', $filters['function'])
                    ->orWhere('description', 'like', '%'.$filters['function'].'%');
            });
        }

        if ($filters['service'] !== '') {
            $query->where(function ($builder) use ($filters) {
                $builder->where('context->service', $filters['service'])
                    ->orWhere('description', 'like', '%'.$filters['service'].'%');
            });
        }

        if ($filters['failed_only']) {
            $query->where(function ($builder) {
                $builder->where('action', 'like', '%failed%')
                    ->orWhere('description', 'like', '%failed%');
            });
        }

        $logs = $query->paginate(50)->withQueryString();
        $actionOptions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');
        $functionOptions = $this->distinctContextValues('function');
        $serviceOptions = $this->distinctContextValues('service');
        $users = User::query()->orderBy('name')->get(['id', 'name', 'email']);
        $clients = Client::query()->orderBy('business_name')->get(['id', 'business_name']);

        $auditSettings = Setting::get('audit', [
            'retention_days' => 90,
        ]);
        $retentionDays = (int) ($auditSettings['retention_days'] ?? 90);

        $dbBytes = $this->estimateAuditDatabaseSizeBytes();
        $logFileBytes = $this->logFileSizeBytes();

        return view('admin.audit.index', compact(
            'logs',
            'filters',
            'actionOptions',
            'functionOptions',
            'serviceOptions',
            'users',
            'clients',
            'retentionDays',
            'dbBytes',
            'logFileBytes'
        ));
    }

    public function updateRetention(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'retention_days' => 'required|integer|min:1|max:3650',
            'prune_now' => 'nullable|boolean',
        ]);

        Setting::put('audit', [
            'retention_days' => (int) $validated['retention_days'],
        ]);

        $deleted = 0;
        if ($request->boolean('prune_now')) {
            $deleted = AuditLogger::pruneOlderThanDays((int) $validated['retention_days']);
        }

        return back()->with('status', $deleted > 0
            ? "Audit retention saved. Pruned {$deleted} older log entries."
            : 'Audit retention saved.');
    }

    /**
     * Distinct values of one context JSON key, computed in the database (the
     * audit table can hold hundreds of thousands of rows) and cached briefly.
     */
    private function distinctContextValues(string $key): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\Cache::remember(
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
     * Size of the audit table, computed by the database rather than by loading
     * every row into PHP (which exhausts memory on large tables).
     */
    private function estimateAuditDatabaseSizeBytes(): int
    {
        return (int) \Illuminate\Support\Facades\Cache::remember('audit:db-size', now()->addMinutes(10), function (): int {
            $connection = AuditLog::query()->getConnection();
            $table = $connection->getTablePrefix() . (new AuditLog())->getTable();

            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $row = $connection->selectOne(
                    'SELECT data_length + index_length AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                    [$table]
                );

                if ($row !== null && $row->bytes !== null) {
                    return (int) $row->bytes;
                }
            }

            // Portable estimate: stored text lengths plus a fixed per-row overhead.
            return (int) AuditLog::query()->toBase()->selectRaw(
                'COALESCE(SUM(200 + COALESCE(LENGTH(description), 0) + COALESCE(LENGTH(old_values), 0)'
                . ' + COALESCE(LENGTH(new_values), 0) + COALESCE(LENGTH(context), 0) + COALESCE(LENGTH(user_agent), 0)), 0) AS bytes'
            )->value('bytes');
        });
    }

    private function logFileSizeBytes(): int
    {
        $path = storage_path('logs/laravel.log');

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
