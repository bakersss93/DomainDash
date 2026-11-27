<?php

namespace Tests\Feature\Microsoft;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\InternetServiceDiagnostic;
use App\Models\User;
use App\Services\Microsoft\MicrosoftSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MicrosoftRound3Test extends MicrosoftTestCase
{
    private function dash()
    {
        $this->nextRequest();

        return $this->get('/dashboard');
    }

    // ---- tenant requirement -------------------------------------------------

    public function test_requirement_defaults_on_and_mfa_trust_defaults_off(): void
    {
        \App\Models\Setting::query()->delete();
        $this->assertTrue(MicrosoftSettings::requireClientTenant());
        $this->assertFalse(MicrosoftSettings::trustMfa());
    }

    public function test_first_link_is_refused_when_the_client_has_no_tenant_even_if_the_global_list_has_it(): void
    {
        $this->configureMicrosoft(['allowed_tenants' => [self::TID]]);
        $user = $this->customer('amy@acme.com', Client::create(['business_name' => 'NoTenant']));

        $this->signIn()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertContains('tenant_not_configured', $this->failedReasons());
        $this->assertNull($user->fresh()->microsoft_oid);
    }

    public function test_every_login_is_refused_when_a_client_tenant_is_removed_later(): void
    {
        $client = Client::create(['business_name' => 'Acme', 'microsoft_tenant_id' => self::TID]);
        $this->customer('amy@acme.com', $client);
        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        auth()->logout();

        $client->update(['microsoft_tenant_id' => null]);
        $this->signIn()->assertRedirect(route('login'));
        $this->assertContains('tenant_not_configured', $this->failedReasons());
    }

    public function test_one_client_without_tenant_blocks_a_multi_client_user(): void
    {
        $u = $this->customer('amy@acme.com');
        $u->clients()->attach(Client::create(['business_name' => 'Second'])->id);

        $this->signIn()->assertRedirect(route('login'));
        $this->assertContains('tenant_not_configured', $this->failedReasons());
    }

    public function test_with_requirement_off_a_missing_tenant_is_tolerated_and_auto_filled(): void
    {
        $this->configureMicrosoft(['require_client_tenant' => false]);
        $client = Client::create(['business_name' => 'Loose']);
        $this->customer('amy@acme.com', $client);

        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertSame(self::TID, $client->fresh()->microsoft_tenant_id);
    }

    public function test_settings_page_warns_when_the_requirement_is_off_and_saves_it(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');
        $this->configureMicrosoft(['require_client_tenant' => false]);

        $this->actingAs($admin)->get(route('admin.sso.index'))->assertOk()
            ->assertSee('Require a tenant ID for each client before its users can sign in with Microsoft')
            ->assertSee('anyone who controls ANY Microsoft tenant');

        $this->actingAs($admin)->post(route('admin.sso.microsoft'), [
            'enabled' => '1', 'client_id' => self::APP_ID, 'require_client_tenant' => '1', 'trust_mfa_clients' => '0',
        ])->assertRedirect();
        $this->assertTrue(MicrosoftSettings::requireClientTenant());
        $this->actingAs($admin)->get(route('admin.sso.index'))->assertDontSee('anyone who controls ANY Microsoft tenant');
    }

    // ---- MFA trust needs the amr claim -------------------------------------------

    private function mfaUser(): User
    {
        return $this->customer('amy@acme.com', null, ['mfa_preference' => 'enforced']);
    }

    public function test_trust_on_and_amr_mfa_satisfies_mfa(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => true]);
        $this->mfaUser();

        $this->signIn(['amr' => ['pwd', 'mfa']])->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertTrue(session('auth.mfa_satisfied'));
        $this->assertNotSame('auth.mfa-required', $this->dash()->original?->name());
    }

    public function test_trust_on_but_no_amr_does_not_satisfy_mfa(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => true]);
        $this->mfaUser();

        $this->signIn()->assertRedirect(config('fortify.home', '/dashboard'));
        $this->assertFalse((bool) session('auth.mfa_satisfied'));
        $this->dash()->assertViewIs('auth.mfa-required');
    }

    public function test_trust_on_but_amr_without_mfa_does_not_satisfy_mfa(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => true]);
        $this->mfaUser();

        $this->signIn(['amr' => ['pwd']]);
        $this->assertFalse((bool) session('auth.mfa_satisfied'));
    }

    public function test_trust_off_ignores_amr_mfa(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => false]);
        $this->mfaUser();

        $this->signIn(['amr' => ['pwd', 'mfa']]);
        $this->assertFalse((bool) session('auth.mfa_satisfied'));
        $this->dash()->assertViewIs('auth.mfa-required');
    }

    // ---- via_pending promoted after the two-factor challenge --------------------------

    public function test_completing_the_two_factor_challenge_promotes_the_session_to_microsoft_and_revalidation_applies(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => false]);
        $user = $this->customer('amy@acme.com', null, [
            'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['rc-one-aaaaaaaa', 'rc-two-bbbbbbbb'])),
        ]);

        $this->signIn()->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertNull(session('auth.via'));

        $this->post('/two-factor-challenge', ['recovery_code' => 'rc-one-aaaaaaaa'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('microsoft', session('auth.via'));
        $this->assertSame(self::OID, session('auth.microsoft_oid'));
        $this->assertSame(self::TID, session('auth.microsoft_tid'));
        $this->assertNull(session('auth.via_pending'));
        $this->assertFalse((bool) session('auth.mfa_satisfied'));

        // Per-request revalidation now applies: unlinking ends the session.
        $user->forceFill(['microsoft_oid' => null, 'microsoft_tid' => null])->save();
        $this->dash()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_abandoned_sso_attempt_does_not_taint_a_later_password_login(): void
    {
        $this->configureMicrosoft(['trust_mfa_clients' => false]);
        $user = $this->customer('amy@acme.com', null, [
            'two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now(),
        ]);
        $this->signIn()->assertRedirect(route('two-factor.login'));
        $this->assertNotNull(session('auth.via_pending'));

        auth()->login($user); // any other login in the same session
        $this->assertNull(session('auth.via'));
        $this->assertNull(session('auth.via_pending'));
    }

    // ---- client edit revokes sessions ------------------------------------------------------

    public function test_changing_tenant_or_login_method_revokes_client_user_sessions_and_is_audited(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');
        $client = Client::create(['business_name' => 'Acme', 'microsoft_tenant_id' => self::TID]);
        $u = $this->customer('amy@acme.com', $client);
        $other = $this->customer('bob@other.com');

        $seed = fn () => DB::table('sessions')->insert([
            ['id' => 'a1', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'b1', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $seed();
        $base = ['business_name' => 'Acme', 'active' => 1, 'microsoft_tenant_id' => self::TID, 'login_method' => 'inherit'];

        // Unrelated change: nothing revoked.
        $this->actingAs($admin)->put(route('admin.clients.update', $client), ['abn' => '123'] + $base)->assertRedirect();
        $this->assertSame(2, DB::table('sessions')->whereIn('id', ['a1', 'b1'])->count());

        $this->actingAs($admin)->put(route('admin.clients.update', $client), ['microsoft_tenant_id' => self::OTHER_TID] + $base)->assertRedirect();
        $this->assertNull(DB::table('sessions')->where('id', 'a1')->first());
        $this->assertNotNull(DB::table('sessions')->where('id', 'b1')->first());
        $this->assertTrue(\App\Models\AuditLog::where('action', 'client.sso_settings.changed')->exists());

        $seed2 = DB::table('sessions')->insert(['id' => 'a2', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->put(route('admin.clients.update', $client), ['microsoft_tenant_id' => self::OTHER_TID, 'login_method' => 'microsoft_only'] + $base)->assertRedirect();
        $this->assertNull(DB::table('sessions')->where('id', 'a2')->first());
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'client.sso_settings.changed')->count());
    }

    // ---- customer history ------------------------------------------------------------------

    public function test_customer_history_hides_staff_runs_and_other_clients_runs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $client = Client::create(['business_name' => 'Acme']);
        $service = InternetService::create(['client_id' => $client->id, 'vocus_service_id' => 'S1', 'service_status' => 'ACTIVE', 'service_type' => 'FTTP', 'address_long' => '1 A St']);
        $client->update(['internet_diagnostics_enabled' => true]);

        $cust = User::factory()->create();
        $cust->assignRole('Customer');
        $cust->givePermissionTo('internet.diagnostics');
        $cust->clients()->attach($client->id);
        $tech = User::factory()->create();
        $tech->assignRole('Technician');
        $tech->clients()->attach($client->id); // even a staff user linked to the client must not show
        $stranger = $this->customer('zed@elsewhere.com');

        foreach ([[$cust, '20260101120000'], [$tech, '20260202120000'], [$stranger, '20260303120000'], [null, '20260404120000']] as [$by, $dt]) {
            InternetServiceDiagnostic::create([
                'internet_service_id' => $service->id, 'diagnostic_type' => 'AUTH-LOG', 'run_by_user_id' => $by?->id,
                'result' => ['records' => [['datetime' => $dt, 'auth_result' => 'PASS']]], 'created_at' => now(),
            ]);
        }

        $history = $this->actingAs($cust)->get(route('internet.show', $service))->assertOk()->viewData('history');
        $this->assertCount(1, $history);
    }

    // ---- migration ---------------------------------------------------------------------------

    public function test_permissions_migration_is_idempotent_and_grants_view_to_customer_only(): void
    {
        Permission::query()->whereIn('name', ['internet.view', 'internet.diagnostics'])->delete();
        $migration = require base_path('database/migrations/2026_12_06_000001_add_internet_permissions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, Permission::where('name', 'internet.view')->where('guard_name', 'web')->count());
        $this->assertSame(1, Permission::where('name', 'internet.diagnostics')->count());
        $customer = Role::findByName('Customer');
        $this->assertTrue($customer->hasPermissionTo('internet.view'));
        $this->assertFalse($customer->hasPermissionTo('internet.diagnostics'));
    }
}
