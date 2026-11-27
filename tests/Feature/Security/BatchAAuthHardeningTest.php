<?php

namespace Tests\Feature\Security;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Services\Halo\HaloPsaClient;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Regression tests for security review Batch A (auth and access control). */
class BatchAAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['domaindash.mfa.enforce' => true, 'domaindash.mfa.required_roles' => ['Administrator', 'Technician']]);

        foreach (['Administrator', 'Technician', 'Customer'] as $role) {
            Role::findOrCreate($role);
        }
        foreach (['domains.view', 'domains.manage', 'domains.transfer', 'domains.renew', 'dns.manage', 'users.manage'] as $p) {
            Permission::findOrCreate($p);
        }
    }

    private function user(string $role, bool $mfa = true, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ($mfa ? [
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ] : []));
        $u->assignRole($role);

        return $u;
    }

    // ---------------------------------------------------------------- A1

    public function test_technician_is_in_the_default_mfa_required_roles(): void
    {
        $this->assertStringContainsString('Technician', (string) file_get_contents(base_path('.env.example')));
        $this->assertTrue($this->user('Technician', false)->requiresMfa());
    }

    public function test_staff_without_mfa_only_sees_the_setup_page_and_nothing_else(): void
    {
        $tech = $this->user('Technician', false);

        $this->actingAs($tech)->get('/dashboard')->assertOk()
            ->assertSee('Set up multi-factor authentication')
            ->assertDontSee('Domains, hosting, SSL certificates');

        $this->actingAs($tech)->get('/admin/domains')->assertRedirect(route('dashboard'));
        $this->actingAs($tech)->get('/tickets')->assertRedirect(route('dashboard'));
        $this->actingAs($tech)->get('/user/profile')->assertRedirect(route('dashboard'));
        $this->actingAs($tech)->getJson('/admin/domains')->assertForbidden();
        $this->actingAs($tech)->postJson('/livewire/update', [])->assertForbidden();

        // The enrolment flow itself stays reachable.
        $this->actingAs($tech)->postJson(route('me.mfa.start'))->assertOk()->assertJsonPath('ready', true);
    }

    public function test_enforced_preference_blocks_until_mfa_is_confirmed(): void
    {
        $customer = $this->user('Customer', false, ['mfa_preference' => 'enforced']);

        $this->actingAs($customer)->get('/tickets')->assertRedirect(route('dashboard'));
        $this->actingAs($customer)->get('/dashboard')->assertSee('Set up multi-factor authentication');

        $enrolled = $this->user('Customer', true, ['mfa_preference' => 'enforced']);
        $this->actingAs($enrolled)->get('/dashboard')->assertOk()->assertDontSee('Set up multi-factor authentication');
    }

    public function test_enforced_preference_is_only_a_prompt_when_enforcement_is_lifted(): void
    {
        config(['domaindash.mfa.enforce' => false]);
        $customer = $this->user('Customer', false, ['mfa_preference' => 'enforced']);

        $this->actingAs($customer)->get('/dashboard')->assertOk()->assertDontSee('Set up multi-factor authentication');
    }

    public function test_dismissable_prompt_users_are_not_blocked(): void
    {
        $customer = $this->user('Customer', false, ['mfa_preference' => 'enabled']);

        $this->actingAs($customer)->get('/tickets')->assertOk();
    }

    // ---------------------------------------------------------------- A2 / A3 / A4

    public function test_deactivated_user_is_logged_out_on_jetstream_and_fortify_routes(): void
    {
        $user = $this->user('Customer', true, ['is_active' => false]);

        $this->actingAs($user)->get('/user/profile')->assertRedirect(route('login'));
    }

    public function test_fortify_two_factor_management_endpoints_are_blocked(): void
    {
        $user = $this->user('Customer');
        $secret = $user->two_factor_secret;

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication', ['force' => 1])->assertForbidden();
        $this->deleteJson('/user/two-factor-authentication')->assertForbidden();
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertForbidden();
        $this->postJson('/user/two-factor-recovery-codes')->assertForbidden();
        $this->getJson('/user/two-factor-secret-key')->assertForbidden();

        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_profile_information_endpoint_and_team_and_account_deletion_routes_are_gone(): void
    {
        $user = $this->user('Customer');

        $this->actingAs($user)->putJson('/user/profile-information', ['name' => 'X', 'email' => 'hijack@example.com'])
            ->assertNotFound();
        $this->assertNotSame('hijack@example.com', $user->fresh()->email);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('teams.show'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('teams.create'));
        $this->assertFalse(\Laravel\Jetstream\Jetstream::hasTeamFeatures());
        $this->assertFalse(\Laravel\Jetstream\Jetstream::hasAccountDeletionFeatures());
        $this->actingAs($user)->get('/teams/create')->assertNotFound();
    }

    // ---------------------------------------------------------------- A5

    public function test_customer_holding_staff_permissions_still_cannot_reach_staff_domain_routes(): void
    {
        $client = Client::create(['business_name' => 'C']);
        $domain = Domain::create(['name' => 'c.example.com', 'client_id' => $client->id]);
        $other = Client::create(['business_name' => 'Other']);

        $customer = $this->user('Customer');
        $customer->clients()->attach($client->id);
        $customer->givePermissionTo(['domains.manage', 'domains.transfer', 'domains.renew']);

        $this->mock(SynergyWholesaleClient::class, fn ($m) => $m->shouldNotReceive('transferDomain', 'renewDomain'));

        $this->actingAs($customer)->post(route('admin.domains.assignClient', $domain), ['client_id' => $other->id])->assertForbidden();
        $this->actingAs($customer)->post(route('admin.domains.transfer'), ['domainName' => 'x.com'])->assertForbidden();
        $this->actingAs($customer)->post(route('admin.domains.renew', $domain), ['years' => 1])->assertForbidden();
        $this->actingAs($customer)->post(route('admin.domains.bulkSync'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.domains.availability'), ['domain' => 'x.com'])->assertForbidden();
        $this->assertSame($client->id, $domain->fresh()->client_id);
    }

    public function test_technician_with_permission_can_assign_a_client(): void
    {
        $client = Client::create(['business_name' => 'C']);
        $domain = Domain::create(['name' => 'c.example.com']);
        $tech = $this->user('Technician');
        $tech->givePermissionTo('domains.manage');

        $this->actingAs($tech)->post(route('admin.domains.assignClient', $domain), ['client_id' => $client->id])->assertRedirect();
        $this->assertSame($client->id, $domain->fresh()->client_id);
    }

    public function test_staff_only_permissions_cannot_be_granted_to_the_customer_role(): void
    {
        $admin = $this->user('Administrator');
        $role = Role::findByName('Customer');

        $this->actingAs($admin)->put(route('admin.permissions.roles.update', $role), ['permissions' => ['domains.view', 'users.manage']])
            ->assertSessionHasErrors('permissions');
        $this->assertFalse($role->fresh()->hasPermissionTo('users.manage'));

        $this->actingAs($admin)->post(route('admin.permissions.update'), ['permissions' => ['Customer' => ['domains.manage' => 1], 'Technician' => ['domains.manage' => 1]]])
            ->assertSessionHasErrors('permissions');
        $this->assertFalse($role->fresh()->hasPermissionTo('domains.manage'));

        $this->actingAs($admin)->put(route('admin.permissions.roles.update', $role), ['permissions' => ['domains.view', 'dns.manage']])
            ->assertSessionHasNoErrors();
        $this->assertTrue($role->fresh()->hasPermissionTo('dns.manage'));

        // Staff roles may still hold them.
        $this->actingAs($admin)->put(route('admin.permissions.roles.update', Role::findByName('Technician')), ['permissions' => ['domains.manage']])
            ->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------- A6

    private function haloTicketFixture(int $ticketTypeId): array
    {
        Setting::put('halo', [
            'base_url' => 'https://halo.example.com',
            'ticket_type_mappings' => [[
                'service_category' => 'Domain', 'ticket_type' => 'Support/Issue', 'halo_ticket_type_id' => 5,
            ]],
        ]);
        $client = Client::create(['business_name' => 'C', 'halopsa_reference' => 77]);
        $user = $this->user('Customer');
        $user->clients()->attach($client->id);

        $halo = \Mockery::mock(HaloPsaClient::class);
        $halo->shouldReceive('getTicket')->with(900)->andReturn(['id' => 900, 'client_id' => 77, 'tickettype_id' => $ticketTypeId, 'summary' => 'S']);
        $halo->shouldReceive('getTicketActions')->andReturn([]);
        $this->app->instance(HaloPsaClient::class, $halo);

        return [$user, $client, $halo];
    }

    public function test_ticket_show_reply_close_enforce_the_configured_ticket_types(): void
    {
        [$user, $client, $halo] = $this->haloTicketFixture(99);
        $halo->shouldNotReceive('createTicketAction');

        $this->actingAs($user)->get(route('tickets.show', ['ticketId' => 900, 'client_id' => $client->id]))->assertDontSee('S</h');
        $this->actingAs($user)->get(route('tickets.show', ['ticketId' => 900, 'client_id' => $client->id]))
            ->assertSessionHasErrors('halo');
        $this->actingAs($user)->post(route('tickets.reply', 900), ['client_id' => $client->id, 'message' => 'hi'])
            ->assertSessionHasErrors('halo');
        $this->actingAs($user)->post(route('tickets.close', 900), ['client_id' => $client->id, 'reason' => 'done'])
            ->assertSessionHasErrors('halo');
    }

    public function test_ticket_of_a_configured_type_can_still_be_shown(): void
    {
        [$user, $client] = $this->haloTicketFixture(5);

        $this->actingAs($user)->get(route('tickets.show', ['ticketId' => 900, 'client_id' => $client->id]))
            ->assertOk()->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------- A7

    private function dnsFixture(): array
    {
        $client = Client::create(['business_name' => 'A']);
        $domain = Domain::create(['name' => 'a.example.com', 'client_id' => $client->id]);
        $staff = $this->user('Technician');
        $staff->givePermissionTo('dns.manage');

        return [$domain, $staff];
    }

    public function test_dns_update_and_delete_require_the_record_to_belong_to_the_domain_zone(): void
    {
        [$domain, $staff] = $this->dnsFixture();
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('listDNSZone')->with('a.example.com')->andReturn([['id' => 'mine', 'hostName' => 'www', 'type' => 'A', 'content' => '1.1.1.1']]);
            $m->shouldReceive('updateDNSRecord')->once()->with('a.example.com', 'mine', 'www', 'A', '2.2.2.2', 300, 0);
            $m->shouldReceive('deleteDNSRecord')->once()->with('a.example.com', 'mine');
        });
        $rec = ['host' => 'www', 'type' => 'A', 'content' => '2.2.2.2', 'ttl' => 300];

        $this->actingAs($staff)->put(route('dns.update', [$domain, 'foreign']), $rec)->assertNotFound();
        $this->actingAs($staff)->delete(route('dns.destroy', [$domain, 'foreign']))->assertNotFound();
        $this->actingAs($staff)->put(route('dns.update', [$domain, 'mine']), $rec)->assertRedirect();
        $this->actingAs($staff)->delete(route('dns.destroy', [$domain, 'mine']))->assertRedirect();
    }

    public function test_api_dns_update_and_delete_require_the_record_to_belong_to_the_domain_zone(): void
    {
        [$domain] = $this->dnsFixture();
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('listDNSZone')->andReturn([['id' => 'mine']]);
            $m->shouldReceive('updateDNSRecord')->once();
            $m->shouldReceive('deleteDNSRecord')->once();
        });
        $gen = ApiKey::generate();
        ApiKey::create(['name' => 't', 'key_prefix' => $gen['prefix'], 'key_hash' => $gen['hash'], 'rate_limit_per_hour' => 1000, 'scopes' => ['dns.write'], 'active' => true]);
        $h = ['x-api-key' => $gen['plain']];
        $rec = ['host' => 'www', 'type' => 'A', 'content' => '2.2.2.2', 'ttl' => 300];

        $this->putJson("/api/v1/domains/{$domain->id}/dns/foreign", $rec, $h)->assertNotFound();
        $this->deleteJson("/api/v1/domains/{$domain->id}/dns/foreign", [], $h)->assertNotFound();
        $this->putJson("/api/v1/domains/{$domain->id}/dns/mine", $rec, $h)->assertOk();
        $this->deleteJson("/api/v1/domains/{$domain->id}/dns/mine", [], $h)->assertNoContent();
    }

    public function test_dns_changes_fail_closed_when_the_zone_cannot_be_read(): void
    {
        [$domain, $staff] = $this->dnsFixture();
        $this->mock(SynergyWholesaleClient::class, function ($m) {
            $m->shouldReceive('listDNSZone')->andThrow(new \RuntimeException('down'));
            $m->shouldNotReceive('deleteDNSRecord');
        });

        $this->actingAs($staff)->delete(route('dns.destroy', [$domain, 'mine']))->assertNotFound();
    }

    // ---------------------------------------------------------------- A8

    public function test_password_reset_revokes_all_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->user('Customer');
        foreach (['s1', 's2'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }

        app(ResetUserPassword::class)->reset($user, ['password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x']);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_password_update_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->user('Customer', true, ['password' => bcrypt('Old-Passw0rd!xyz')]);
        foreach (['other1', 'other2'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }

        $this->actingAs($user);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        $current = session()->getId();
        DB::table('sessions')->insert(['id' => $current, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        app(\App\Actions\Fortify\UpdateUserPassword::class)->update($user, [
            'current_password' => 'Old-Passw0rd!xyz', 'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ]);

        $this->assertSame([$current], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
    }

    public function test_forgot_password_does_not_reveal_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);

        $unknown->assertSessionHasNoErrors();
        $this->assertSame(session('status'), trans('passwords.sent'));
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        Notification::assertSentToTimes($user, \Illuminate\Auth\Notifications\ResetPassword::class, 1);
        Notification::assertNothingSentTo(new User(['email' => 'nobody@example.com']));
    }

    public function test_confirm_password_is_throttled(): void
    {
        $user = $this->user('Customer');
        $this->actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/user/confirm-password', ['password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/user/confirm-password', ['password' => 'wrong'])->assertStatus(429);
    }

    // ---------------------------------------------------------------- A9

    private function impersonation(): array
    {
        $admin = $this->user('Administrator');
        $target = $this->user('Customer');

        return [$admin, $target, ['impersonate_as' => $target->id, 'impersonator_id' => $admin->id, 'impersonation_started_at' => now()->getTimestamp()]];
    }

    public function test_valid_impersonation_acts_as_the_target(): void
    {
        [$admin, $target, $session] = $this->impersonation();

        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertOk()
            ->assertSee('currently impersonating '.$target->email);
    }

    public function test_impersonation_expires_after_the_maximum_duration(): void
    {
        [$admin, $target, $session] = $this->impersonation();
        $session['impersonation_started_at'] = now()->subMinutes(61)->getTimestamp();

        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertOk()
            ->assertDontSee('currently impersonating');

        $this->assertNull(session('impersonate_as'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'impersonation.end']);
    }

    public function test_impersonation_ends_if_the_impersonator_loses_the_administrator_role(): void
    {
        [$admin, $target, $session] = $this->impersonation();
        $admin->removeRole('Administrator');

        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertDontSee('currently impersonating');

        $this->assertNull(session('impersonate_as'));
    }

    public function test_impersonation_ends_if_the_target_became_an_administrator_or_inactive(): void
    {
        [$admin, $target, $session] = $this->impersonation();
        $target->assignRole('Administrator');
        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertDontSee('currently impersonating');

        [$admin, $target, $session] = $this->impersonation();
        $target->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->withSession($session)->get('/dashboard');
        $this->assertNull(session('impersonate_as'));
    }

    public function test_impersonation_session_from_a_different_user_is_rejected(): void
    {
        [$admin, $target, $session] = $this->impersonation();
        $other = $this->user('Administrator');
        $session['impersonator_id'] = $other->id;

        $this->actingAs($admin)->withSession($session)->get('/dashboard')->assertDontSee('currently impersonating');
        $this->assertNull(session('impersonate_as'));
    }

    // ---------------------------------------------------------------- A10

    private function apiKey(array $scopes): array
    {
        $gen = ApiKey::generate();
        ApiKey::create(['name' => 't', 'key_prefix' => $gen['prefix'], 'key_hash' => $gen['hash'], 'rate_limit_per_hour' => 1000, 'scopes' => $scopes, 'active' => true]);

        return ['x-api-key' => $gen['plain']];
    }

    private function userPayload(array $extra = []): array
    {
        return $extra + ['name' => 'N', 'email' => 'n@example.com', 'password' => 'Str0ngPassw0rd!x', 'password_confirmation' => 'Str0ngPassw0rd!x'];
    }

    public function test_users_write_key_cannot_assign_staff_roles_or_change_emails(): void
    {
        $h = $this->apiKey(['users.write']);

        $this->postJson('/api/v1/users', $this->userPayload(['role' => 'Technician']), $h)->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'n@example.com']);

        $victim = $this->user('Customer', true, ['email' => 'victim@example.com']);
        $this->putJson("/api/v1/users/{$victim->id}", ['email' => 'attacker@example.com'], $h)->assertForbidden();
        $this->putJson("/api/v1/users/{$victim->id}", ['role' => 'Technician'], $h)->assertStatus(422);
        $this->assertSame('victim@example.com', $victim->fresh()->email);

        // Non-sensitive edits and Customer creation still work.
        $this->putJson("/api/v1/users/{$victim->id}", ['name' => 'Renamed', 'email' => 'victim@example.com'], $h)->assertOk();
        $this->postJson('/api/v1/users', $this->userPayload(['role' => 'Customer']), $h)->assertCreated();
    }

    public function test_users_admin_key_can_assign_technician_and_change_emails(): void
    {
        $h = $this->apiKey(['users.write', 'users.admin']);

        $this->postJson('/api/v1/users', $this->userPayload(['role' => 'Technician']), $h)->assertCreated();
        $victim = $this->user('Customer');
        $this->putJson("/api/v1/users/{$victim->id}", ['email' => 'changed@example.com'], $h)->assertOk();
        $this->assertSame('changed@example.com', $victim->fresh()->email);
    }

    public function test_new_api_keys_expire_after_365_days_by_default_and_it_is_configurable(): void
    {
        $admin = $this->user('Administrator');
        $payload = ['name' => 'k1', 'rate_limit_per_hour' => 100, 'scopes' => ['domains.read']];

        $this->actingAs($admin)->post(route('admin.apikeys.store'), $payload)->assertSessionHasNoErrors();
        $key = ApiKey::where('name', 'k1')->firstOrFail();
        $this->assertEqualsWithDelta(365, now()->diffInDays($key->expires_at, false), 1);

        $this->actingAs($admin)->post(route('admin.apikeys.store'), ['name' => 'k2', 'expires_in_days' => 30] + $payload);
        $this->assertEqualsWithDelta(30, now()->diffInDays(ApiKey::where('name', 'k2')->firstOrFail()->expires_at, false), 1);

        config(['domaindash.api_keys.default_expiry_days' => 0]);
        $this->actingAs($admin)->post(route('admin.apikeys.store'), ['name' => 'k3'] + $payload);
        $this->assertNull(ApiKey::where('name', 'k3')->firstOrFail()->expires_at);
    }
}
