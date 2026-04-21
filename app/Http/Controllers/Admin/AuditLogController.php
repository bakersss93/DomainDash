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
        $functionOptions = AuditLog::query()
            ->whereNotNull('context')
            ->get(['context'])
            ->pluck('context')
            ->map(fn ($context) => $context['function'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $serviceOptions = AuditLog::query()
            ->whereNotNull('context')
            ->get(['context'])
            ->pluck('context')
            ->map(fn ($context) => $context['service'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->values();
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

    private function estimateAuditDatabaseSizeBytes(): int
    {
        return (int) AuditLog::query()->get()->reduce(function (int $carry, AuditLog $log): int {
            $row = json_encode([
                $log->id,
                $log->user_id,
                $log->user_email,
                $log->action,
                $log->auditable_type,
                $log->auditable_id,
                $log->description,
                $log->old_values,
                $log->new_values,
                $log->context,
                $log->ip_address,
                $log->user_agent,
                $log->created_at,
            ]);

            return $carry + strlen((string) $row);
        }, 0);
    }

    private function logFileSizeBytes(): int
    {
        $path = storage_path('logs/laravel.log');

        return is_file($path) ? (int) filesize($path) : 0;
    }
}
