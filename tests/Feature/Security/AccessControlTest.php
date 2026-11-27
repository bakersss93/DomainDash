<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled_by_default(): void
    {
        $this->assertFalse(config('domaindash.allow_registration'));

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Eve', 'email' => 'eve@example.com',
            'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('users', ['email' => 'eve@example.com']);
    }

    public function test_user_implements_must_verify_email_and_unverified_users_are_blocked(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User());

        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_administrator_without_mfa_is_redirected_to_setup(): void
    {
        Role::findOrCreate('Administrator');
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $this->actingAs($admin)->get('/admin/permissions')->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->getJson('/admin/permissions')->assertForbidden();
    }

    public function test_mfa_enforcement_can_be_lifted_for_break_glass(): void
    {
        config(['domaindash.mfa.enforce' => false]);
        Role::findOrCreate('Administrator');
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $this->assertFalse($admin->requiresMfa());
    }

    public function test_security_headers_are_sent(): void
    {
        $this->withoutVite();
        $res = $this->get('/login');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options');
        $res->assertHeader('Referrer-Policy');
        $res->assertHeader('Permissions-Policy');
        $this->assertTrue($res->headers->has('Content-Security-Policy-Report-Only') || $res->headers->has('Content-Security-Policy'));
    }
}
