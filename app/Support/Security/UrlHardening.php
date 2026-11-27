<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\URL;

/**
 * Host-header poisoning defence (B1).
 *
 * Password-reset / invitation / verification links are built from the request host. If a request is sent with a
 * forged `Host:` (or X-Forwarded-Host) header, the emailed link would point at the attacker's site. We therefore
 *  1. trust only the APP_URL host (see trustedHostPatterns(), wired into bootstrap/app.php), and
 *  2. force every generated URL onto APP_URL (see apply(), wired into AppServiceProvider::boot()).
 */
class UrlHardening
{
    /**
     * Regex patterns for Request::setTrustedHosts(): exactly the APP_URL host, no subdomains.
     *
     * @return list<string>
     */
    public static function trustedHostPatterns(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host ? ['^' . preg_quote(strtolower($host), '/') . '$'] : [];
    }

    /** Pin generated URLs to APP_URL (and https when APP_URL is https). */
    public static function apply(): void
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);

        if (! $url || empty($parts['host'])) {
            return;
        }

        URL::forceRootUrl($url);

        if (strtolower($parts['scheme'] ?? '') === 'https') {
            URL::forceScheme('https');
        }
    }
}
