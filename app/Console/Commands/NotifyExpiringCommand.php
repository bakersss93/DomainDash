<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;
use App\Models\SslCertificate;
use Illuminate\Support\Facades\Notification;

class NotifyExpiringCommand extends Command
{
    protected $signature = 'domaindash:notify-expiring';
    protected $description = 'Notify customers/admins of expiring domains and SSLs';

    public function handle(): int
    {
        $domains = Domain::whereNotNull('expiry_date')->where('expiry_date','<=', now()->addDays(30))->get();
        $ssls = SslCertificate::whereNotNull('expire_date')->where('expire_date','<=', now()->addDays(30))->get();

        // Simplified: in real app, group by client and send to assigned users
        foreach ($domains as $d) {
            // dispatch email using Blade templates
        }
        foreach ($ssls as $s) {
            // dispatch email using Blade templates
        }
        $this->info('Notifications enqueued.');
        return 0;
    }
}
