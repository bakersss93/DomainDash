<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_admin_never_has_the_fixed_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::where('email', 'admin@domaindash.com')->firstOrFail();
        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertTrue($admin->hasRole('Administrator'));
    }
}
