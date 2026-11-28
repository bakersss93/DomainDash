<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Support > Help: the permission that gates every integration-configuration article. Idempotent.
 * Administrator and Technician get it by default; the owner can revoke it per role on Admin > Roles & Permissions.
 * It is staff-only (StaffPermissions::STAFF_ONLY), so it can never be granted to a customer role.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'help.integrations.view', 'guard_name' => 'web']);

        foreach (['Administrator', 'Technician'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: removing it would silently revoke access granted since.
    }
};
