<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Number of deliberate (non generic-HTTP) events written in this process. AuditRequestActions compares it
     * before / after a request to avoid writing a second, generic row for something a controller already logged.
     */
    public static int $explicitWrites = 0;

    /**
     * Log an audit event
     *
     * @param string $action The action performed (create, update, delete, sync, etc.)
     * @param Model|string $auditable The model instance or class name
     * @param array $options Additional options (old_values, new_values, description, user_email)
     * @return AuditLog
     */
    public static function log(string $action, $auditable, array $options = []): AuditLog
    {
        if (! str_starts_with($action, 'http.')) {
            self::$explicitWrites++;
        }

        $auditableType = is_object($auditable) ? get_class($auditable) : $auditable;
        $auditableId = is_object($auditable) ? $auditable->id : ($options['auditable_id'] ?? null);

        $user = Auth::user();

        // While impersonating, record the real administrator on every row.
        try {
            if (app()->bound('request') && request()->hasSession() && ($impersonator = request()->session()->get('impersonator_id'))) {
                $ctx = (array) ($options['context'] ?? []);
                $ctx['impersonator_id'] = $ctx['impersonator_id'] ?? $impersonator;
                $options['context'] = $ctx;
            }
        } catch (\Throwable $e) {
            // never let audit decoration break the request
        }

        $log = AuditLog::create([
            'user_id' => $user?->id,
            'user_email' => $options['user_email'] ?? $user?->email,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'description' => $options['description'] ?? null,
            // Defence in depth: nothing secret-like is ever persisted, whatever the caller.
            'old_values' => \App\Support\Redactor::redact($options['old_values'] ?? null),
            'new_values' => \App\Support\Redactor::redact($options['new_values'] ?? null),
            'context' => \App\Support\Redactor::redact($options['context'] ?? null),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);

        self::forwardToSyslog($log);

        return $log;
    }

    /** Optional tamper-resistant copy: AUDIT_SYSLOG=true sends every audit event to the `audit_syslog` log channel. */
    private static function forwardToSyslog(AuditLog $log): void
    {
        if (! config('monitoring.syslog', false) || str_starts_with((string) $log->action, 'http.get')) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Log::channel('audit_syslog')->info('audit ' . json_encode([
                'action' => $log->action,
                'user' => $log->user_email,
                'ip' => $log->ip_address,
                'description' => mb_substr((string) $log->description, 0, 300),
                'at' => optional($log->created_at)->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // an unavailable syslog must never break the request
        }
    }

    /**
     * Log a create action
     */
    public static function logCreate(Model $model, ?string $description = null): AuditLog
    {
        return self::log('create', $model, [
            'description' => $description ?? "Created {$model->getTable()} record",
            'new_values' => $model->getAttributes(),
        ]);
    }

    /**
     * Log an update action
     */
    public static function logUpdate(Model $model, array $originalValues, ?string $description = null): AuditLog
    {
        return self::log('update', $model, [
            'description' => $description ?? "Updated {$model->getTable()} record",
            'old_values' => $originalValues,
            'new_values' => $model->getAttributes(),
        ]);
    }

    /**
     * Log a delete action
     */
    public static function logDelete(Model $model, string $confirmedEmail, ?string $description = null): AuditLog
    {
        return self::log('delete', $model, [
            'description' => $description ?? "Deleted {$model->getTable()} record",
            'user_email' => $confirmedEmail,
            'old_values' => $model->getAttributes(),
        ]);
    }

    /**
     * Log a custom action
     */
    public static function logAction(string $action, Model $model, ?string $description = null, array $additionalData = []): AuditLog
    {
        return self::log($action, $model, array_merge([
            'description' => $description,
        ], $additionalData));
    }

    public static function logSystem(string $action, string $description, array $context = [], array $additionalData = []): AuditLog
    {
        return self::log($action, 'System', array_merge([
            'description' => $description,
            'context' => $context,
        ], $additionalData));
    }

    /** Is this action debug noise (only recorded when monitoring.verbose / the Verbose setting is on)? */
    public static function isNoise(string $action): bool
    {
        return in_array($action, (array) config('monitoring.noise_actions', []), true);
    }

    /**
     * Delete audit rows older than $days. Security events (see SecurityEvents) are only deleted once they are also
     * older than $securityDays, which can never be lower than the configured floor (default 365 days).
     */
    public static function pruneOlderThanDays(int $days, ?int $securityDays = null): int
    {
        $floor = max(1, (int) config('monitoring.security_retention_floor_days', 365));
        $securityDays = max($floor, $securityDays ?? $floor, 1);
        $cutoff = Carbon::now()->subDays(max(1, $days));
        $securityCutoff = Carbon::now()->subDays($securityDays);
        $total = 0;

        // Chunked so a large backlog never holds one long delete lock.
        do {
            $deleted = AuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->where(function ($q) use ($securityCutoff) {
                    $q->where(fn ($n) => \App\Support\Monitoring\SecurityEvents::whereNotSecurity($n))
                      ->orWhere('created_at', '<', $securityCutoff);
                })
                ->limit(5000)->delete();
            $total += $deleted;
        } while ($deleted >= 5000);

        return $total;
    }
}
