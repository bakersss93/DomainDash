<?php

namespace App\Services\Https;

use App\Models\PlatformCertificate;
use App\Models\PlatformCertificateRequest;
use App\Models\Setting;
use App\Support\Https\HostnameValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Web-side facade for Platform HTTPS: settings, heartbeat, request creation, status.
 * Never touches the filesystem outside storage, never runs processes.
 */
class PlatformHttps
{
    public const SETTING = 'platform_https';
    public const HEARTBEAT = 'platform_https_heartbeat';
    public const LAST_RENEWAL = 'platform_https_last_renewal';
    private const FORCE_CACHE_KEY = 'platform_https.force';

    public static function forceEnabled(): bool
    {
        try {
            return (bool) Cache::remember(self::FORCE_CACHE_KEY, 300, fn () => (bool) (Setting::get(self::SETTING, [])['force'] ?? false));
        } catch (\Throwable) {
            return false; // table missing (pre-migration) or DB down: never block the app
        }
    }

    public static function setForce(bool $on): void
    {
        $current = (array) Setting::get(self::SETTING, []);
        $current['force'] = $on;
        Setting::put(self::SETTING, $current);
        Cache::forget(self::FORCE_CACHE_KEY);
    }

    /** @return array{last_seen:?Carbon,stale:bool,info:array} */
    public function heartbeat(): array
    {
        $info = (array) Setting::get(self::HEARTBEAT, []);
        $seen = isset($info['last_seen']) ? Carbon::parse($info['last_seen']) : null;
        $stale = $seen === null || $seen->lt(now()->subSeconds((int) config('domaindash.https.heartbeat_stale_seconds', 300)));

        return ['last_seen' => $seen, 'stale' => $stale, 'info' => $info];
    }

    public function lastRenewal(): array
    {
        return (array) Setting::get(self::LAST_RENEWAL, []);
    }

    public function hostFromAppUrl(): string
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return HostnameValidator::isValidHostname($host) ? $host : '';
    }

    /** True when a usable (active, unexpired) certificate exists. */
    public function hasValidActiveCertificate(): bool
    {
        $cert = PlatformCertificate::active();

        return $cert !== null && ! $cert->isExpired();
    }

    public function hasOpenRequest(): bool
    {
        return PlatformCertificateRequest::query()->whereIn('status', ['pending', 'running'])->exists();
    }

    /**
     * Zone (registered domain) of $host that is managed in Synergy, from the local domains table.
     */
    public function managedZone(string $host): ?string
    {
        return ZoneFinder::fromLocalDomains($host);
    }

    public function createLetsEncryptRequest(string $hostname, string $email, bool $staging, ?int $userId): PlatformCertificateRequest
    {
        return PlatformCertificateRequest::create([
            'type' => 'letsencrypt', 'hostname' => $hostname, 'email' => $email, 'staging' => $staging,
            'status' => 'pending', 'requested_by' => $userId,
        ]);
    }

    /** Store an already-validated upload; the key never leaves the DB unencrypted. */
    public function createUploadRequest(string $hostname, \App\Support\Https\CertificateBundle $bundle, ?int $userId): PlatformCertificateRequest
    {
        return PlatformCertificateRequest::create([
            'type' => 'upload', 'hostname' => $hostname, 'status' => 'pending', 'requested_by' => $userId,
            'payload' => Crypt::encryptString(json_encode([
                'cert' => $bundle->certificatePem,
                'chain' => $bundle->chainPem,
                'key' => $bundle->privateKeyPem,
            ])),
        ]);
    }
}
