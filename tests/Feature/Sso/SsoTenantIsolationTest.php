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

    // ---- client connection can only produce customers bound to its own client ---------------------------

    public function test_client_connection_jit_creates_customer_bound_only_to_its_client(): void
    {
        $client = Client::create(['business_name' => 'Acme']);
        $other = Client::create(['business_name' => 'Other']);
        $c = $this->clientConnection(['client_id' => $client->id, 'group_role_map' => [['group_id' => self::GROUP_CUSTOMER, 'role' => 'Customer']]]);

        $this->ssoLogin($c, ['email' => 'bob@acme.com', 'groups' => [self::GROUP_CUSTOMER]])->assertRedirect();

        $user = User::where('email', 'bob@acme.com')->firstOrFail();
        $this->assertSame(['Customer'], $user->getRoleNames()->all());
        $this->assertSame([$client->id], $user->clients()->pluck('clients.id')->all());
        $this->assertFalse($user->clients->contains($other));
    }

    public function test_stored_staff_mapping_on_client_connection_is_ignored_at_login(): void
    {
        // Bypass the save-time validator on purpose: simulates a poisoned row / manual DB edit.
        $c = $this->clientConnection(['group_role_map' => [
            ['group_id' => self::GROUP_ADMIN, 'role' => 'Administrator'],
            ['group_id' => self::GROUP_CUSTOMER, 'role' => 'Customer'],
        ]]);

        $this->ssoLogin($c, ['email' => 'mallory@acme.com', 'groups' => [self::GROUP_ADMIN, self::GROUP_CUSTOMER]])->assertRedirect();

        $user = User::where('email', 'mallory@acme.com')->firstOrFail();
        $this->assertSame(['Customer'], $user->getRoleNames()->all());
        $this->assertFalse($user->hasRole('Administrator'));
    }

    public function test_client_connection_with_only_a_staff_mapping_denies_login(): void
    {
        $c = $this->clientConnection(['group_role_map' => [['group_id' => self::GROUP_ADMIN, 'role' => 'Administrator']], 'default_role' => 'Technician']);

        $this->ssoLogin($c, ['email' => 'mallory@acme.com', 'groups' => [self::GROUP_ADMIN]])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'mallory@acme.com')->count());
        $this->assertContains('no_group_match', $this->failedReasons());
    }

    public function test_save_time_validation_rejects_staff_roles_on_client_connections(): void
    {
        $client = Client::create(['business_name' => 'Acme']);
        $payload = [
            'name' => 'Acme', 'type' => 'client', 'client_id' => $client->id, 'entra_tenant_id' => SamlFixtureTenant::ID,
            'idp_entity_id' => 'https://sts.windows.net/'.SamlFixtureTenant::ID.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/x/saml2', 'idp_x509_cert' => $this->idpKey['cert'],
            'domains' => 'acme.com', 'group_role_map' => self::GROUP_ADMIN.' = Administrator',
        ];

        $this->actingAs($this->admin())->post(route('admin.sso.store'), $payload)->assertSessionHasErrors('group_role_map');
        $this->assertSame(0, SamlConnection::count());

        $this->post(route('admin.sso.store'), array_merge($payload, ['group_role_map' => '', 'default_role' => 'Technician']))->assertSessionHasErrors('default_role');
        $this->post(route('admin.sso.store'), array_merge($payload, ['group_role_map' => self::GROUP_CUSTOMER.' = Customer']))->assertSessionHasNoErrors();
        $this->assertSame(1, SamlConnection::count());
    }

    public function test_customer_role_that_gained_privileged_permissions_is_not_customer_safe(): void
    {
        Role::findByName('Customer')->givePermissionTo('users.manage');
        $c = $this->clientConnection(['group_role_map' => [['group_id' => self::GROUP_CUSTOMER, 'role' => 'Customer']]]);

        $this->ssoLogin($c, ['email' => 'bob@acme.com', 'groups' => [self::GROUP_CUSTOMER]])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- email-domain discovery -------------------------------------------------------------------------

    public function test_client_connection_cannot_claim_staff_domain_or_its_subdomains(): void
    {
        $this->staffConnection([], ['contoso.com']);
        $client = Client::create(['business_name' => 'Acme']);
        $base = [
            'name' => 'Acme', 'type' => 'client', 'client_id' => $client->id, 'entra_tenant_id' => SamlFixtureTenant::ID,
            'idp_entity_id' => 'https://sts.windows.net/'.SamlFixtureTenant::ID.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/x/saml2', 'idp_x509_cert' => $this->idpKey['cert'],
        ];
        $this->actingAs($this->admin());

        $this->post(route('admin.sso.store'), $base + ['domains' => 'contoso.com'])->assertSessionHasErrors('domains');
        $this->post(route('admin.sso.store'), $base + ['domains' => 'it.contoso.com'])->assertSessionHasErrors('domains');
        $this->assertSame(1, SamlConnection::count());
    }

    public function test_domain_cannot_be_claimed_by_two_connections(): void
    {
        $this->clientConnection([], ['acme.com']);
        $client = Client::create(['business_name' => 'Rival']);
        $payload = [
            'name' => 'Rival', 'type' => 'client', 'client_id' => $client->id, 'entra_tenant_id' => SamlFixtureTenant::ID,
            'idp_entity_id' => 'https://sts.windows.net/'.SamlFixtureTenant::ID.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/x/saml2', 'idp_x509_cert' => $this->idpKey['cert'],
            'domains' => 'ACME.com',
        ];

        $this->actingAs($this->admin())->post(route('admin.sso.store'), $payload)->assertSessionHasErrors('domains');
    }

    public function test_database_enforces_domain_uniqueness_across_connections(): void
    {
        $a = $this->clientConnection([], ['acme.com']);
        $b = $this->clientConnection([], ['other.com']);

        $this->expectException(QueryException::class);
        SamlConnectionDomain::create(['saml_connection_id' => $b->id, 'domain' => 'acme.com']);
        $this->assertNotNull($a);
    }

    public function test_discovery_routes_to_the_connection_that_owns_the_domain_staff_first(): void
    {
        $staff = $this->staffConnection([], ['contoso.com']);
        $client = $this->clientConnection([], ['acme.com']);
        // Poisoned data: a client row sharing the staff domain can only exist by bypassing validation + unique index.
        DB::table('saml_connection_domains')->where('saml_connection_id', $client->id)->update(['domain' => 'contoso.com.']);

        $this->post(route('sso.discover'), ['email' => 'x@contoso.com'])->assertRedirect(route('saml.login', $staff->uuid));
        $this->post(route('sso.discover'), ['email' => 'x@unknown.org'])->assertRedirect(route('login'))->assertSessionHasErrors('sso_email');
    }

    public function test_discovery_ignores_disabled_connections(): void
    {
        $this->staffConnection(['enabled' => false], ['contoso.com']);

        $this->post(route('sso.discover'), ['email' => 'x@contoso.com'])->assertRedirect(route('login'))->assertSessionHasErrors('sso_email');
    }

    public function test_assertion_email_outside_the_connections_domains_is_refused(): void
    {
        $this->staffConnection([], ['contoso.com']);
        $c = $this->clientConnection(['default_role' => 'Customer'], ['acme.com']);

        // Client tenant admin asserts an address at the staff domain.
        $this->ssoLogin($c, ['email' => 'ceo@contoso.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'ceo@contoso.com')->count());
        $this->assertContains('domain_not_allowed', $this->failedReasons());
    }

    // ---- cross-client / staff linking -------------------------------------------------------------------

    public function test_local_user_of_client_a_is_never_linked_via_client_b(): void
    {
        $a = Client::create(['business_name' => 'A']);
        $b = Client::create(['business_name' => 'B']);
        $victim = User::factory()->create(['email' => 'pat@shared.com']);
        $victim->assignRole('Customer');
        $victim->clients()->attach($a->id);
        $connB = $this->clientConnection(['client_id' => $b->id, 'default_role' => 'Customer'], ['shared.com']);

        $this->ssoLogin($connB, ['email' => 'pat@shared.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $victim->refresh();
        $this->assertNull($victim->saml_connection_id);
        $this->assertNull($victim->saml_object_id);
        $this->assertSame([$a->id], $victim->clients()->pluck('clients.id')->all());
        $this->assertContains('wrong_client', $this->failedReasons());
    }

    public function test_user_linked_to_client_b_connection_cannot_be_reached_from_client_a_connection(): void
    {
        $a = Client::create(['business_name' => 'A']);
        $b = Client::create(['business_name' => 'B']);
        $connA = $this->clientConnection(['client_id' => $a->id, 'default_role' => 'Customer'], ['a.com']);
        $connB = $this->clientConnection(['client_id' => $b->id, 'default_role' => 'Customer'], ['b.com']);
        $this->ssoLogin($connB, ['email' => 'pat@b.com', 'objectId' => 'bbbbbbbb-0000-0000-0000-000000000009'])->assertRedirect();
        auth()->logout();

        // Tenant A asserts the same object id AND the same email address.
        $this->ssoLogin($connA, ['email' => 'pat@b.com', 'objectId' => 'bbbbbbbb-0000-0000-0000-000000000009'])->assertRedirect(route('login'));

        $this->assertGuest();
        $pat = User::where('email', 'pat@b.com')->first();
        $this->assertSame($connB->id, $pat->saml_connection_id);
        $this->assertSame([$b->id], $pat->clients()->pluck('clients.id')->all());
    }

    public function test_staff_user_is_never_linked_by_a_client_connection(): void
    {
        $tech = User::factory()->create(['email' => 'tina@acme.com']);
        $tech->assignRole('Technician');
        $c = $this->clientConnection(['default_role' => 'Customer'], ['acme.com']);

        $this->ssoLogin($c, ['email' => 'tina@acme.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($tech->fresh()->saml_connection_id);
        $this->assertTrue($tech->fresh()->hasRole('Technician'));
        $this->assertContains('staff_on_client_connection', $this->failedReasons());
    }

    public function test_unassigned_local_customer_is_not_adopted_by_a_client_tenant_on_email_match(): void
    {
        $orphan = User::factory()->create(['email' => 'zed@acme.com']);
        $orphan->assignRole('Customer');
        $c = $this->clientConnection(['default_role' => 'Customer'], ['acme.com']);

        $this->ssoLogin($c, ['email' => 'zed@acme.com'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_existing_local_user_of_this_client_links_on_first_login_and_roles_sync(): void
    {
        $client = Client::create(['business_name' => 'Acme']);
        $user = User::factory()->create(['email' => 'amy@acme.com']);
        $user->assignRole('Customer');
        $user->clients()->attach($client->id);
        $c = $this->clientConnection(['client_id' => $client->id, 'default_role' => 'Customer'], ['acme.com']);

        $this->ssoLogin($c, ['email' => 'AMY@acme.com', 'objectId' => 'aaaaaaaa-0000-0000-0000-0000000000aa'])->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame($c->id, $user->fresh()->saml_connection_id);
        $this->assertSame(1, User::count());
    }

    public function test_client_user_is_never_linked_by_staff_connection(): void
    {
        $client = Client::create(['business_name' => 'Acme']);
        $user = User::factory()->create(['email' => 'amy@contoso.com']);
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
        $existing = User::factory()->create(['email' => 'sam@elsewhere.com']);
        $existing->assignRole('Technician');
        $c = $this->staffConnection(['default_role' => 'Technician'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'sam@elsewhere.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($existing->fresh()->saml_connection_id);
    }

    public function test_break_glass_accounts_are_never_linked_or_logged_in_via_sso(): void
    {
        $bg = User::factory()->create(['email' => 'breakglass@contoso.com', 'is_break_glass' => true]);
        $bg->assignRole('Administrator');
        $c = $this->staffConnection(['default_role' => 'Administrator'], ['contoso.com']);

        $this->ssoLogin($c, ['email' => 'breakglass@contoso.com'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($bg->fresh()->saml_connection_id);
        $this->assertContains('break_glass', $this->failedReasons());
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

        $existing = User::factory()->create(['email' => 'old@contoso.com']);
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
