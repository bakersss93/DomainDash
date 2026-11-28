<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ticket routes now enforce tickets.create. Make sure the permission exists and that every existing
 * role holds it so nobody loses access. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'tickets.create', 'guard_name' => 'web']);

        // Ticket routes were previously open to every signed-in user, so every role that exists today keeps
        // that ability (including custom roles). New roles created later start without it.
        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            if (! $role->hasPermissionTo($permission)) {
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
