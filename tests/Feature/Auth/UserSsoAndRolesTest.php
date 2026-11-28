<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Admin\UsersController;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Saml\SsoPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Sso\SsoTestCase;

class UserSsoAndRolesTest extends SsoTestCase
{
    private function admin(): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    private function payload(User $u, array $over = []): array
    {
        return $over + ['first_name' => 'A', 'last_name' => 'B', 'email' => $u->email, 'role' => 'Technician', 'mfa_preference' => 'enabled', 'is_active' => '1'];
    }

    // ---- sso_enabled gate ----------------------------------------------------------------------------------

    public function test_saml_refuses_an_existing_user_without_sso_enabled_and_audits_it(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician']);
        $user = User::factory()->create(['email' => 'tina@contoso.com', 'sso_enabled' => false]);
        $user->assignRole('Technician');

        $this->ssoLogin($c, ['email' => 'tina@contoso.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->saml_object_id, 'must not even link');
        $this->assertTrue(AuditLog::where('action', 'sso.login.failed')->where('description', 'like', '%sso_not_enabled%')->exists());

        $user->forceFill(['sso_enabled' => true])->save();
        $this->ssoLogin($c, ['email' => 'tina@contoso.com'])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_jit_created_staff_get_sso_enabled(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician', 'jit_provisioning' => true]);

        $this->ssoLogin($c, ['email' => 'new@contoso.com'])->assertRedirect();

        $this->assertTrue((bool) User::where('email', 'new@contoso.com')->firstOrFail()->sso_enabled);
    }

    public function test_new_users_default_to_sso_disabled(): void
    {
        $this->assertFalse((bool) User::factory()->create()->fresh()->sso_enabled);
    }

    public function test_migration_backfills_linked_users(): void
    {
        $migration = require base_path('database/migrations/2026_12_07_000001_add_sso_enabled_to_users.php');
        $migration->down();

        $now = now();
        $row = fn ($email, $extra = []) => ['name' => $email, 'email' => $email, 'password' => 'x', 'created_at' => $now, 'updated_at' => $now] + $extra;
        DB::table('users')->insert($row('saml@x.com', ['saml_object_id' => 'oid-1']));
        DB::table('users')->insert($row('ms@x.com', ['microsoft_oid' => 'oid-2', 'microsoft_tid' => 'tid-2']));
        DB::table('users')->insert($row('plain@x.com'));

        $migration->up();

        $this->assertSame(1, (int) DB::table('users')->where('email', 'saml@x.com')->value('sso_enabled'));
        $this->assertSame(1, (int) DB::table('users')->where('email', 'ms@x.com')->value('sso_enabled'));
        $this->assertSame(0, (int) DB::table('users')->where('email', 'plain@x.com')->value('sso_enabled'));
    }

    public function test_admin_toggles_sso_on_the_user_page_with_audit_and_session_revoke(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $user->assignRole('Technician');

        $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->assertSee('Enable Microsoft sign-in');

        $this->put(route('admin.users.update', $user), $this->payload($user, ['sso_enabled' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue((bool) $user->fresh()->sso_enabled);
        $this->assertTrue(AuditLog::where('action', 'user.sso_enabled.changed')->exists());

        $this->put(route('admin.users.update', $user), $this->payload($user, ['sso_enabled' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse((bool) $user->fresh()->sso_enabled);
    }

    // ---- roles from the permissions area -----------------------------------------------------------------------

    public function test_role_lists_include_custom_roles_and_assignment_works(): void
    {
        Role::findOrCreate('Auditor')->givePermissionTo('domains.view');
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->get(route('admin.users.create'))->assertOk()->assertSee('value="Auditor"', false);
        $this->get(route('admin.users'))->assertOk()->assertSee('value="Auditor"', false);

        $user = User::factory()->create();
        $user->assignRole('Customer');
        $this->get(route('admin.users.edit', $user))->assertOk()->assertSee('value="Auditor"', false);

        $this->put(route('admin.users.update', $user), $this->payload($user, ['role' => 'Auditor']))->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->hasRole('Auditor'));

        $this->post(route('admin.users.store'), [
            'first_name' => 'N', 'last_name' => 'U', 'email' => 'nu@example.com', 'role' => 'Auditor', 'mfa_preference' => 'enabled',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users'));
        $this->assertTrue(User::where('email', 'nu@example.com')->firstOrFail()->hasRole('Auditor'));
    }

    public function test_unknown_roles_are_rejected(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.update', $user), $this->payload($user, ['role' => 'Wizard']))->assertSessionHasErrors('role');
    }

    public function test_non_administrators_cannot_assign_the_administrator_role(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Technician');
        $victim = User::factory()->create();
        $victim->assignRole('Customer');

        $req = Request::create('/x', 'PUT', $this->payload($victim, ['role' => 'Administrator']));
        $req->setUserResolver(fn () => $actor);
        $req->setLaravelSession(app('session.store'));

        try {
            app(UsersController::class)->update($req, $victim);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }
        $this->assertTrue($victim->fresh()->hasRole('Customer'));

        // ... nor edit an Administrator, and the role is not even offered.
        $admin = $this->admin();
        $req = Request::create('/x', 'PUT', $this->payload($admin, ['role' => 'Technician']));
        $req->setUserResolver(fn () => $actor);
        $this->expectException(ValidationException::class);
        app(UsersController::class)->update($req, $admin);
    }

    public function test_administrator_role_is_hidden_from_non_administrators(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Technician');
        $req = Request::create('/x');
        $req->setUserResolver(fn () => $actor);

        $view = app(UsersController::class)->create($req);

        $this->assertNotContains('Administrator', $view->getData()['roles']->pluck('name')->all());
        $this->assertContains('Technician', $view->getData()['roles']->pluck('name')->all());
    }

    public function test_custom_roles_classify_as_staff_or_customer_by_staff_only_permissions(): void
    {
        Role::findOrCreate('Viewer')->givePermissionTo('domains.view');
        Role::findOrCreate('Manager')->givePermissionTo('users.manage');
        Role::findOrCreate('Empty');

        $this->assertTrue(SsoPolicy::isCustomerSafeRole('Viewer'));
        $this->assertTrue(SsoPolicy::isCustomerSafeRole('Customer'));
        $this->assertTrue(SsoPolicy::isCustomerSafeRole('Empty'));
        $this->assertFalse(SsoPolicy::isCustomerSafeRole('Manager'));
        $this->assertFalse(SsoPolicy::isCustomerSafeRole('Technician'), 'internal staff roles are always staff');
        $this->assertFalse(SsoPolicy::isCustomerSafeRole('Administrator'));
        $this->assertFalse(SsoPolicy::isCustomerSafeRole('DoesNotExist'));

        $v = User::factory()->create();
        $v->assignRole('Viewer');
        $m = User::factory()->create();
        $m->assignRole('Manager');
        $mixed = User::factory()->create();
        $mixed->assignRole(['Viewer', 'Manager']);
        $this->assertFalse(SsoPolicy::isStaff($v));
        $this->assertTrue(SsoPolicy::isStaff($m));
        $this->assertTrue(SsoPolicy::isStaff($mixed));
        $this->assertContains('Manager', SsoPolicy::staffRoleNames());
        $this->assertNotContains('Viewer', SsoPolicy::staffRoleNames());
    }
}
