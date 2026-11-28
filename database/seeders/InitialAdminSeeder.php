<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('email', 'admin@domaindash.com')->exists();
        $plain = $existing ? null : (env('ADMIN_INITIAL_PASSWORD') ?: \Illuminate\Support\Str::password(24));

        User::firstOrCreate(
            ['email' => 'admin@domaindash.com'],
            ['name' => 'System Admin', 'password' => Hash::make($plain ?? \Illuminate\Support\Str::random(40))]
        );

        if ($plain !== null && ! env('ADMIN_INITIAL_PASSWORD') && $this->command) {
            $this->command->warn("Initial admin created: admin@domaindash.com / {$plain}  (shown once; change it after first sign-in)");
        }
    }
}
