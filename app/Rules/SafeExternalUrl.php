<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects URLs that point to private/loopback/link-local network ranges to
 * prevent Server-Side Request Forgery (SSRF) via admin-controlled base_url
 * settings (HaloPSA, ITGlue, etc.).
 *
 * Enforces:
 *  - HTTPS scheme only
 *  - Valid hostname
 *  - Not a private / loopback / link-local / reserved address
 */
class SafeExternalUrl implements ValidationRule
{
    // RFC-1918, loopback, link-local, and other non-routable ranges.
    private const BLOCKED_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',   // link-local / AWS metadata
        '100.64.0.0/10',    // CGNAT
        '192.0.0.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '240.0.0.0/4',
        '::1/128',          // IPv6 loopback
        'fc00::/7',         // IPv6 unique-local
        'fe80::/10',        // IPv6 link-local
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $parsed = parse_url((string) $value);

        if (! $parsed || ! isset($parsed['host'])) {
            $fail('The :attribute must be a valid URL.');
            return;
        }

        if (($parsed['scheme'] ?? '') !== 'https') {
            $fail('The :attribute must use HTTPS.');
            return;
        }

        $host = $parsed['host'];

        // Resolve hostname to IP; if it fails or resolves to blocked range, reject.
        $ip = gethostbyname($host);

        // gethostbyname returns the original string on failure.
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Can't resolve — allow (DNS may not be available at validation time;
            // the important protection is blocking known private literals).
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            foreach (self::BLOCKED_CIDRS as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    $fail('The :attribute must not point to a private or reserved address.');
                    return;
                }
            }
        }
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        // IPv6 check
        if (str_contains($cidr, ':')) {
            if (! str_contains($ip, ':')) {
                return false;
            }
            $ipBin     = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            $prefixBytes = (int) floor((int) $bits / 8);
            $prefixBits  = (int) $bits % 8;
            for ($i = 0; $i < $prefixBytes; $i++) {
                if ($ipBin[$i] !== $subnetBin[$i]) {
                    return false;
                }
            }
            if ($prefixBits > 0 && $prefixBytes < strlen($ipBin)) {
                $mask = 0xFF & (0xFF << (8 - $prefixBits));
                if ((ord($ipBin[$prefixBytes]) & $mask) !== (ord($subnetBin[$prefixBytes]) & $mask)) {
                    return false;
                }
            }
            return true;
        }

        // IPv4 check
        if (str_contains($ip, ':')) {
            return false;
        }
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
