<?php

namespace Tests\Feature\Sso;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\SamlConnection;
use App\Models\SamlConnectionDomain;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class SsoTenantIsolationTest extends SsoTestCase
{
    private const GROUP_CUSTOMER = 'cccccccc-0000-0000-0000-000000000001';
    private const GROUP_TECH = 'dddddddd-0000-0000-0000-000000000002';
    private const GROUP_ADMIN = 'eeeeeeee-0000-0000-0000-000000000003';

    private function failedReasons(): array
    {
        return AuditLog::where('action', 'sso.login.failed')->get()->map(fn ($l) => $l->context['reason'] ?? null)->all();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    // ---- SAML is staff-only ---------------------------------------------------------------------------------

    public function test_customer_class_roles_never_come_from_saml_even_if_stored(): void
    {
        // Bypass the save-time validator on purpose: simulates a poisoned row / manual DB edit.
        $c = $this->staffConnection(['group_role_map' => [['group_id' => self::GROUP_CUSTOMER, 'role' => 'Customer']]]);

        $this->ssoLogin($c, ['email' => 'bob@contoso.com', 'groups' => [self::GROUP_CUSTOMER]])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'bob@contoso.com')->count());
        $this->assertContains('no_group_match', $this->failedReasons());
    }

    public function test_domain_cannot_be_claimed_by_two_connections_in_the_admin_form(): void
    {
        $this->staffConnection([], ['contoso.com']);
        $payload = [
            'name' => 'Rival', 'entra_tenant_id' => SamlFixtureTenant::ID,
            'idp_entity_id' => 'https://sts.windows.net/'.SamlFixtureTenant::ID.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/x/saml2', 'idp_x509_cert' => $this->idpKey['cert'],
            'domains' => 'CONTOSO.com',
        ];

        $this->actingAs($this->admin())->post(route('admin.sso.store'), $payload)->assertSessionHasErrors('domains');
    }

    public function test_database_enforces_domain_uniqueness_across_connections(): void
    {
        $this->staffConnection([], ['contoso.com']);
        $b = $this->staffConnection([], ['other.com']);

        $this->expectException(QueryException::class);
        SamlConnectionDomain::create(['saml_connection_id' => $b->id, 'domain' => 'contoso.com']);
    }

    public function test_assertion_email_outside_the_connections_domains_is_refused(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'ceo@elsewhere.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'ceo@elsewhere.com')->count());
        $this->assertContains('domain_not_allowed', $this->failedReasons());
    }

    public function test_microsoft_linked_client_user_is_never_taken_over_by_saml(): void
    {
        $user = User::factory()->create(['sso_enabled' => true, 'email' => 'amy@contoso.com', 'microsoft_oid' => 'aaaaaaaa-0000-0000-0000-0000000000aa', 'microsoft_tid' => SamlFixtureTenant::ID]);
        $user->assignRole('Technician');
        $c = $this->staffConnection(['default_role' => 'Administrator'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'amy@contoso.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($user->fresh()->saml_connection_id);
        $this->assertContains('microsoft_linked_user', $this->failedReasons());
    }

    public function test_users_with_the_password_exception_can_also_use_sso(): void
    {
        $bg = User::factory()->create(['sso_enabled' => true, 'email' => 'breakglass@contoso.com', 'password_login_allowed' => true]);
        $bg->assignRole('Administrator');
        $c = $this->staffConnection(['default_role' => 'Administrator'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'breakglass@contoso.com'])->assertRedirect();

        $this->assertAuthenticatedAs($bg->fresh());
        $this->assertTrue($bg->fresh()->password_login_allowed);
    }



    // ---- staff / client linking ---------------------------------------------------------------------------

    public function test_client_user_is_never_linked_by_staff_connection(): void
    {
        $client = Client::create(['business_name' => 'Acme']);
        $user = User::factory()->create(['sso_enabled' => true, 'email' => 'amy@contoso.com']);
        $user->assignRole('Customer');
        $user->clients()->attach($client->id);
        $c = $this->staffConnection(['default_role' => 'Administrator'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'amy@contoso.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasRole('Administrator'));
        $this->assertContains('client_user_on_staff_connection', $this->failedReasons());
    }

    public function test_staff_email_link_requires_domain_in_connection(): void
    {
        $existing = User::factory()->create(['sso_enabled' => true, 'email' => 'sam@elsewhere.com']);
        $existing->assignRole('Technician');
        $c = $this->staffConnection(['default_role' => 'Technician'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'sam@elsewhere.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($existing->fresh()->saml_connection_id);
    }

    public function test_linked_user_is_matched_by_object_id_even_if_email_changes(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician'], ['contoso.com']);
        $this->ssoLogin($c, ['email' => 'old@contoso.com', 'objectId' => 'aaaaaaaa-0000-0000-0000-0000000000bb'])->assertRedirect();
        auth()->logout();
        $id = User::where('email', 'old@contoso.com')->value('id');

        $this->ssoLogin($c, ['email' => 'renamed@contoso.com', 'objectId' => 'aaaaaaaa-0000-0000-0000-0000000000bb'])->assertRedirect();

        $this->assertAuthenticatedAs(User::find($id));
        $this->assertSame(1, User::count());
    }

    public function test_attacker_cannot_take_over_a_linked_account_by_asserting_its_email_with_a_different_object_id(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician'], ['contoso.com']);
        $this->ssoLogin($c, ['email' => 'victim@contoso.com', 'objectId' => 'aaaaaaaa-0000-0000-0000-0000000000cc'])->assertRedirect();
        auth()->logout();

        $this->ssoLogin($c, ['email' => 'victim@contoso.com', 'objectId' => 'aaaaaaaa-0000-0000-0000-0000000000dd'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertContains('already_linked', $this->failedReasons());
    }

    public function test_inactive_users_are_blocked(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician'], ['contoso.com']);
        $this->ssoLogin($c, ['email' => 'ina@contoso.com'])->assertRedirect();
        auth()->logout();
        User::where('email', 'ina@contoso.com')->update(['is_active' => false]);

        $this->ssoLogin($c, ['email' => 'ina@contoso.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertContains('inactive', $this->failedReasons());
    }

    public function test_jit_disabled_blocks_unknown_users_but_links_existing_ones(): void
    {
        $c = $this->staffConnection(['default_role' => 'Technician', 'jit_provisioning' => false], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'new@contoso.com'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'new@contoso.com')->count());

        $existing = User::factory()->create(['sso_enabled' => true, 'email' => 'old@contoso.com']);
        $existing->assignRole('Technician');
        $this->ssoLogin($c, ['email' => 'old@contoso.com'])->assertRedirect();
        $this->assertAuthenticatedAs($existing->fresh());
    }

    // ---- role sync --------------------------------------------------------------------------------------

    public function test_roles_are_resynced_on_every_login_and_removed_with_the_group(): void
    {
        $c = $this->staffConnection(['group_role_map' => [
            ['group_id' => self::GROUP_ADMIN, 'role' => 'Administrator'],
            ['group_id' => self::GROUP_TECH, 'role' => 'Technician'],
        ]], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'rae@contoso.com', 'groups' => [self::GROUP_ADMIN, self::GROUP_TECH]])->assertRedirect();
        $user = User::where('email', 'rae@contoso.com')->first();
        $this->assertEqualsCanonicalizing(['Administrator', 'Technician'], $user->getRoleNames()->all());
        auth()->logout();

        $this->ssoLogin($c, ['email' => 'rae@contoso.com', 'groups' => [self::GROUP_TECH]])->assertRedirect();
        $this->assertSame(['Technician'], $user->fresh()->getRoleNames()->all());
    }

    public function test_losing_every_mapped_group_denies_login_revokes_roles_and_sessions(): void
    {
        $c = $this->staffConnection(['group_role_map' => [['group_id' => self::GROUP_TECH, 'role' => 'Technician']]], ['contoso.com']);
        $this->ssoLogin($c, ['email' => 'lou@contoso.com', 'groups' => [self::GROUP_TECH]])->assertRedirect();
        $user = User::where('email', 'lou@contoso.com')->first();
        auth()->logout();

        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

        $this->ssoLogin($c, ['email' => 'lou@contoso.com', 'groups' => []])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame([], $user->fresh()->getRoleNames()->all());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertContains('no_group_match', $this->failedReasons());
    }

    public function test_group_ids_match_case_insensitively(): void
    {
        $c = $this->staffConnection(['group_role_map' => [['group_id' => self::GROUP_TECH, 'role' => 'Technician']]], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'cas@contoso.com', 'groups' => [strtoupper(self::GROUP_TECH)]])->assertRedirect();
        $this->assertAuthenticated();
    }
}

/** Tenant GUID used by the admin-form payloads (same value the fixture connections use). */
final class SamlFixtureTenant
{
    public const ID = \Tests\Support\SamlFixture::TENANT;
}
