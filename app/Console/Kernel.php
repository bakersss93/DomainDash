<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Nightly domain/hosting/SSL sync
        $schedule->job(new \App\Jobs\SyncSynergyDomainsJob())->dailyAt('02:30');

        // Backup
        $schedule->command('domaindash:backup-run')->dailyAt('03:00');

        // Expiry notifications
        $schedule->command('domaindash:notify-expiring')->hourly();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
