<?php

namespace App\Support\Https;

use InvalidArgumentException;

/**
 * Strict hostname / e-mail validation shared by the web app and the root helper.
 * The helper uses the strict methods (no normalisation): it never trusts the DB.
 */
final class HostnameValidator
{
    private const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /** Lower-case + trim, for user input only. Validation is still done by hostname(). */
    public static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function isValidHostname(string $host, bool $allowWildcard = false): bool
    {
        try {
            self::hostname($host, $allowWildcard);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * RFC 1123 hostname: lower-case only, 2+ labels, labels 1-63 chars, total <= 253,
     * not an IP address, numeric TLD rejected. A single leading "*." is accepted only
     * when $allowWildcard is true (DNS-01 only).
     *
     * @throws InvalidArgumentException
     */
    public static function hostname(string $host, bool $allowWildcard = false): string
    {
        if ($host === '' || strlen($host) > 253) {
            throw new InvalidArgumentException('Hostname must be between 1 and 253 characters.');
        }

        if (! preg_match('/^[a-z0-9.*-]+$/D', $host)) {
            throw new InvalidArgumentException('Hostname may only contain lower-case letters, digits, hyphens and dots.');
        }

        $name = $host;
        if (str_starts_with($name, '*.')) {
            if (! $allowWildcard) {
                throw new InvalidArgumentException('Wildcard hostnames are not allowed here.');
            }
            $name = substr($name, 2);
        }

        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('IP addresses are not supported; use a hostname.');
        }

        $labels = explode('.', $name);
        if (count($labels) < 2) {
            throw new InvalidArgumentException('Hostname must be fully qualified (e.g. dash.example.com).');
        }

        foreach ($labels as $label) {
            if (! preg_match(self::LABEL, $label)) {
                throw new InvalidArgumentException('Hostname contains an invalid label.');
            }
        }

        if (ctype_digit(end($labels))) {
            throw new InvalidArgumentException('Hostname has an invalid top-level label.');
        }

        return $host;
    }

    /** @throws InvalidArgumentException */
    public static function email(string $email): string
    {
        if ($email === '' || strlen($email) > 254) {
            throw new InvalidArgumentException('E-mail must be between 1 and 254 characters.');
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._%+-]{0,63}@([a-z0-9.-]+)$/D', $email, $m)) {
            throw new InvalidArgumentException('E-mail address contains unsupported characters.');
        }

        self::hostname(strtolower($m[1]));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail address is not valid.');
        }

        return $email;
    }

    /** ACME TXT validation token: base64url, as produced by certbot (43 chars for SHA-256). */
    public static function acmeValidation(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $value)) {
            throw new InvalidArgumentException('Invalid ACME validation token.');
        }

        return $value;
    }

    /** Directory/lineage name for a hostname: deterministic, path-safe. */
    public static function certName(string $host): string
    {
        $host = self::hostname($host, true);

        return str_starts_with($host, '*.') ? 'wildcard.' . substr($host, 2) : $host;
    }

    /** Wildcard-aware match of $host against a certificate name list. */
    public static function covers(string $host, array $names): bool
    {
        $host = strtolower($host);
        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }
            if ($name === $host) {
                return true;
            }
            // "*.example.com" covers exactly one extra label ("a.example.com"), not "a.b.example.com"
            // and not "example.com". A requested "*.example.com" only matches a cert listing it exactly.
            if (str_starts_with($name, '*.') && ! str_starts_with($host, '*.')) {
                $suffix = substr($name, 1); // ".example.com"
                if (str_ends_with($host, $suffix)) {
                    $left = substr($host, 0, -strlen($suffix));
                    if ($left !== '' && ! str_contains($left, '.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
