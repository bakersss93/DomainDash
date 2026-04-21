<?php

namespace App\Console;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleRegistrar
{
    public static function register(Schedule $schedule): void
    {
        // Nightly domain/hosting/SSL sync
        $schedule->job(new \App\Jobs\SyncSynergyDomainsJob())->dailyAt('02:30');

        $syncSchedule = Setting::get('sync_schedule', []);
        $timezone = date_default_timezone_get();

        self::scheduleSyncTask($schedule, $syncSchedule['sync_domains'] ?? [], 'sync-domains', 'sync-domains', $timezone);
        self::scheduleSyncTask($schedule, $syncSchedule['sync_hosting_services'] ?? [], 'sync-hosting-services', 'sync-hosting-services', $timezone);
        self::scheduleSyncTask($schedule, $syncSchedule['sync_halo_assets'] ?? [], 'sync-halo-assets', 'sync-halo-assets', $timezone);
        self::scheduleSyncTask($schedule, $syncSchedule['sync_itglue'] ?? [], 'sync-itglue', 'sync-itglue', $timezone);

        // Backup
        $schedule->command('domaindash:backup-run')->dailyAt('03:00');

        // Audit retention housekeeping
        $schedule->command('domaindash:audit-prune')->dailyAt('03:30');

        // Expiry notifications
        $schedule->command('domaindash:notify-expiring')->hourly();
    }

    private static function scheduleSyncTask(Schedule $schedule, array $taskConfig, string $taskName, string $taskArgument, string $timezone): void
    {
        $enabled = filter_var($taskConfig['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return;
        }

        $frequency = $taskConfig['frequency'] ?? 'daily';
        $time = $taskConfig['time'] ?? '02:00';

        $event = match ($frequency) {
            'hourly' => $schedule->command('domaindash:sync-task', ['task' => $taskArgument])->hourlyAt((int) substr($time, 3, 2)),
            'weekly' => $schedule->command('domaindash:sync-task', ['task' => $taskArgument])->weeklyOn(1, $time),
            default => $schedule->command('domaindash:sync-task', ['task' => $taskArgument])->dailyAt($time),
        };

        $event->timezone($timezone)->name("scheduled-{$taskName}");
    }
}
