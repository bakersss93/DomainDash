<?php

namespace App\Services\Saml;

use App\Models\User;
use Spatie\Permission\Models\Role;

class SsoPolicy
{
    /**
     * A role is staff-class when it is one of the internal staff roles or holds ANY staff-only permission. This is
     * derived, so custom roles from Settings > Permissions classify themselves: grant a staff permission and the role
     * becomes staff-class, with no config list to keep in sync.
     */
    public static function isStaffRole(string $roleName): bool
    {
        if (in_array($roleName, \App\Support\Security\StaffPermissions::STAFF_ROLES, true)) {
            return true;
        }

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

        return $role === null || $role->permissions->pluck('name')
            ->intersect(\App\Support\Security\StaffPermissions::STAFF_ONLY)->isNotEmpty();
    }

    /** A role customer-class users may hold: it exists and is not staff-class. */
    public static function isCustomerSafeRole(string $roleName): bool
    {
        return ! self::isStaffRole($roleName);
    }

    /** The enabled staff SAML connection that serves this email's domain, if any. */
    public static function staffConnectionForEmail(string $email): ?\App\Models\SamlConnection
    {
        $domain = str_contains($email, '@') ? strtolower(substr(strrchr($email, '@'), 1)) : '';
        if ($domain === '') {
            return null;
        }

        return \App\Models\SamlConnection::query()
            ->where('enabled', true)
            ->whereHas('domains', fn ($q) => $q->where('domain', $domain))
            ->first();
    }

    /** Staff = anything that is not purely customer-class (a user with no roles is treated as staff: fail closed). */
    public static function isStaff(User $user): bool
    {
        $roles = $user->getRoleNames();

        if ($roles->isEmpty()) {
            return true;
        }

        return $roles->contains(fn ($r) => ! self::isCustomerSafeRole($r));
    }

    /** Roles a staff SAML connection may assign (everything that is not customer-class). */
    public static function staffRoleNames(): array
    {
        return Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')
            ->reject(fn ($r) => self::isCustomerSafeRole($r))->values()->all();
    }
}
