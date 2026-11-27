<?php

namespace App\Console\Commands;

use App\Models\PlatformCertificate;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AuditLogger;
use App\Services\EmailTemplateMailer;
use App\Services\Https\PlatformHttps;
use Illuminate\Console\Command;

/** Daily: alert admins at 30/14/7/1 days before platform certificate expiry and on failed renewals. */
class HttpsCheckCommand extends Command
{
    public const THRESHOLDS = [30, 14, 7, 1];
    public const ALERTS = 'platform_https_alerts';

    protected $signature = 'domaindash:https-check';
    protected $description = 'Check the platform HTTPS certificate expiry and failed renewals';

    public function handle(EmailTemplateMailer $mailer): int
    {
        $sent = 0;
        $cert = PlatformCertificate::active();

        if ($cert && $cert->not_after) {
            $days = $cert->daysRemaining();
            $state = (array) Setting::get(self::ALERTS, []);
            if (($state['fingerprint'] ?? null) !== $cert->fingerprint_sha256) {
                $state = ['fingerprint' => $cert->fingerprint_sha256, 'sent' => []];
            }

            $due = collect(self::THRESHOLDS)->filter(fn ($t) => $days <= $t)->min();
            if ($due !== null && ! in_array($due, $state['sent'], true)) {
                $state['sent'] = array_values(array_unique(array_merge($state['sent'], array_filter(self::THRESHOLDS, fn ($t) => $t >= $due))));
                Setting::put(self::ALERTS, $state);

                $title = $days < 0 ? 'Platform HTTPS certificate has expired' : "Platform HTTPS certificate expires in {$days} day(s)";
                $this->raise('platform_https_expiring', $title,
                    "{$cert->hostname} ({$cert->source}) expires on {$cert->not_after->toDateString()}.", [
                        'hostname' => $cert->hostname, 'expiry_date' => $cert->not_after->toDateString(), 'days_left' => $days, 'error' => '',
                    ], $mailer);
                $sent++;
            }
        }

        $renewal = (new PlatformHttps())->lastRenewal();
        if (($renewal['ok'] ?? true) === false && empty($renewal['notified'])) {
            $renewal['notified'] = true;
            Setting::put(PlatformHttps::LAST_RENEWAL, $renewal);
            $this->raise('platform_https_renewal_failed', 'Platform HTTPS renewal failed',
                'Automatic certificate renewal failed. Open Settings > Platform HTTPS for details.', [
                    'hostname' => $cert?->hostname ?? '', 'expiry_date' => $cert?->not_after?->toDateString() ?? '',
                    'days_left' => $cert?->daysRemaining() ?? '', 'error' => (string) ($renewal['message'] ?? ''),
                ], $mailer);
            $sent++;
        }

        $this->info("HTTPS check complete. Alerts raised: {$sent}.");

        return self::SUCCESS;
    }

    private function raise(string $event, string $title, string $message, array $vars, EmailTemplateMailer $mailer): void
    {
        AuditLogger::logSystem('https.alert', $title, ['service' => 'platform-https', 'event' => $event]);

        foreach (User::role('Administrator')->get() as $admin) {
            UserNotification::create([
                'user_id' => $admin->id, 'type' => 'warning', 'title' => $title,
                'message' => substr($message, 0, 1000), 'link_url' => route('admin.https', [], false),
            ]);
        }

        try {
            $mailer->sendForEvent($event, ['https' => $vars, 'company' => ['name' => config('app.name', 'DomainDash')]], null);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
