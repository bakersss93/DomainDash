<?php

namespace App\Console;

use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Nightly domain/hosting/SSL sync
        $schedule->job(new \App\Jobs\SyncSynergyDomainsJob())->dailyAt('02:30');

        $syncSchedule = Setting::get('sync_schedule', []);
        $this->scheduleSyncTask($schedule, $syncSchedule['sync_domains'] ?? [], 'sync-domains', 'domaindash:sync-task sync-domains');
        $this->scheduleSyncTask($schedule, $syncSchedule['sync_hosting_services'] ?? [], 'sync-hosting-services', 'domaindash:sync-task sync-hosting-services');
        $this->scheduleSyncTask($schedule, $syncSchedule['sync_halo_assets'] ?? [], 'sync-halo-assets', 'domaindash:sync-task sync-halo-assets');
        $this->scheduleSyncTask($schedule, $syncSchedule['sync_itglue'] ?? [], 'sync-itglue', 'domaindash:sync-task sync-itglue');

        // Backup
        $schedule->command('domaindash:backup-run')->dailyAt('03:00');

        // Expiry notifications
        $schedule->command('domaindash:notify-expiring')->hourly();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }

    private function scheduleSyncTask(Schedule $schedule, array $taskConfig, string $taskName, string $command): void
    {
        $enabled = filter_var($taskConfig['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return;
        }

        $frequency = $taskConfig['frequency'] ?? 'daily';
        $time = $taskConfig['time'] ?? '02:00';

        $event = match ($frequency) {
            'hourly' => $schedule->command($command)->hourlyAt((int) substr($time, 3, 2)),
            'weekly' => $schedule->command($command)->weeklyOn(1, $time),
            default => $schedule->command($command)->dailyAt($time),
        };

        $event->name("scheduled-{$taskName}");
    }
}
