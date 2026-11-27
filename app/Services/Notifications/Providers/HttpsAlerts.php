<?php

namespace App\Services\Notifications\Providers;

use App\Models\PlatformCertificate;
use App\Services\Https\PlatformHttps;
use App\Services\Notifications\AlertProvider;

/** Platform HTTPS: certificate expiring/expired, and the root helper not reporting while HTTPS is managed here. */
class HttpsAlerts implements AlertProvider
{
    public function alerts(): array
    {
        $out = [];
        $url = route('admin.https');

        try {
            $cert = PlatformCertificate::active();
            $https = new PlatformHttps();

            if ($cert && $cert->not_after) {
                $days = $cert->daysRemaining();
                if ($days !== null && $days <= 30) {
                    $out[] = [
                        'key' => 'https-cert-expiry',
                        'severity' => $days <= 7 ? 'error' : 'warning',
                        'title' => $days < 0 ? 'Platform HTTPS certificate expired' : "Platform HTTPS certificate expires in {$days} day(s)",
                        'text' => "{$cert->hostname} expires on {$cert->not_after->toDateString()}.",
                        'url' => $url,
                        'at' => null,
                    ];
                }
            }

            // The helper only matters once a certificate is managed here or a request is waiting for it.
            if (($cert || $https->hasOpenRequest()) && $https->heartbeat()['stale']) {
                $out[] = [
                    'key' => 'https-helper',
                    'severity' => 'warning',
                    'title' => 'HTTPS helper not running',
                    'text' => 'The privileged certificate helper has not reported recently, so certificate requests and renewals will not be processed.',
                    'url' => $url,
                    'at' => $https->heartbeat()['last_seen']?->toIso8601String(),
                ];
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
