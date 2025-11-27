<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Traits\HasRoles;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Define base permissions
        $perms = [
            'domains.view','domains.manage','dns.manage','domains.transfer','domains.register','domains.renew',
            'services.view','services.manage','ssl.view','ssl.manage',
            'clients.view','clients.manage','users.view','users.manage','users.impersonate',
            'settings.manage','apikeys.manage','analytics.view','tickets.create','sync.run'
        ];

        foreach ($perms as $p) { Permission::findOrCreate($p); }

        $admin = Role::firstOrCreate(['name' => 'Administrator']);
        $tech  = Role::firstOrCreate(['name' => 'Technician']);
        $cust  = Role::firstOrCreate(['name' => 'Customer']);

        // Admin gets everything
        $admin->syncPermissions(Permission::all());

        // Technician defaults
        $tech->syncPermissions([
            'domains.view','dns.manage','domains.transfer','domains.register','services.view','ssl.view','tickets.create'
        ]);

        // Customer defaults (read-only on assigned resources + DNS manage as allowed via checkbox UI)
        $cust->syncPermissions(['domains.view','services.view','ssl.view','tickets.create']);

        // Ensure an initial admin exists (in case InitialAdminSeeder didn't run)
        $user = User::firstOrCreate(
            ['email' => 'admin@domaindash.com'],
            ['name' => 'System Admin','password' => Hash::make('password')]
        );
        $user->assignRole('Administrator');
    }
}
