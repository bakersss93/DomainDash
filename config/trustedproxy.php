<?php

/*
| Trusted reverse proxies (SEC-16). Read by Laravel's TrustProxies middleware
| (global stack) via config('trustedproxy.proxies'); config-based so it keeps
| working with `php artisan config:cache` (env() is not available from
| bootstrap/app.php once config is cached).
|
|   TRUSTED_PROXIES=            (default) trust nobody; behind nginx -> php-fpm directly
|   TRUSTED_PROXIES=*           trust the calling IP (only if php-fpm/app is reachable ONLY via the proxy)
|   TRUSTED_PROXIES=10.0.0.5,10.0.0.6   comma separated IPs / CIDRs (Cloudflare/LB ranges)
*/
$raw = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match (true) {
        $raw === '' => null,
        $raw === '*' || $raw === '**' => $raw,
        default => array_values(array_filter(array_map('trim', explode(',', $raw)))),
    },
];
