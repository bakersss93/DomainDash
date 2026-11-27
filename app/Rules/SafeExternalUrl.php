<?php

namespace App\Rules;

use Closure;
use App\Support\Security\PublicNetwork;
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

        foreach (PublicNetwork::addressesFor($host) as $ip) {
            if (! PublicNetwork::isPublic($ip)) {
                $fail('The :attribute must not point to a private or reserved address.');

                return;
            }
        }
    }
}
