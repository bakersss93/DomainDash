<?php

namespace App\Services\Saml;

use App\Models\User;
use Spatie\Permission\Models\Role;

class SsoPolicy
{
    /**
     * Staff roles are exactly the internal staff roles (StaffPermissions::STAFF_ROLES). This matches
     * User::isStaff(), the policies and PermissionsController, so every part of the app agrees on who is staff.
     */
    public static function isStaffRole(string $roleName): bool
    {
        return in_array($roleName, \App\Support\Security\StaffPermissions::STAFF_ROLES, true);
    }

    /**
     * A role customer-class users may hold: it exists, is not a staff role and holds no staff-only permission.
     * A custom role that somehow holds a staff-only permission is neither staff nor customer-safe, so it can
     * be neither mapped from Entra groups nor used for client sign-in (fail closed).
     */
    public static function isCustomerSafeRole(string $roleName): bool
    {
        if (self::isStaffRole($roleName)) {
            return false;
        }

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

        return $role !== null && $role->permissions->pluck('name')
            ->intersect(\App\Support\Security\StaffPermissions::STAFF_ONLY)->isEmpty();
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
