<?php

namespace App\Services\Notifications;

use App\Models\NotificationAlertState;
use App\Models\User;

/**
 * Central generator for administrator alerts. Providers report conditions that are currently true; per-user
 * dismiss/snooze state is applied on top, and a snoozed alert reappears once its snooze has passed while the
 * condition still holds.
 */
class SystemAlerts
{
    public const SEVERITY_ORDER = ['error' => 3, 'warning' => 2, 'info' => 1];
    public const SNOOZE_HOURS = [1, 4, 24, 168];
    public const DEFAULT_SNOOZE_HOURS = 24;

    /** @var list<class-string<AlertProvider>> */
    public static array $providers = [
        Providers\QueueWorkerAlerts::class,
        Providers\IntegrationConfigAlerts::class,
        Providers\RecentFailureAlerts::class,
        Providers\HttpsAlerts::class,
        Providers\MfaAlerts::class,
        Providers\PendingMigrationAlerts::class,
    ];

    /** @return list<array<string,mixed>> every active alert, most severe first */
    public function active(): array
    {
        $alerts = [];
        foreach (static::$providers as $class) {
            try {
                foreach (app($class)->alerts() as $alert) {
                    $alerts[] = $alert;
                }
            } catch (\Throwable) {
                // one broken provider must not hide the others
            }
        }

        usort($alerts, fn ($a, $b) => (self::SEVERITY_ORDER[$b['severity']] ?? 0) <=> (self::SEVERITY_ORDER[$a['severity']] ?? 0));

        return $alerts;
    }

    /** Active alerts this user has not dismissed or snoozed (or whose snooze has ended). */
    public function forUser(User $user): array
    {
        if (! $user->hasRole('Administrator')) {
            return [];
        }

        $active = $this->active();
        $keys = array_column($active, 'key');

        // Forget state for conditions that cleared, so a later recurrence is shown again.
        NotificationAlertState::where('user_id', $user->id)->whereNotIn('alert_key', $keys ?: [''])->delete();

        $hidden = NotificationAlertState::where('user_id', $user->id)
            ->where('snoozed_until', '>', now())
            ->pluck('snoozed_until', 'alert_key');

        return array_values(array_filter($active, fn ($a) => ! isset($hidden[$a['key']])));
    }

    public function snooze(User $user, string $key, ?int $hours = null): void
    {
        $hours = in_array($hours, self::SNOOZE_HOURS, true) ? $hours : self::DEFAULT_SNOOZE_HOURS;

        NotificationAlertState::updateOrCreate(
            ['user_id' => $user->id, 'alert_key' => $key],
            ['snoozed_until' => now()->addHours($hours)]
        );
    }

    public static function highestSeverity(array $alerts): ?string
    {
        $best = null;
        foreach ($alerts as $a) {
            if ($best === null || (self::SEVERITY_ORDER[$a['severity']] ?? 0) > (self::SEVERITY_ORDER[$best] ?? 0)) {
                $best = $a['severity'];
            }
        }

        return $best;
    }
}
