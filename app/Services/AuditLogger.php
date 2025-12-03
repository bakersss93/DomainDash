<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
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
        $auditableType = is_object($auditable) ? get_class($auditable) : $auditable;
        $auditableId = is_object($auditable) ? $auditable->id : ($options['auditable_id'] ?? null);

        $user = Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'user_email' => $options['user_email'] ?? $user?->email,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'description' => $options['description'] ?? null,
            'old_values' => $options['old_values'] ?? null,
            'new_values' => $options['new_values'] ?? null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
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
}
