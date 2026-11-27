<?php

namespace App\Support\Monitoring;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Audit events that matter for incident response (sign-ins, MFA, impersonation, secret reveals, API keys, settings,
 * SSO, backups). They are kept for at least `monitoring.security_retention_floor_days` (365) whatever the
 * administrator sets for ordinary activity, so the trail survives a "reduce retention" action.
 */
class SecurityEvents
{
    /** Action prefixes / exact names. A trailing ".failed" variant is covered by the prefix match. */
    public const PATTERNS = [
        'auth.', 'impersonation.', 'domain.auth-code-read', 'hosting.password-reveal',
        'apikey.', 'settings.', 'saml.', 'mfa.', 'user.', 'backup.', 'monitoring.settings',
    ];

    public static function isSecurityAction(?string $action): bool
    {
        foreach (self::PATTERNS as $p) {
            if ($action !== null && str_starts_with($action, $p)) {
                return true;
            }
        }

        return false;
    }

    /** Add `action LIKE 'p%' OR ...` (security events) to a query. */
    public static function whereSecurity(EloquentBuilder|Builder $query): EloquentBuilder|Builder
    {
        return $query->where(function ($q) {
            foreach (self::PATTERNS as $p) {
                $q->orWhere('action', 'like', $p . '%');
            }
        });
    }

    /** Add the negation: ordinary (non security) events. */
    public static function whereNotSecurity(EloquentBuilder|Builder $query): EloquentBuilder|Builder
    {
        return $query->where(function ($q) {
            foreach (self::PATTERNS as $p) {
                $q->where('action', 'not like', $p . '%');
            }
        });
    }
}
