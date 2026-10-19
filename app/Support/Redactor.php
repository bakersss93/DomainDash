<?php

namespace App\Support;

/**
 * Recursive, case-insensitive redaction of secret-looking array keys.
 */
class Redactor
{
    public const MASK = '[REDACTED]';
    public const FORM_MASK = '********';

    private const EXACT = ['key', 'pass', 'pwd', 'pin', 'otp', 'code_verifier'];

    private const FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'access_key',
        'private_key', 'client_secret', 'credential', 'authorization', 'auth_code',
        'authcode', 'authinfo', 'epp', 'two_factor', 'recovery_code', 'mfa_code',
        'bearer', 'cookie', 'sso_url', 'passphrase', 'signature',
    ];

    public static function isSecretKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $k = strtolower(str_replace(['-', ' ', '.'], '_', $key));

        if (in_array($k, self::EXACT, true)) {
            return true;
        }

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($k, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace the value of every secret-like key (at any depth) with a mask.
     */
    public static function redact(mixed $data, string $mask = self::MASK): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (self::isSecretKey($key) && $value !== null && $value !== '') {
                $out[$key] = $mask;
            } elseif (is_array($value)) {
                $out[$key] = self::redact($value, $mask);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Settings form helper: swap real secrets for the "********" placeholder.
     */
    public static function maskForForm(mixed $data): mixed
    {
        return self::redact($data, self::FORM_MASK);
    }

    /**
     * Settings form helper: for every secret-like key, keep the stored value when
     * the submitted one is the placeholder, blank, or missing.
     */
    public static function preserveSecrets(mixed $new, mixed $current): mixed
    {
        if (! is_array($new) || ! is_array($current)) {
            return $new;
        }

        foreach ($current as $key => $currentValue) {
            $has = array_key_exists($key, $new);
            if (self::isSecretKey($key) && ! is_array($currentValue)) {
                if (! $has || $new[$key] === self::FORM_MASK || $new[$key] === '' || $new[$key] === null) {
                    $new[$key] = $currentValue;
                }
            } elseif ($has && is_array($currentValue)) {
                $new[$key] = self::preserveSecrets($new[$key], $currentValue);
            }
        }

        // A submitted placeholder with nothing stored must never be saved as a secret.
        foreach ($new as $key => $value) {
            if (self::isSecretKey($key) && $value === self::FORM_MASK) {
                unset($new[$key]);
            }
        }

        return $new;
    }
}
