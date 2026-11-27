<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SSRF guard for admin-configurable integration base URLs (HaloPSA, IT Glue, Vocus ...).
 *
 * Requires https, no embedded credentials, and a host that is neither a private / loopback / link-local /
 * reserved address nor "localhost". Hostnames are resolved (IPv4 and IPv6) and every address must be public.
 * A name that does not resolve at validation time is allowed (DNS may be unavailable); this rule is a
 * save-time check, not a replacement for network egress controls.
 */
class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parsed = is_string($value) ? parse_url(trim($value)) : false;

        if (! $parsed || empty($parsed['host'])) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        if (strtolower($parsed['scheme'] ?? '') !== 'https') {
            $fail('The :attribute must use HTTPS.');

            return;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            $fail('The :attribute must not contain a username or password.');

            return;
        }

        $host = strtolower(trim($parsed['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            $fail('The :attribute must not point to a private or reserved address.');

            return;
        }

        foreach ($this->addressesFor($host) as $ip) {
            if (! $this->isPublic($ip)) {
                $fail('The :attribute must not point to a private or reserved address.');

                return;
            }
        }
    }

    /** @return list<string> */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) {
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return $ips;
    }

    private function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) is judged by the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // CGNAT 100.64.0.0/10 and IPv6 link-local are not covered by the PHP flags on every version.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            return ! (($long & 0xFFC00000) === ip2long('100.64.0.0'));
        }

        return ! str_starts_with(strtolower($ip), 'fe80:');
    }
}
