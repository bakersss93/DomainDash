<?php

namespace App\Services\Auth;

use App\Models\Client;
use App\Models\SamlConnection;
use App\Models\Setting;
use App\Models\User;
use App\Services\Microsoft\MicrosoftSettings;
use App\Services\Saml\SsoPolicy;

/**
 * Which sign-in methods a user may use.
 *
 *   per audience (staff / clients):  both | microsoft_only          (Setting `sso`)
 *   per client (client users only):  inherit | both | microsoft_only (clients.login_method)
 *   per user exception:              users.password_login_allowed ("may also use password")
 *
 * "microsoft_only" closes PASSWORD sign-in. Microsoft sign-in stays open to everyone it is configured for.
 * A user with several clients is held to the strictest of them.
 */
class LoginPolicy
{
    public const BOTH = 'both';
    public const MICROSOFT_ONLY = 'microsoft_only';
    public const INHERIT = 'inherit';

    public static function settings(): array
    {
        return array_merge(['staff_login' => self::BOTH, 'client_login' => self::BOTH], (array) Setting::get('sso', []));
    }

    public static function staffMode(): string
    {
        return self::settings()['staff_login'] === self::MICROSOFT_ONLY ? self::MICROSOFT_ONLY : self::BOTH;
    }

    public static function clientMode(): string
    {
        return self::settings()['client_login'] === self::MICROSOFT_ONLY ? self::MICROSOFT_ONLY : self::BOTH;
    }

    /** Mode for this user before the personal exception: 'both' | 'microsoft_only'. */
    public static function modeFor(User $user): string
    {
        if (SsoPolicy::isStaff($user)) {
            return self::staffMode();
        }

        $global = self::clientMode();
        $overrides = $user->clients()->pluck('clients.login_method');
        if ($overrides->isEmpty()) {
            return $global;
        }

        $effective = $overrides->map(fn ($m) => match ($m) {
            self::BOTH => self::BOTH,
            self::MICROSOFT_ONLY => self::MICROSOFT_ONLY,
            default => $global,
        });

        return $effective->contains(self::MICROSOFT_ONLY) ? self::MICROSOFT_ONLY : self::BOTH;
    }

    public static function passwordAllowed(User $user): bool
    {
        return (bool) $user->password_login_allowed || self::modeFor($user) === self::BOTH;
    }

    /** Human description for the admin user page. */
    public static function describe(User $user): string
    {
        $mode = self::modeFor($user);
        $who = SsoPolicy::isStaff($user) ? 'staff' : 'client';
        $base = $mode === self::MICROSOFT_ONLY ? "Microsoft sign-in only ({$who} policy)" : "Password or Microsoft ({$who} policy)";

        return $user->password_login_allowed && $mode === self::MICROSOFT_ONLY ? $base.', password allowed by exception' : $base;
    }

    // ---- refusal rules ---------------------------------------------------------------------------------------

    /** @return list<string> reasons staff "Microsoft only" must not be (or stay) on */
    public static function staffMicrosoftOnlyProblems(array $ignoreUserIds = []): array
    {
        $problems = [];

        $hasAdmin = User::role('Administrator')->where('is_active', true)->where('password_login_allowed', true)
            ->whereNotIn('id', $ignoreUserIds)->exists();
        if (! $hasAdmin) {
            $problems[] = 'Keep at least one active Administrator with the "may also use password" exception before closing password sign-in for staff, or you could lock everyone out.';
        }
        if (! SamlConnection::query()->where('enabled', true)->exists()) {
            $problems[] = 'Enable a staff SAML connection before closing password sign-in for staff.';
        }

        return $problems;
    }

    /** @return list<string> */
    public static function clientMicrosoftOnlyProblems(): array
    {
        return MicrosoftSettings::configured() ? [] : ['Configure and enable Microsoft sign-in for clients before closing password sign-in for clients.'];
    }

    /** True when any part of the client policy (global or a client override) depends on Microsoft sign-in. */
    public static function clientsDependOnMicrosoft(): bool
    {
        return self::clientMode() === self::MICROSOFT_ONLY
            || Client::query()->where('login_method', self::MICROSOFT_ONLY)->exists();
    }
}
