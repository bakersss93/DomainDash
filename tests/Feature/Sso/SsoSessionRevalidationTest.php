<?php

namespace Tests\Feature\Sso;

use App\Models\SamlConnection;
use App\Models\User;
use App\Services\Saml\ConnectionValidator;
use App\Services\Saml\SsoPolicy;
use App\Support\Security\StaffPermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SsoSessionRevalidationTest extends SsoTestCase
{
    private function loggedIn(array $attrs = []): array
    {
        $c = $this->staffConnection($attrs + ['default_role' => 'Technician', 'skip_local_mfa' => true]);
        $this->ssoLogin($c, ['email' => 'alice@contoso.com'])->assertRedirect();
        $this->assertAuthenticated();

        return [$c, User::where('email', 'alice@contoso.com')->firstOrFail()];
    }

    /** Forget the guard's cached user so each request re-reads the (changed) row, like a real new request. */
    private function dash()
    {
        $this->app['auth']->forgetGuards();

        return $this->get('/dashboard');
    }

    public function test_session_survives_while_connection_is_valid(): void
    {
        $this->loggedIn();
        $this->dash()->assertSuccessful();
    }

    public function test_disabling_the_connection_ends_the_session(): void
    {
        [$c] = $this->loggedIn();
        $c->forceFill(['enabled' => false])->save();

        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deleting_the_connection_ends_the_session(): void
    {
        [$c, $u] = $this->loggedIn();
        $u->forceFill(['saml_connection_id' => null])->save();
        $c->delete();

        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_unlinking_or_rebinding_the_user_ends_the_session(): void
    {
        [$c, $u] = $this->loggedIn();
        $u->forceFill(['saml_connection_id' => null])->save();

        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivated_user_loses_sso_session(): void
    {
        [, $u] = $this->loggedIn();
        $u->forceFill(['is_active' => false])->save();

        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_mfa_bypass_is_dropped_when_connection_stops_trusting_entra(): void
    {
        [$c, $u] = $this->loggedIn();
        $this->dash()->assertSuccessful();

        $c->forceFill(['skip_local_mfa' => false])->save();
        $u->forceFill(['mfa_preference' => 'enforced'])->save();
        $u->assignRole('Administrator'); // requires MFA by policy

        $res = $this->dash();
        $res->assertViewIs('auth.mfa-required');
    }

    public function test_create_form_defaults_and_warns(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');
        Permission::findOrCreate('settings.manage');

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $res = $this->get(route('admin.sso.create'));
        $res->assertSuccessful()->assertSee('Conditional Access');
        $this->assertDoesNotMatchRegularExpression('/name="skip_local_mfa" value="1"[^>]*checked/', $res->getContent());
    }

    public function test_customer_safe_role_uses_shared_staff_permission_list(): void
    {
        Permission::findOrCreate('analytics.view');
        $this->assertContains('analytics.view', StaffPermissions::STAFF_ONLY);
        Role::findOrCreate('Customer')->givePermissionTo('analytics.view');

        $this->assertFalse(SsoPolicy::isCustomerSafeRole('Customer'));
    }

    public function test_connections_default_to_local_mfa_and_are_always_staff(): void
    {
        $base = [
            'name' => 'x', 'entra_tenant_id' => \Tests\Support\SamlFixture::TENANT, 'idp_entity_id' => 'x', 'idp_sso_url' => 'https://x.test', 'idp_x509_cert' => 'x',
        ];
        $this->assertFalse(SamlConnection::create($base + ['type' => 'staff'])->fresh()->skip_local_mfa);
        $c = SamlConnection::create($base + ['type' => 'client'])->fresh();
        $this->assertFalse($c->skip_local_mfa);
        $this->assertSame('staff', $c->type, 'a client type can never be stored');
    }

    public function test_domain_cannot_be_claimed_by_two_connections(): void
    {
        $this->staffConnection([], ['contoso.com']);
        $v = new ConnectionValidator();
        $base = [
            'name' => 'n', 'entra_tenant_id' => \Tests\Support\SamlFixture::TENANT, 'idp_entity_id' => 'https://sts.windows.net/'.\Tests\Support\SamlFixture::TENANT.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/x/saml2', 'idp_x509_cert' => 'x', 'domains' => 'CONTOSO.com',
        ];

        [, $e] = $v->validate($base);
        $this->assertArrayHasKey('domains', $e);
    }
}
