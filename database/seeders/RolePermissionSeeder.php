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
            'internet.view','internet.order','internet.manage','internet.cancel','internet.diagnostics',
            'clients.view','clients.manage','users.view','users.manage','users.impersonate',
            'settings.manage','apikeys.manage','analytics.view','tickets.create','sync.run','domain-pricing.view','domain-pricing.manage'
        ];

        foreach ($perms as $p) { Permission::findOrCreate($p); }

        $admin = Role::firstOrCreate(['name' => 'Administrator']);
        $tech  = Role::firstOrCreate(['name' => 'Technician']);
        $cust  = Role::firstOrCreate(['name' => 'Customer']);

        // Admin gets everything
        $admin->syncPermissions(Permission::all());

        // Technician defaults
        $tech->syncPermissions([
            'domains.view','dns.manage','domains.transfer','domains.register','services.view','ssl.view',
            'internet.view','internet.order','internet.manage','tickets.create','domain-pricing.view'
        ]);

        // Customer defaults (read-only on assigned resources + DNS manage as allowed via checkbox UI)
        $cust->syncPermissions(['domains.view','services.view','ssl.view','internet.view','tickets.create']);

        // Ensure an initial admin exists (in case InitialAdminSeeder didn't run)
        $existing = User::where('email', 'admin@domaindash.com')->exists();
        $plain = null;
        if (! $existing) {
            // Never a fixed password. Use ADMIN_INITIAL_PASSWORD if supplied, otherwise generate one and print it once.
            $plain = env('ADMIN_INITIAL_PASSWORD') ?: \Illuminate\Support\Str::password(24);
        }
        $user = User::firstOrCreate(
            ['email' => 'admin@domaindash.com'],
            ['name' => 'System Admin', 'password' => Hash::make($plain ?? \Illuminate\Support\Str::random(40))]
        );
        $user->assignRole('Administrator');

        if ($plain !== null && ! env('ADMIN_INITIAL_PASSWORD') && $this->command) {
            $this->command->warn("Initial admin created: admin@domaindash.com / {$plain}  (shown once; change it after first sign-in)");
        }
    }
}
