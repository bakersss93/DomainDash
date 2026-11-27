<?php

namespace Tests\Feature\Microsoft;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\LoginPolicy;
use App\Services\Microsoft\MicrosoftSettings;
use Tests\Support\SamlFixture;

class MicrosoftSessionAndPolicyTest extends MicrosoftTestCase
{
    private function admin(array $extra = []): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()] + $extra);
        $u->assignRole('Administrator');

        return $u;
    }

    private function dash()
    {
        $this->nextRequest();

        return $this->get('/dashboard');
    }

    // ---- MFA ---------------------------------------------------------------------------------------------------

    public function test_trusting_microsoft_satisfies_mandatory_local_mfa(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => true]);
        $user = $this->customer('amy@acme.com', null, ['mfa_preference' => 'enforced']);
        $this->signIn(['amr' => ['pwd', 'mfa']])->assertRedirect(config('fortify.home', '/dashboard'));

        $this->assertTrue(session('auth.mfa_satisfied'));
        $this->assertSame(self::OID, session('auth.microsoft_oid'));
        $res = $this->dash()->assertSuccessful();
        $this->assertNotSame('auth.mfa-required', $res->original instanceof \Illuminate\View\View ? $res->original->name() : null);
        $this->assertNotNull($user->fresh()->microsoft_oid);
    }

    public function test_not_trusting_microsoft_keeps_local_mfa(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => false]);
        $this->customer('amy@acme.com', null, ['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);

        $this->signIn()->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertNull(session('auth.via'));

        // Not enrolled: signed in, but the mandatory-MFA policy blocks everything except enrolment.
        $this->customer('bob@acme.com', Client::first(), ['mfa_preference' => 'enforced']);
        $this->signIn(['preferred_username' => 'bob@acme.com', 'oid' => 'eeeeeeee-1111-2222-3333-444444444444'])->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertAuthenticated();
        $this->assertFalse((bool) session('auth.mfa_satisfied'));
        $this->dash()->assertViewIs('auth.mfa-required');
    }

    public function test_mfa_trust_is_dropped_mid_session_when_the_setting_is_switched_off(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => true]);
        $this->customer('amy@acme.com', null, ['mfa_preference' => 'enforced']);
        $this->signIn(['amr' => ['mfa']]);
        $this->dash()->assertSuccessful();

        $this->configureMicrosoft(['trust_mfa_clients' => false]);
        $this->dash()->assertViewIs('auth.mfa-required');
    }

    // ---- session re-validation -----------------------------------------------------------------------------

    public function test_session_ends_when_the_feature_is_disabled(): void
    {
        $this->customer();
        $this->signIn();
        $this->dash()->assertSuccessful();

        $this->configureMicrosoft(['enabled' => false]);
        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_session_ends_when_an_administrator_unlinks_the_user(): void
    {
        $user = $this->customer();
        $this->signIn();
        $this->dash()->assertSuccessful();

        $user->fresh()->forceFill(['microsoft_oid' => null, 'microsoft_tid' => null])->save();
        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_session_ends_when_the_link_changes_or_the_user_becomes_staff_or_inactive(): void
    {
        $user = $this->customer();
        $this->signIn();

        $user->fresh()->forceFill(['microsoft_oid' => 'dddddddd-1111-2222-3333-444444444444'])->save();
        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();

        $user->fresh()->forceFill(['microsoft_oid' => self::OID])->save();
        $this->signIn(['preferred_username' => 'zzz@acme.com']);
        $this->assertAuthenticated();
        $user->fresh()->syncRoles(['Technician']);
        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();

        $user->fresh()->syncRoles(['Customer']);
        $this->signIn();
        $user->fresh()->forceFill(['is_active' => false])->save();
        $this->dash()->assertRedirect(route('login'));
    }

    // ---- login-method policy -----------------------------------------------------------------------------

    private function passwordLogin(string $email)
    {
        $this->post('/login', ['email' => $email, 'password' => 'password']);
    }

    public function test_client_microsoft_only_blocks_client_passwords_generically_and_audits(): void
    {
        $user = $this->customer('amy@acme.com');
        Setting::put('sso', ['client_login' => 'microsoft_only']);

        $this->post('/login', ['email' => 'amy@acme.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $blocked = session('errors')->first('email');
        $this->post('/login', ['email' => 'amy@acme.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertSame($blocked, session('errors')->first('email'));
        $log = AuditLog::where('action', 'auth.password.blocked_by_policy')->firstOrFail();
        $this->assertSame('microsoft_only', $log->context['mode']);

        // Microsoft sign-in still works.
        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_per_user_exception_beats_the_policy(): void
    {
        $user = $this->customer('amy@acme.com', null, ['password_login_allowed' => true]);
        Setting::put('sso', ['client_login' => 'microsoft_only']);

        $this->passwordLogin('amy@acme.com');
        $this->assertAuthenticatedAs($user);
    }

    public function test_per_client_override_in_both_directions(): void
    {
        $open = Client::create(['business_name' => 'Open', 'login_method' => 'both']);
        $closed = Client::create(['business_name' => 'Closed', 'login_method' => 'microsoft_only']);
        $inherit = Client::create(['business_name' => 'Inherit']);
        $a = $this->customer('open@x.com', $open);
        $b = $this->customer('closed@x.com', $closed);
        $c = $this->customer('inherit@x.com', $inherit);

        // Global both, one client closed.
        $this->configureMicrosoft();
        $this->passwordLogin('closed@x.com');
        $this->assertGuest();
        $this->passwordLogin('open@x.com');
        $this->assertAuthenticatedAs($a);
        auth()->logout();
        $this->passwordLogin('inherit@x.com');
        $this->assertAuthenticatedAs($c);
        auth()->logout();

        // Global microsoft_only, one client open.
        Setting::put('sso', ['client_login' => 'microsoft_only']);
        $this->passwordLogin('inherit@x.com');
        $this->assertGuest();
        $this->passwordLogin('open@x.com');
        $this->assertAuthenticatedAs($a);
        $this->assertNotNull($b);
    }

    public function test_a_user_with_several_clients_gets_the_strictest_policy(): void
    {
        $open = Client::create(['business_name' => 'Open', 'login_method' => 'both']);
        $closed = Client::create(['business_name' => 'Closed', 'login_method' => 'microsoft_only']);
        $u = $this->customer('multi@x.com', $open);
        $u->clients()->attach($closed->id);

        $this->assertSame('microsoft_only', LoginPolicy::modeFor($u));
        $this->passwordLogin('multi@x.com');
        $this->assertGuest();
    }

    public function test_client_policy_does_not_touch_staff_and_staff_policy_does_not_touch_clients(): void
    {
        $tech = User::factory()->create(['email' => 'tech@x.com']);
        $tech->assignRole('Technician');
        $cust = $this->customer('cust@x.com');

        Setting::put('sso', ['client_login' => 'microsoft_only']);
        $this->passwordLogin('tech@x.com');
        $this->assertAuthenticatedAs($tech);
        auth()->logout();

        Setting::put('sso', ['staff_login' => 'microsoft_only']);
        $this->passwordLogin('cust@x.com');
        $this->assertAuthenticatedAs($cust);
    }

    public function test_client_microsoft_only_is_refused_unless_microsoft_sign_in_is_configured(): void
    {
        $this->actingAs($this->admin());
        $this->configureMicrosoft(['enabled' => false]);

        $this->post(route('admin.sso.settings'), ['staff_login' => 'both', 'client_login' => 'microsoft_only'])->assertSessionHasErrors('client_login');
        $this->assertSame('both', LoginPolicy::clientMode());

        $client = Client::create(['business_name' => 'Acme']);
        $this->put(route('admin.clients.update', $client), ['business_name' => 'Acme', 'login_method' => 'microsoft_only'])->assertSessionHasErrors('login_method');
        $this->assertSame('inherit', $client->fresh()->login_method);

        $this->configureMicrosoft();
        $this->post(route('admin.sso.settings'), ['staff_login' => 'both', 'client_login' => 'microsoft_only'])->assertSessionHasNoErrors();
        $this->assertSame('microsoft_only', LoginPolicy::clientMode());
        $this->put(route('admin.clients.update', $client), ['business_name' => 'Acme', 'login_method' => 'microsoft_only', 'microsoft_tenant_id' => strtoupper(self::TID)])->assertSessionHasNoErrors();
        $client->refresh();
        $this->assertSame('microsoft_only', $client->login_method);
        $this->assertSame(self::TID, $client->microsoft_tenant_id);
    }

    public function test_microsoft_sign_in_cannot_be_disabled_while_clients_depend_on_it(): void
    {
        $this->actingAs($this->admin());
        Setting::put('sso', ['client_login' => 'microsoft_only']);

        $this->post(route('admin.sso.microsoft'), ['enabled' => '0', 'client_id' => self::APP_ID])->assertSessionHasErrors('enabled');
        $this->assertTrue(MicrosoftSettings::enabled());

        Setting::put('sso', ['client_login' => 'both']);
        Client::create(['business_name' => 'Closed', 'login_method' => 'microsoft_only']);
        $this->post(route('admin.sso.microsoft'), ['enabled' => '0', 'client_id' => self::APP_ID])->assertSessionHasErrors('enabled');
    }

    public function test_staff_microsoft_only_needs_a_password_admin_and_an_enabled_saml_connection(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->post(route('admin.sso.settings'), ['staff_login' => 'microsoft_only', 'client_login' => 'both'])->assertSessionHasErrors('staff_login');
        $admin->forceFill(['password_login_allowed' => true])->save();
        $this->post(route('admin.sso.settings'), ['staff_login' => 'microsoft_only', 'client_login' => 'both'])->assertSessionHasErrors('staff_login');
        SamlFixture::connection(['type' => 'staff'], ['contoso.com']);
        $this->post(route('admin.sso.settings'), ['staff_login' => 'microsoft_only', 'client_login' => 'both'])->assertSessionHasNoErrors();

        // A disabled-only exception holder does not count.
        $admin->forceFill(['is_active' => false])->save();
        $this->assertNotEmpty(LoginPolicy::staffMicrosoftOnlyProblems());
    }

    public function test_the_user_edit_page_shows_the_effective_policy_and_the_exception_checkbox(): void
    {
        $admin = $this->admin();
        $user = $this->customer('amy@acme.com');
        Setting::put('sso', ['client_login' => 'microsoft_only']);

        $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()
            ->assertSee('Microsoft sign-in only (client policy)')->assertSee('password_login_allowed', false);
    }

    // ---- admin settings ------------------------------------------------------------------------------------------

    public function test_client_secret_is_stored_encrypted_masked_and_kept_when_left_blank(): void
    {
        $this->actingAs($this->admin());
        $this->configureMicrosoft(['enabled' => false, 'client_id' => '', 'client_secret' => '']);

        $this->post(route('admin.sso.microsoft'), [
            'enabled' => '1', 'client_id' => strtoupper(self::APP_ID), 'client_secret' => self::SECRET,
            'allowed_tenants' => self::TID."\n".strtoupper(self::OTHER_TID), 'trust_mfa_clients' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::SECRET, MicrosoftSettings::clientSecret());
        $this->assertSame(self::APP_ID, MicrosoftSettings::clientId());
        $this->assertSame([self::TID, self::OTHER_TID], MicrosoftSettings::allowedTenants());
        $raw = \Illuminate\Support\Facades\DB::table('settings')->where('key', 'microsoft_signin')->value('value');
        $this->assertStringNotContainsString(self::SECRET, (string) $raw, 'encrypted at rest');

        $page = $this->get(route('admin.sso.index'))->assertOk();
        $page->assertDontSee(self::SECRET)->assertSee('https://dash.example.test/auth/microsoft/callback');

        $this->post(route('admin.sso.microsoft'), ['enabled' => '1', 'client_id' => self::APP_ID, 'client_secret' => '********'])->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, MicrosoftSettings::clientSecret());

        $log = AuditLog::where('action', 'settings.microsoft_signin.updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->toArray()));
    }

    public function test_enabling_needs_an_application_id_and_secret_and_tenants_must_be_guids(): void
    {
        $this->actingAs($this->admin());
        $this->configureMicrosoft(['enabled' => false, 'client_id' => '', 'client_secret' => '']);

        $this->post(route('admin.sso.microsoft'), ['enabled' => '1'])->assertSessionHasErrors('enabled');
        $this->post(route('admin.sso.microsoft'), ['enabled' => '0', 'allowed_tenants' => 'contoso.onmicrosoft.com'])->assertSessionHasErrors('allowed_tenants');
        $this->post(route('admin.sso.microsoft'), ['enabled' => '0', 'client_id' => 'not-a-guid'])->assertSessionHasErrors('client_id');
    }

    // ---- unlink / account view -------------------------------------------------------------------------------

    public function test_administrator_can_unlink_a_user_and_it_is_audited(): void
    {
        $user = $this->customer('amy@acme.com');
        $this->signIn();
        $this->post('/logout');
        $this->assertNotNull($user->fresh()->microsoft_oid);

        $this->actingAs($this->admin())->post(route('admin.users.microsoft.unlink', $user))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->microsoft_oid);
        $this->assertNull($user->fresh()->microsoft_tid);
        $this->assertTrue(AuditLog::where('action', 'auth.microsoft.unlinked')->exists());

        // After unlinking, the next Microsoft sign-in re-links.
        $this->post('/logout');
        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertSame(self::OID, $user->fresh()->microsoft_oid);
    }

    public function test_only_administrators_can_unlink(): void
    {
        $user = $this->customer('amy@acme.com', null, ['microsoft_oid' => self::OID, 'microsoft_tid' => self::TID]);
        $tech = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $tech->assignRole('Technician');

        $this->actingAs($tech)->post(route('admin.users.microsoft.unlink', $user))->assertForbidden();
        $this->assertNotNull($user->fresh()->microsoft_oid);
    }

    public function test_a_client_user_can_see_their_linked_microsoft_account_but_not_the_object_id(): void
    {
        $user = $this->customer('amy@acme.com', null, ['microsoft_oid' => self::OID, 'microsoft_tid' => self::TID, 'microsoft_linked_at' => now()]);

        $json = $this->actingAs($user)->getJson(route('me.account.details'))->assertOk()
            ->assertJsonPath('microsoft.linked', true)->assertJsonPath('microsoft.tenant_id', self::TID);
        $this->assertStringNotContainsString(self::OID, $json->getContent());
        $this->assertArrayNotHasKey('microsoft_oid', $user->fresh()->toArray());
    }

    public function test_microsoft_events_are_security_events_and_the_login_audit_has_no_secrets(): void
    {
        foreach (['auth.microsoft.login.success', 'auth.microsoft.login.failed', 'auth.microsoft.linked', 'auth.microsoft.unlinked', 'auth.password.blocked_by_policy'] as $a) {
            $this->assertTrue(\App\Support\Monitoring\SecurityEvents::isSecurityAction($a), $a);
        }

        $this->customer();
        $this->signIn();
        $dump = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString('auth-code-1', $dump);
    }
}
