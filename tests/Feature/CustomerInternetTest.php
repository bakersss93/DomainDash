<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerInternetController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\InternetServiceDiagnostic;
use App\Models\Setting;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Halo\HaloPsaClient;
use App\Services\Vocus\VocusWsmClient;
use App\Support\Security\StaffPermissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerInternetTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;
    private Client $clientB;
    private InternetService $serviceA;
    private InternetService $serviceB;
    private User $customerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->clientA = Client::create(['business_name' => 'Client A', 'halopsa_reference' => '101']);
        $this->clientB = Client::create(['business_name' => 'Client B', 'halopsa_reference' => '202']);

        $plan = VocusPlan::create(['plan_id' => 'RC-SECRET-CODE', 'name' => 'Business 100', 'speed_tier' => '100/40', 'download_mbps' => 100, 'upload_mbps' => 40, 'cost_price' => 41.23, 'sell_price' => 77.77]);

        $common = [
            'service_status' => 'ACTIVE', 'service_type' => 'FTTP', 'plan_id' => 'RC-SECRET-CODE', 'vocus_plan_id' => $plan->id,
            'cost_price' => 41.23, 'sell_price' => 77.77, 'username' => 'pppoe-user@wba.example.com', 'realm' => 'wba.example.com',
            'avc_id' => 'AVC000111222', 'cvc_id' => 'CVC000999', 'ntd_id' => 'NTD-SECRET', 'notes' => 'INTERNAL-NOTE',
            'last_transaction_id' => 'TX-SECRET-1', 'halo_sync_error' => 'HALO-INTERNAL-ERR', 'halo_asset_id' => 55,
            'service_scope' => 'RESELLER-CONNECT',
        ];
        $this->serviceA = InternetService::create($common + ['vocus_service_id' => 'SVC-A-1', 'client_id' => $this->clientA->id, 'address_long' => '1 Alpha St, Sydney NSW']);
        $this->serviceB = InternetService::create($common + ['vocus_service_id' => 'SVC-B-1', 'client_id' => $this->clientB->id, 'address_long' => '9 Beta Rd, Perth WA']);

        $this->customerA = $this->customer($this->clientA);
    }

    private function customer(Client $client, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole('Customer');
        foreach ($extraPermissions as $p) {
            $user->givePermissionTo($p);
        }
        $user->clients()->attach($client->id);

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $admin->assignRole('Administrator');

        return $admin;
    }

    private function enableRequests(bool $new = true, bool $cancel = true): void
    {
        Setting::put('vocus', ['customer_requests' => ['allow_new' => $new, 'allow_cancel' => $cancel]]);
    }

    private function fakeVocusAuthLog(): void
    {
        Setting::put('vocus', array_merge((array) Setting::get('vocus', []), ['access_key' => 'AK', 'cert_path' => 'c.p12']));
        $this->mock(VocusWsmClient::class, function ($m) {
            $m->shouldReceive('getAuthLog')->with('SVC-A-1', 'RC-SECRET-CODE')->andReturn([
                'transaction_id' => 'TX-9',
                'records' => [
                    ['datetime' => '20260101120000', 'auth_result' => 'PASS', 'reason' => 'REASON-INTERNAL'],
                    ['datetime' => '20260101110000', 'auth_result' => 'FAIL', 'reason' => 'REASON-INTERNAL-2'],
                ],
            ]);
            $m->shouldNotReceive('disconnectSession');
        });
    }

    // ---- Seeder / permission model -------------------------------------------------------------------------------

    public function test_customer_role_defaults_are_view_only_and_inert_for_order_cancel_manage(): void
    {
        $perms = Role::findByName('Customer')->permissions->pluck('name')->all();

        $this->assertContains('internet.view', $perms);
        foreach (['internet.order', 'internet.cancel', 'internet.manage', 'internet.diagnostics'] as $p) {
            $this->assertNotContains($p, $perms);
        }
        $this->assertNotContains('internet.diagnostics', StaffPermissions::STAFF_ONLY);
        $this->assertNotContains('internet.order', StaffPermissions::STAFF_ONLY);
        $this->assertNotContains('internet.cancel', StaffPermissions::STAFF_ONLY);
    }

    // ---- Listing / isolation / leakage ---------------------------------------------------------------------------

    public function test_customer_lists_only_own_clients_services(): void
    {
        $this->actingAs($this->customerA)->get(route('internet.index'))
            ->assertOk()
            ->assertSee('SVC-A-1')
            ->assertSee('1 Alpha St')
            ->assertDontSee('SVC-B-1')
            ->assertDontSee('9 Beta Rd');
    }

    public function test_customer_cannot_view_or_diagnose_another_clients_service(): void
    {
        $this->clientA->update(['internet_diagnostics_enabled' => true]);
        $this->clientB->update(['internet_diagnostics_enabled' => true]);
        $user = $this->customer($this->clientA, ['internet.diagnostics']);
        $this->mock(VocusWsmClient::class, fn ($m) => $m->shouldNotReceive('getAuthLog'));

        $this->actingAs($user)->get(route('internet.show', $this->serviceB))->assertForbidden();
        $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceB), ['type' => 'AUTH-LOG'])->assertForbidden();
    }

    public function test_user_without_permission_or_client_cannot_see_internet(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('Customer');
        $this->actingAs($stranger)->get(route('internet.index'))->assertForbidden();

        $noPerm = $this->customer($this->clientA);
        $noPerm->revokePermissionTo('internet.view');
        $noPerm->roles()->detach();
        $this->actingAs($noPerm)->get(route('internet.show', $this->serviceA))->assertForbidden();
    }

    public function test_sensitive_fields_never_reach_the_customer_pages(): void
    {
        $this->clientA->update(['internet_diagnostics_enabled' => true]);
        $user = $this->customer($this->clientA, ['internet.diagnostics']);

        foreach ([route('internet.index'), route('internet.show', $this->serviceA)] as $url) {
            $res = $this->actingAs($user)->get($url)->assertOk();
            $res->assertSee('SVC-A-1')->assertSee('Business 100');
            foreach ([
                'pppoe-user', 'wba.example.com', 'AVC000111222', 'CVC000999', 'NTD-SECRET', 'INTERNAL-NOTE', 'TX-SECRET-1',
                'HALO-INTERNAL-ERR', 'RC-SECRET-CODE', '41.23', '77.77', 'cost_price', 'sell_price', 'halo_',
            ] as $secret) {
                $res->assertDontSee($secret);
            }
        }
    }

    public function test_staff_are_sent_to_the_admin_internet_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('internet.index'))->assertRedirect(route('admin.services.internet'));
    }

    public function test_sidebar_item_only_for_customers_with_a_service(): void
    {
        $this->actingAs($this->customerA)->get(route('dashboard'))->assertSee(route('internet.index'), false);

        $empty = Client::create(['business_name' => 'No Services']);
        $this->actingAs($this->customer($empty))->get(route('dashboard'))->assertDontSee(route('internet.index'), false);
    }

    // ---- Diagnostics gating --------------------------------------------------------------------------------------

    public function test_diagnostic_runs_when_flag_and_permission_are_on_and_shows_friendly_result(): void
    {
        $this->clientA->update(['internet_diagnostics_enabled' => true]);
        $user = $this->customer($this->clientA, ['internet.diagnostics']);
        $this->fakeVocusAuthLog();

        $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceA), ['type' => 'AUTH-LOG'])
            ->assertRedirect()->assertSessionHas('status');

        $stored = InternetServiceDiagnostic::first();
        $this->assertSame($user->id, $stored->run_by_user_id);
        $this->assertArrayNotHasKey('reason', $stored->result['records'][0], 'carrier reason text is not stored for customers');
        $this->assertTrue(AuditLog::where('action', 'internet.diagnostic')->exists());

        $this->actingAs($user)->get(route('internet.show', $this->serviceA))
            ->assertOk()
            ->assertSee('1 of 2 recent sign-in attempts succeeded')
            ->assertSee('Connected')
            ->assertDontSee('REASON-INTERNAL')
            ->assertDontSee('<AuthLogRecord', false);
    }

    public function test_diagnostic_refused_when_client_flag_is_off(): void
    {
        $user = $this->customer($this->clientA, ['internet.diagnostics']);
        $this->mock(VocusWsmClient::class, fn ($m) => $m->shouldNotReceive('getAuthLog'));

        $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceA), ['type' => 'AUTH-LOG'])->assertForbidden();
        $this->actingAs($user)->get(route('internet.show', $this->serviceA))->assertOk()->assertDontSee('Run connection test');
    }

    public function test_diagnostic_refused_when_permission_is_off(): void
    {
        $this->clientA->update(['internet_diagnostics_enabled' => true]);
        $this->mock(VocusWsmClient::class, fn ($m) => $m->shouldNotReceive('getAuthLog'));

        $this->actingAs($this->customerA)->post(route('internet.diagnostic', $this->serviceA), ['type' => 'AUTH-LOG'])->assertForbidden();
    }

    public function test_disruptive_diagnostic_types_are_rejected(): void
    {
        $this->clientA->update(['internet_diagnostics_enabled' => true]);
        $user = $this->customer($this->clientA, ['internet.diagnostics']);
        $this->mock(VocusWsmClient::class, fn ($m) => $m->shouldNotReceive('disconnectSession', 'getAuthLog'));

        $this->assertSame(['AUTH-LOG'], CustomerInternetController::CUSTOMER_DIAGNOSTICS);
        $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceA), ['type' => 'DISCONNECT'])->assertSessionHasErrors('type');
        $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceA), [])->assertSessionHasErrors('type');
    }

    public function test_diagnostic_is_rate_limited(): void
    {
        $this->clientA->update(['internet_diagnostics_enabled' => true]);
        $user = $this->customer($this->clientA, ['internet.diagnostics']);
        $this->fakeVocusAuthLog();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceA), ['type' => 'AUTH-LOG'])->assertRedirect();
        }
        $this->actingAs($user)->post(route('internet.diagnostic', $this->serviceA), ['type' => 'AUTH-LOG'])->assertStatus(429);
    }

    public function test_admin_client_form_saves_the_diagnostics_flag(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.clients.update', $this->clientA), [
            'business_name' => 'Client A', 'internet_diagnostics_enabled' => '1', 'active' => '1',
        ]);
        $this->assertTrue($this->clientA->fresh()->internet_diagnostics_enabled);

        $this->actingAs($admin)->put(route('admin.clients.update', $this->clientA), ['business_name' => 'Client A', 'internet_diagnostics_enabled' => '0']);
        $this->assertFalse($this->clientA->fresh()->internet_diagnostics_enabled);
    }

    // ---- Ordering / cancellation requests ------------------------------------------------------------------------

    private function haloConfigured(): void
    {
        Setting::put('halo', [
            'base_url' => 'https://halo.example.com',
            'ticket_type_mappings' => [['service_category' => 'Internet', 'ticket_type' => 'Service Request', 'halo_ticket_type_id' => 77]],
        ]);
    }

    public function test_requests_are_forbidden_and_buttons_hidden_by_default(): void
    {
        $user = $this->customer($this->clientA, ['internet.order', 'internet.cancel']);
        $this->mock(HaloPsaClient::class, fn ($m) => $m->shouldNotReceive('createTicket'));

        $this->actingAs($user)->post(route('internet.request-new'), ['client_id' => $this->clientA->id, 'address' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('internet.request-cancellation', $this->serviceA))->assertForbidden();
        $this->actingAs($user)->get(route('internet.index'))->assertDontSee('Request new service');
        $this->actingAs($user)->get(route('internet.show', $this->serviceA))->assertDontSee('Request cancellation');
    }

    public function test_requests_are_forbidden_when_setting_on_but_permission_missing(): void
    {
        $this->enableRequests();
        $this->mock(HaloPsaClient::class, fn ($m) => $m->shouldNotReceive('createTicket'));

        $this->actingAs($this->customerA)->post(route('internet.request-new'), ['client_id' => $this->clientA->id, 'address' => 'x'])->assertForbidden();
        $this->actingAs($this->customerA)->post(route('internet.request-cancellation', $this->serviceA))->assertForbidden();
    }

    public function test_cancellation_request_creates_a_ticket_and_never_calls_the_carrier(): void
    {
        $this->enableRequests();
        $this->haloConfigured();
        $user = $this->customer($this->clientA, ['internet.cancel']);

        $this->mock(VocusWsmClient::class, fn ($m) => $m->shouldNotReceive('cancelService', 'setStatus', 'disconnectSession'));
        $this->mock(HaloPsaClient::class, function ($m) {
            $m->shouldReceive('createTicket')->once()->withArgs(function (array $p) {
                return $p['client_id'] === 101 && $p['tickettype_id'] === 77
                    && str_contains($p['summary'], 'SVC-A-1') && str_contains($p['details'], 'Reason: Moving office')
                    && $p['asset_id'] === 55;
            })->andReturn(['id' => 1]);
        });

        $this->actingAs($user)->get(route('internet.show', $this->serviceA))->assertSee('Request cancellation');
        $this->actingAs($user)->post(route('internet.request-cancellation', $this->serviceA), ['reason' => 'Moving office'])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertTrue(AuditLog::where('action', 'internet.request_cancellation')->exists());
        $this->assertSame('ACTIVE', $this->serviceA->fresh()->service_status);
    }

    public function test_cancellation_request_for_another_clients_service_is_forbidden(): void
    {
        $this->enableRequests();
        $this->haloConfigured();
        $user = $this->customer($this->clientA, ['internet.cancel']);
        $this->mock(HaloPsaClient::class, fn ($m) => $m->shouldNotReceive('createTicket'));

        $this->actingAs($user)->post(route('internet.request-cancellation', $this->serviceB))->assertForbidden();
    }

    public function test_new_service_request_creates_a_ticket_for_own_client_only(): void
    {
        $this->enableRequests();
        $this->haloConfigured();
        $user = $this->customer($this->clientA, ['internet.order']);

        $this->mock(HaloPsaClient::class, function ($m) {
            $m->shouldReceive('createTicket')->once()->withArgs(fn (array $p) => $p['client_id'] === 101
                && $p['tickettype_id'] === 77 && str_contains($p['details'], '5 Gamma Ave'))->andReturn(['id' => 2]);
        });

        $this->actingAs($user)->get(route('internet.index'))->assertSee('Request new service');
        $this->actingAs($user)->post(route('internet.request-new'), ['client_id' => $this->clientB->id, 'address' => '5 Gamma Ave'])->assertForbidden();
        $this->actingAs($user)->post(route('internet.request-new'), ['client_id' => $this->clientA->id, 'address' => '5 Gamma Ave', 'speed' => '100/40'])
            ->assertRedirect(route('internet.index'))->assertSessionHas('status');
    }

    public function test_request_without_internet_ticket_mapping_reports_an_error_and_creates_nothing(): void
    {
        $this->enableRequests();
        Setting::put('halo', ['base_url' => 'https://halo.example.com', 'ticket_type_mappings' => []]);
        $user = $this->customer($this->clientA, ['internet.order']);
        $this->mock(HaloPsaClient::class, fn ($m) => $m->shouldNotReceive('createTicket'));

        $this->actingAs($user)->post(route('internet.request-new'), ['client_id' => $this->clientA->id, 'address' => 'x'])
            ->assertSessionHasErrors('halo');
    }

    public function test_admin_setting_toggles_customer_requests_and_defaults_off(): void
    {
        $this->assertFalse(\App\Support\InternetCustomerSettings::allowNewRequests());
        $this->assertFalse(\App\Support\InternetCustomerSettings::allowCancellationRequests());

        $this->actingAs($this->admin())->post(route('admin.settings.update'), [
            'vocus' => ['customer_requests' => ['allow_new' => '1', 'allow_cancel' => '0']],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(\App\Support\InternetCustomerSettings::allowNewRequests());
        $this->assertFalse(\App\Support\InternetCustomerSettings::allowCancellationRequests());
    }
}
