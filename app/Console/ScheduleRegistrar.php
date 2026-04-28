<?php

namespace App\Console;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

class ScheduleRegistrar
{
    public static function register(Schedule $schedule): void
    {
        // Nightly domain/hosting/SSL sync
        $schedule->job(new \App\Jobs\SyncSynergyDomainsJob())
            ->dailyAt('02:30')
            ->name('scheduled-synergy-domain-job')
            ->description('Queue job: Sync Synergy domains');

        $syncSchedule = Schema::hasTable('settings') ? Setting::get('sync_schedule', []) : [];
        $timezone = date_default_timezone_get();

        self::scheduleSyncTask($schedule, $syncSchedule['sync_domains'] ?? [], 'sync-domains', 'sync-domains', $timezone);
        self::scheduleSyncTask($schedule, $syncSchedule['sync_hosting_services'] ?? [], 'sync-hosting-services', 'sync-hosting-services', $timezone);
        self::scheduleSyncTask($schedule, $syncSchedule['sync_halo_assets'] ?? [], 'sync-halo-assets', 'sync-halo-assets', $timezone);
        self::scheduleSyncTask($schedule, $syncSchedule['sync_itglue'] ?? [], 'sync-itglue', 'sync-itglue', $timezone);

        // Backup
        $schedule->command('domaindash:backup-run')
            ->dailyAt('03:00')
            ->name('scheduled-backup-run')
            ->description('Command: run scheduled backup');

        // Audit retention housekeeping
        $schedule->command('domaindash:audit-prune')
            ->dailyAt('03:30')
            ->name('scheduled-audit-prune')
            ->description('Command: prune audit logs');

        // Expiry notifications
        $schedule->command('domaindash:notify-expiring')
            ->hourly()
            ->name('scheduled-notify-expiring')
            ->description('Command: send expiry notifications');
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

        $event->timezone($timezone)
            ->withoutOverlapping(30)
            ->name("scheduled-{$taskName}")
            ->description("Command: domaindash:sync-task {$taskArgument}");
    }
}
