<?php

namespace App\Services\Saml;

use App\Models\User;
use Spatie\Permission\Models\Role;

class SsoPolicy
{
    /** @return list<string> */
    public static function customerRoleNames(): array
    {
        return array_values((array) config('saml.customer_roles', ['Customer']));
    }

    /** A role customer-class users may hold: on the customer allow-list and without any privileged permission. */
    public static function isCustomerSafeRole(string $roleName): bool
    {
        if (! in_array($roleName, self::customerRoleNames(), true)) {
            return false;
        }

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            return false;
        }

        $privileged = \App\Support\Security\StaffPermissions::STAFF_ONLY;

        return $role->permissions->pluck('name')->intersect($privileged)->isEmpty();
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
