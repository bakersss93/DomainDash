<?php

namespace App\Services\Saml;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class SsoPolicy
{
    /** @return list<string> */
    public static function customerRoleNames(): array
    {
        return array_values((array) config('saml.customer_roles', ['Customer']));
    }

    /** A role a CLIENT connection may assign: on the customer allow-list and without any privileged permission. */
    public static function isCustomerSafeRole(string $roleName): bool
    {
        if (! in_array($roleName, self::customerRoleNames(), true)) {
            return false;
        }

        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            return false;
        }

        $privileged = (array) config('saml.privileged_permissions', []);

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

    public static function settings(): array
    {
        return array_merge(['enforce_staff' => false], (array) Setting::get('sso', []));
    }

    public static function enforceStaff(): bool
    {
        return (bool) (self::settings()['enforce_staff'] ?? false);
    }

    public static function passwordLoginBlocked(User $user): bool
    {
        return self::enforceStaff() && ! $user->is_break_glass && self::isStaff($user);
    }

    /** Called from the password login pipeline. */
    public static function assertPasswordLoginAllowed(User $user): void
    {
        if (self::passwordLoginBlocked($user)) {
            throw ValidationException::withMessages([
                'email' => 'Password sign-in is disabled for staff. Use "Sign in with Microsoft".',
            ]);
        }
    }
}
