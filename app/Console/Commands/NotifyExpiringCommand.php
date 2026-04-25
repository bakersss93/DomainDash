<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\EmailTemplateMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifyExpiringCommand extends Command
{
    protected $signature = 'domaindash:notify-expiring';
    protected $description = 'Notify customers/admins of expiring domains and SSLs';

    public function handle(EmailTemplateMailer $mailer): int
    {
        $domainCount = 0;
        $sslCount = 0;

        $domains = Domain::query()
            ->with('client')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->startOfDay())
            ->whereDate('expiry_date', '<=', now()->addDays(30)->endOfDay())
            ->get();

        foreach ($domains as $domain) {
            $daysBefore = now()->startOfDay()->diffInDays($domain->expiry_date->startOfDay(), false);
            if ($daysBefore < 0) {
                continue;
            }

            $lockKey = sprintf('notify:domain:%d:%d:%s', $domain->id, $daysBefore, now()->toDateString());
            if (! Cache::add($lockKey, true, now()->endOfDay())) {
                continue;
            }

            $sent = $mailer->sendForEvent(
                'domain_expiring',
                [
                    'client' => [
                        'name' => $domain->client?->business_name ?: $domain->client?->primary_contact_name,
                        'email' => $domain->client?->email,
                    ],
                    'company' => [
                        'name' => config('app.name', 'DomainDash'),
                    ],
                    'domain' => [
                        'name' => $domain->name,
                        'expiry_date' => $domain->expiry_date?->toDateString(),
                        'renewal_price' => 'TBC',
                    ],
                ],
                $domain->client?->email,
                $daysBefore,
            );

            $domainCount += $sent ? 1 : 0;
        }

        $ssls = SslCertificate::query()
            ->with('client')
            ->whereNotNull('expire_date')
            ->whereDate('expire_date', '>=', now()->startOfDay())
            ->whereDate('expire_date', '<=', now()->addDays(30)->endOfDay())
            ->get();

        foreach ($ssls as $ssl) {
            $daysBefore = now()->startOfDay()->diffInDays($ssl->expire_date->startOfDay(), false);
            if ($daysBefore < 0) {
                continue;
            }

            $lockKey = sprintf('notify:ssl:%d:%d:%s', $ssl->id, $daysBefore, now()->toDateString());
            if (! Cache::add($lockKey, true, now()->endOfDay())) {
                continue;
            }

            $sent = $mailer->sendForEvent(
                'ssl_expiring',
                [
                    'client' => [
                        'name' => $ssl->client?->business_name ?: $ssl->client?->primary_contact_name,
                        'email' => $ssl->client?->email,
                    ],
                    'company' => [
                        'name' => config('app.name', 'DomainDash'),
                    ],
                    'ssl' => [
                        'common_name' => $ssl->common_name,
                        'expiry_date' => $ssl->expire_date?->toDateString(),
                        'issuer' => $ssl->product_name,
                    ],
                ],
                $ssl->client?->email,
                $daysBefore,
            );

            $sslCount += $sent ? 1 : 0;
        }

        $this->info("Expiry notification run completed. Domain emails sent: {$domainCount}; SSL emails sent: {$sslCount}.");

        return self::SUCCESS;
    }
}
