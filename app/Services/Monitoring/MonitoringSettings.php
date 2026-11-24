<?php

namespace App\Services\Monitoring;

use App\Models\Setting;

/**
 * Effective monitoring configuration: config/monitoring.php defaults overridden by the
 * administrator's values in the `audit` settings group. Registered as a scoped service so
 * the database is read at most once per request / queued job (and re-read between jobs).
 * Never throws: a missing table or setting falls back to the defaults.
 */
class MonitoringSettings
{
    public const LEVELS = ['errors', 'all'];

    private ?array $stored = null;

    private function stored(): array
    {
        if ($this->stored === null) {
            try {
                $value = Setting::get('audit', []);
                $this->stored = is_array($value) ? $value : [];
            } catch (\Throwable) {
                $this->stored = [];
            }
        }

        return $this->stored;
    }

    public function flush(): void
    {
        $this->stored = null;
    }

    public function verbose(): bool
    {
        $v = $this->stored()['verbose'] ?? null;

        return $v === null ? (bool) config('monitoring.verbose', false) : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function maskPii(): bool
    {
        $v = $this->stored()['mask_pii'] ?? null;

        return $v === null ? (bool) config('monitoring.mask_pii', true) : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    public function level(string $direction): string
    {
        $key = $direction === 'inbound' ? 'inbound_level' : 'outbound_level';
        $v = $this->stored()[$key] ?? config('monitoring.' . $key, $direction === 'inbound' ? 'all' : 'errors');

        return in_array($v, self::LEVELS, true) ? $v : ($direction === 'inbound' ? 'all' : 'errors');
    }

    public function bodyLimit(): int
    {
        return max(1024, (int) config('monitoring.body_limit', 65536));
    }

    /** Retention in days for: activity | outbound | inbound | jobs */
    public function retentionDays(string $category): int
    {
        $stored = $this->stored();
        $key = $category === 'activity' ? 'retention_days' : 'retention_days_' . $category;
        $days = (int) ($stored[$key] ?? config('monitoring.retention.' . $category, 90));

        return max(1, min(3650, $days ?: (int) config('monitoring.retention.' . $category, 90)));
    }

    /** @return array<string, mixed> everything the settings form shows */
    public function all(): array
    {
        return [
            'verbose' => $this->verbose(),
            'mask_pii' => $this->maskPii(),
            'outbound_level' => $this->level('outbound'),
            'inbound_level' => $this->level('inbound'),
            'retention' => [
                'activity' => $this->retentionDays('activity'),
                'outbound' => $this->retentionDays('outbound'),
                'inbound' => $this->retentionDays('inbound'),
                'jobs' => $this->retentionDays('jobs'),
            ],
        ];
    }
}
