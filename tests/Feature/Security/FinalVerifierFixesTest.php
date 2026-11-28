<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Saml\SsoPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinalVerifierFixesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        Role::findOrCreate('Customer');
        $u = User::factory()->create();
        $u->assignRole('Customer');

        return $u;
    }

    public function test_confirm_is_refused_once_mfa_is_already_confirmed(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson(route('me.mfa.start'))->assertOk();
        $secret = decrypt($u->fresh()->two_factor_secret);
        $g = new Google2FA();

        $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => $g->oathTotp($secret, $g->getTimestamp())])
            ->assertOk()->assertJsonStructure(['recovery_codes']);

        // A second valid code must not re-reveal the recovery codes.
        $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => $g->oathTotp($secret, $g->getTimestamp() - 1)])
            ->assertStatus(409)->assertJsonMissingPath('recovery_codes');
    }

    public function test_confirm_endpoint_is_throttled(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson(route('me.mfa.start'))->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => '000000'])->assertStatus(422);
        }

        $this->actingAs($u)->postJson(route('me.mfa.confirm'), ['code' => '000000'])->assertStatus(429);
    }

    public function test_login_identify_has_a_per_ip_limit_across_different_emails(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->post(route('login.identify'), ['email' => "user{$i}@example.com"])->assertStatus(302);
        }

        $this->post(route('login.identify'), ['email' => 'another@example.com'])->assertStatus(429);
    }

    public function test_unknown_email_password_login_still_fails_generically(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_staff_classification_is_the_same_everywhere(): void
    {
        foreach (['Administrator', 'Technician', 'Customer', 'Hybrid', 'Viewer'] as $r) {
            Role::findOrCreate($r);
        }
        $staffPerm = Permission::findOrCreate(\App\Support\Security\StaffPermissions::STAFF_ONLY[0]);
        Role::findByName('Hybrid')->givePermissionTo($staffPerm);

        $this->assertTrue(SsoPolicy::isStaffRole('Administrator'));
        $this->assertTrue(SsoPolicy::isStaffRole('Technician'));
        $this->assertFalse(SsoPolicy::isStaffRole('Hybrid'), 'custom roles are never staff, matching User::isStaff()');
        $this->assertFalse(SsoPolicy::isCustomerSafeRole('Hybrid'), 'a custom role holding a staff-only permission is not customer-safe either');
        $this->assertTrue(SsoPolicy::isCustomerSafeRole('Viewer'));
        $this->assertFalse(SsoPolicy::isCustomerSafeRole('DoesNotExist'));

        $hybrid = User::factory()->create();
        $hybrid->assignRole('Hybrid');
        $this->assertFalse($hybrid->isStaff());
        $this->assertTrue(SsoPolicy::isStaff($hybrid), 'not customer-safe, so refused for client Microsoft sign-in');
    }

    public function test_ticket_permission_migration_grants_every_existing_role(): void
    {
        $custom = Role::findOrCreate('Reseller Contact');
        Role::findOrCreate('Customer');

        $migration = require database_path('migrations/2026_12_08_000001_grant_tickets_create_to_customer.php');
        $migration->up();
        $migration->up(); // idempotent

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($custom->fresh()->hasPermissionTo('tickets.create'));
        $this->assertTrue(Role::findByName('Customer')->hasPermissionTo('tickets.create'));
        $this->assertSame(1, Permission::where('name', 'tickets.create')->count());
    }
}
