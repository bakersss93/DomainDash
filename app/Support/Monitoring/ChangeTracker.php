<?php

namespace App\Support\Monitoring;

use App\Models\AuditLog;
use App\Models\IntegrationRequest;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Support\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Collects Eloquent create / update / delete events while an HTTP mutation is being handled, so the generic
 * request audit row can carry a real old -> new diff ("Updated client Example Pty Ltd: phone 0400 -> 0411").
 * Inactive outside AuditRequestActions (queued jobs and sync runs never feed it).
 */
class ChangeTracker
{
    private const IGNORED_MODELS = [AuditLog::class, IntegrationRequest::class, SyncRun::class];

    private const LABEL_FIELDS = ['business_name', 'name', 'domain_name', 'vocus_service_id', 'email', 'title', 'plan_id', 'tld', 'domain'];

    private const MAX_CHANGES = 20;

    private static bool $active = false;

    /** @var array<int, array<string, mixed>> */
    private static array $changes = [];

    public static function start(): void
    {
        self::$active = true;
        self::$changes = [];
    }

    /** @return array<int, array<string, mixed>> */
    public static function stop(): array
    {
        $changes = self::$changes;
        self::$active = false;
        self::$changes = [];

        return $changes;
    }

    public static function record(string $event, mixed $model): void
    {
        if (! self::$active || ! $model instanceof Model || count(self::$changes) >= self::MAX_CHANGES) {
            return;
        }
        if (in_array(get_class($model), self::IGNORED_MODELS, true)) {
            return;
        }

        $type = Str::lower(Str::headline(class_basename($model)));

        // Settings hold encrypted vendor credentials: record only which key changed, never values.
        if ($model instanceof Setting) {
            self::$changes[] = ['event' => $event, 'type' => 'setting', 'model' => get_class($model), 'id' => $model->getKey(), 'label' => (string) $model->getAttribute('key'), 'old' => [], 'new' => []];

            return;
        }

        $old = [];
        $new = [];
        if ($event === 'updated') {
            foreach ($model->getChanges() as $key => $value) {
                if (self::ignoredColumn($key)) {
                    continue;
                }
                $old[$key] = self::scalar($model->getOriginal($key));
                $new[$key] = self::scalar($value);
            }
            if ($new === []) {
                return;
            }
        } elseif ($event === 'created') {
            $new = self::attributes($model);
        } else {
            $old = self::attributes($model);
        }

        self::$changes[] = [
            'event' => $event,
            'type' => $type,
            'model' => get_class($model),
            'id' => $model->getKey(),
            'label' => self::label($model),
            'old' => Redactor::redact($old),
            'new' => Redactor::redact($new),
        ];
    }

    private static function attributes(Model $model): array
    {
        $out = [];
        foreach ($model->getAttributes() as $key => $value) {
            if (! self::ignoredColumn($key) && $value !== null) {
                $out[$key] = self::scalar($value);
            }
            if (count($out) >= 25) {
                break;
            }
        }

        return $out;
    }

    private static function ignoredColumn(string $key): bool
    {
        return (bool) preg_match('/^(updated_at|created_at|remember_token|last_used_at|last_login_at)$|_(synced_at|sync_hash|sync_state)$/', $key);
    }

    private static function scalar(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…' : $value;
    }

    public static function label(Model $model): string
    {
        foreach (self::LABEL_FIELDS as $field) {
            $value = $model->getAttribute($field);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return '#' . $model->getKey();
    }
}
