<?php

namespace App\Services\Microsoft;

use App\Models\Setting;

/**
 * "Sign in with Microsoft" for client users (one multi-tenant Entra app registration owned by the operator).
 * Stored in the encrypted `microsoft_signin` setting; the client secret never leaves the server.
 */
class MicrosoftSettings
{
    public const KEY = 'microsoft_signin';

    public static function all(): array
    {
        return array_merge([
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'allowed_tenants' => [],
            'trust_mfa_clients' => true,
        ], (array) Setting::get(self::KEY, []));
    }

    public static function enabled(): bool
    {
        return (bool) self::all()['enabled'];
    }

    public static function clientId(): string
    {
        return trim((string) self::all()['client_id']);
    }

    public static function clientSecret(): string
    {
        return (string) self::all()['client_secret'];
    }

    /** Enabled AND complete: the only state in which the sign-in flow runs. */
    public static function configured(): bool
    {
        return self::enabled() && self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** @return list<string> lowercase tenant GUIDs; empty = any tenant (subject to user matching) */
    public static function allowedTenants(): array
    {
        return array_values(array_unique(array_map('strtolower', array_map('trim', (array) self::all()['allowed_tenants']))));
    }

    public static function trustMfa(): bool
    {
        return (bool) self::all()['trust_mfa_clients'];
    }

    /** Derived from APP_URL (never from the request Host header). */
    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/microsoft/callback';
    }
}
