<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Customer-facing internet permissions. Idempotent; the Customer role (if present) may view, never diagnose, by default. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $view = Permission::firstOrCreate(['name' => 'internet.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'internet.diagnostics', 'guard_name' => 'web']);

        $customer = Role::query()->where('name', 'Customer')->where('guard_name', 'web')->first();
        if ($customer && ! $customer->hasPermissionTo($view)) {
            $customer->givePermissionTo($view);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions are left in place: removing them would silently revoke access granted since.
    }
};
