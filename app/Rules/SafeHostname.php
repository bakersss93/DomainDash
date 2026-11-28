<?php

namespace App\Rules;

use App\Support\Https\HostnameValidator;
use App\Support\Security\PublicNetwork;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Admin-configurable server hostnames (SMTP host, SFTP backup host): a plain hostname or IP address, no scheme /
 * path / port, and never localhost or a private / loopback / link-local / reserved address (SSRF guard).
 * A name that does not resolve at validation time is allowed. Set DOMAINDASH_ALLOW_PRIVATE_HOSTS=true to permit
 * internal relays / backup servers on a private network.
 */
class SafeHostname implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $host = is_string($value) ? strtolower(trim($value)) : '';

        $isIp = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        if (! $isIp) {
            try {
                HostnameValidator::hostname($host);
            } catch (\Throwable) {
                $fail('The :attribute must be a valid hostname or IP address (no scheme, path or port).');

                return;
            }
        }

        if (config('domaindash.allow_private_hosts', false)) {
            return;
        }

        $bare = trim($host, '[]');
        if ($bare === 'localhost' || str_ends_with($bare, '.localhost') || str_ends_with($bare, '.internal') || str_ends_with($bare, '.local')) {
            $fail('The :attribute must not point to a private or reserved address.');

            return;
        }

        foreach (PublicNetwork::addressesFor($bare) as $ip) {
            if (! PublicNetwork::isPublic($ip)) {
                $fail('The :attribute must not point to a private or reserved address.');

                return;
            }
        }
    }
}
