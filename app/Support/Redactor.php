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
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'access_key', 'accesskey', 'alias_key', 'aliaskey',
        'private_key', 'client_secret', 'credential', 'authorization', 'auth_code',
        'authcode', 'authinfo', 'epp', 'two_factor', 'recovery_code', 'mfa_code',
        'bearer', 'cookie', 'sso_url', 'passphrase', 'signature', 'samlresponse',
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

    // ------------------------------------------------------------------
    // Monitoring helpers: headers, URLs, XML / text bodies, PII
    // ------------------------------------------------------------------

    private const PII_FRAGMENTS = ['email', 'phone', 'mobile', 'fax', 'birth', 'dob', 'passport', 'licence', 'license', 'medicare'];

    /** Response headers worth keeping; everything else (cookies, tokens, server internals) is dropped. */
    public const RESPONSE_HEADER_ALLOWLIST = [
        'content-type', 'content-length', 'date', 'retry-after', 'location', 'x-request-id', 'x-correlation-id',
        'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'server', 'soap-status',
    ];

    /**
     * Mask request headers. Credentials show a short prefix only (or nothing when the value is short).
     *
     * @param array<string, string|array<int,string>> $headers
     * @return array<string, string>
     */
    public static function maskHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            $lower = strtolower((string) $name);

            if ($lower === 'authorization' || $lower === 'proxy-authorization') {
                $scheme = strtolower(strtok($value, ' ') ?: '');
                $out[$name] = in_array($scheme, ['bearer', 'basic', 'token'], true) ? ucfirst($scheme) . ' ' . self::MASK : self::MASK;
            } elseif (in_array($lower, ['cookie', 'set-cookie'], true)) {
                $out[$name] = self::MASK;
            } elseif (self::isSecretKey($lower)) {
                $out[$name] = self::keyPrefix($value);
            } else {
                $out[$name] = mb_substr($value, 0, 300);
            }
        }

        return $out;
    }

    /** "ITG.7474…" for long credentials, fully masked for short ones. */
    public static function keyPrefix(string $value): string
    {
        $value = trim($value);

        return strlen($value) >= 16 ? substr($value, 0, 8) . '…' : self::MASK;
    }

    /** Keep only allow-listed response headers. */
    public static function filterResponseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::RESPONSE_HEADER_ALLOWLIST, true)) {
                $out[$name] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }
        }

        return $out;
    }

    /** Remove user:pass@ and mask secret-looking query parameters (api keys, tokens). */
    public static function stripUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return mb_substr(preg_replace('/([?&][^=&#]*(?:key|token|secret|password|sig)[^=&#]*=)[^&#]*/i', '$1' . self::MASK, $url) ?? $url, 0, 1000);
        }

        $out = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');

        if (isset($parts['query']) && $parts['query'] !== '') {
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
                $k = urldecode((string) $k);
                $pairs[] = rawurlencode($k) . '=' . ($v !== null && self::isSecretKey($k) ? self::MASK : (string) $v);
            }
            $out .= '?' . implode('&', $pairs);
        }

        return mb_substr($out, 0, 1000);
    }

    /** Mask the text content of XML elements / attribute-keyed params whose name looks secret. */
    public static function redactXml(string $xml, string $mask = self::MASK): string
    {
        $result = preg_replace_callback(
            '~<((?:[\w.-]+:)?([\w.-]+))((?:\s[^<>]*?)?)>([^<]*)</\1\s*>~u',
            function (array $m) use ($mask) {
                return self::isSecretKey($m[2]) && trim($m[4]) !== '' ? "<{$m[1]}{$m[3]}>{$mask}</{$m[1]}>" : $m[0];
            },
            $xml
        );

        // Secrets carried as attribute values, e.g. <std:Param id="password">value</std:Param>.
        $result = preg_replace_callback(
            '~(<[^<>]*?\b(?:id|name|key)="([^"]*)"[^<>]*>)([^<]*)(</)~u',
            fn (array $m) => self::isSecretKey($m[2]) && trim($m[3]) !== '' ? $m[1] . $mask . $m[4] : $m[0],
            $result ?? $xml
        );

        return $result ?? $xml;
    }

    /** Last-resort redaction for free text (non JSON / XML): key=value and "key": "value" pairs. */
    public static function redactText(string $text, string $mask = self::MASK): string
    {
        $text = preg_replace('/(bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1' . $mask, $text) ?? $text;

        return preg_replace_callback(
            '/(["\']?)([A-Za-z0-9_.\-]{2,40})\1(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;}{\[]+)/',
            fn (array $m) => self::isSecretKey($m[2]) ? $m[1] . $m[2] . $m[1] . $m[3] . (in_array($m[4][0] ?? '', ['"', "'"], true) ? $m[4][0] . $mask . $m[4][0] : $mask) : $m[0],
            $text
        ) ?? $text;
    }

    /** Partially mask customer contact details (e-mail, phone, date of birth ...) at any depth. */
    public static function maskPii(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::maskPii($value);
            } elseif (is_string($key) && is_string($value) && $value !== '' && self::isPiiKey($key)) {
                $out[$key] = self::maskPiiValue($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function isPiiKey(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::PII_FRAGMENTS as $fragment) {
            if (str_contains($k, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function maskPiiValue(string $value): string
    {
        if (str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 1) . '***@' . $domain;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) >= 6) {
            return str_repeat('*', max(3, strlen($digits) - 3)) . substr($digits, -3);
        }

        return '***';
    }
}
