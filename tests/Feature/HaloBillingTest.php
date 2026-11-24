<?php

namespace Tests\Feature;

use App\Jobs\PushInternetServicesToHaloJob;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\Setting;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Halo\HaloBillingSettings;
use App\Services\Halo\HaloPsaClient;
use App\Services\Sync\HaloSubscriptionPusher;
use App\Services\Sync\SyncItemFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halo billing of Vocus internet services. Payload shapes follow the tenant swagger
 * (docs/review/16-halo-subscriptions-swagger-facts.md). Not run in the build environment (no vendor/):
 *   php artisan test --filter=HaloBillingTest
 */
class HaloBillingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private VocusPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake(); // the observer's delayed jobs must not run inline (sync queue)
        Http::preventStrayRequests();
        Setting::put('halo', [
            'base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key',
            'billing' => ['sync_subscriptions' => '1', 'sync_lines' => '1', 'strategy' => 'contract'],
        ]);

        $this->client = Client::create(['business_name' => 'Acme Pty Ltd', 'halopsa_reference' => '42']);
        $this->plan = VocusPlan::create([
            'plan_id' => 'FTTP-100-20', 'name' => 'FTTP 100/20', 'cost_price' => '40.00', 'sell_price' => '80.00',
            'active' => true, 'halo_item_id' => 9, 'halo_item_name' => 'NBN 100/20',
        ]);
    }

    private function admin(bool $administrator = true, bool $sync = true): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        if ($administrator) {
            $user->assignRole('Administrator');
        }
        if ($sync) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function service(array $attrs = []): InternetService
    {
        return InternetService::create($attrs + [
            'vocus_service_id' => 'DD-1', 'client_id' => $this->client->id, 'plan_id' => 'FTTP-100-20',
            'vocus_plan_id' => $this->plan->id, 'cost_price' => '40.00', 'sell_price' => '80.00',
            'service_status' => 'ACTIVE', 'address_long' => '1 Test St, Sydney NSW 2000', 'username' => 'acme@example.com.au',
        ]);
    }

    /**
     * @param array<int,array> $subs     existing subscriptions returned by GET /SoftwareLicence
     * @param array<int,array> $invoices recurring invoices returned for the client / contract
     * @param array<int,array> $lines    lines of the recurring invoice
     */
    private function fakeHalo(array $subs = [], ?array $invoices = null, array $lines = [], ?array $contracts = null): void
    {
        $invoices ??= [['id' => 700, 'name' => 'Managed services', 'client_id' => 42, 'contract_id' => 55, 'contract_ref' => 'MS-1']];
        $contracts ??= [['id' => 55, 'ref' => 'MS-1', 'client_id' => 42, 'contracttype' => 3, 'contracttype_name' => 'Managed', 'active' => true]];

        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/item/9*' => Http::response(['id' => 9, 'name' => 'NBN 100/20', 'baseprice' => 80, 'costprice' => 40]),
            'halo.example.com/api/item*' => Http::response(['record_count' => 2, 'items' => [
                ['id' => 9, 'name' => 'NBN 100/20', 'internalreference' => 'NBN100', 'baseprice' => 80, 'costprice' => 40, 'recurringprice' => 80, 'isrecurringitem' => true, 'assetgroup_name' => 'Internet'],
                ['id' => 10, 'name' => 'Setup fee', 'baseprice' => 99.5, 'isrecurringitem' => false],
            ]]),
            'halo.example.com/api/softwarelicence*' => fn (HttpRequest $r) => $r->method() === 'POST'
                ? Http::response([array_merge(['id' => 501], $r->data()[0] ?? [])])
                : Http::response(['record_count' => count($subs), 'licences' => $subs]),
            'halo.example.com/api/clientcontract*' => Http::response(['record_count' => count($contracts), 'contracts' => $contracts]),
            'halo.example.com/api/recurringinvoice/lines*' => Http::response([['id' => 801]]),
            'halo.example.com/api/recurringinvoice/updatelines*' => Http::response([['id' => 801]]),
            'halo.example.com/api/recurringinvoice/700*' => Http::response(['id' => 700, 'lines' => $lines]),
            'halo.example.com/api/recurringinvoice*' => Http::response(['record_count' => count($invoices), 'invoices' => $invoices]),
        ]);
    }

    private function posts(string $pathEnd): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $pathEnd))
            ->map(fn ($pair) => $pair[0]->data()[0] ?? [])->values()->all();
    }

    private function push(InternetService $service, bool $force = false): string
    {
        return app(HaloSubscriptionPusher::class)->push(
            $service->fresh(['client', 'vocusPlan']), app(HaloPsaClient::class), HaloBillingSettings::fromSettings(), $force
        );
    }

    // ------------------------------------------------------------ item picker

    public function test_item_search_proxy_normalises_items_and_caches(): void
    {
        $this->fakeHalo();
        $admin = $this->admin();

        $json = $this->actingAs($admin)->getJson('/admin/settings/halo/items?q=nbn')->assertOk()->json();
        $this->actingAs($admin)->getJson('/admin/settings/halo/items?q=nbn')->assertOk();

        $this->assertSame(9, $json['items'][0]['id']);
        $this->assertSame('NBN100', $json['items'][0]['code']);
        $this->assertTrue($json['items'][0]['recurring']);
        $this->assertSame(80.0, $json['items'][0]['price']);
        $this->assertFalse($json['items'][1]['recurring']);
        $this->assertStringNotContainsString('secret-api-key', json_encode($json));
        $count = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/api/item') && str_contains($r->url(), 'search=nbn'))->count();
        $this->assertSame(1, $count, 'second search is served from the 60 s cache');
    }

    public function test_item_search_and_link_require_administrator(): void
    {
        $this->fakeHalo();
        $user = $this->admin(false, true);

        $this->actingAs($user)->getJson('/admin/settings/halo/items')->assertForbidden();
        $this->actingAs($user)->putJson('/admin/settings/vocus/plans/' . $this->plan->id . '/halo-item', ['halo_item_id' => 9])->assertForbidden();
    }

    public function test_link_and_unlink_plan_item(): void
    {
        $this->fakeHalo();
        $admin = $this->admin();
        $this->plan->update(['halo_item_id' => null, 'halo_item_name' => null]);

        $this->actingAs($admin)->putJson('/admin/settings/vocus/plans/' . $this->plan->id . '/halo-item', ['halo_item_id' => 9])
            ->assertOk()->assertJsonPath('plan.halo_item_name', 'NBN 100/20');
        $this->assertSame(9, (int) $this->plan->fresh()->halo_item_id);

        $this->actingAs($admin)->putJson('/admin/settings/vocus/plans/' . $this->plan->id . '/halo-item', ['halo_item_id' => null])->assertOk();
        $this->assertNull($this->plan->fresh()->halo_item_id);
        $this->assertNull($this->plan->fresh()->halo_item_name);
    }

    // ------------------------------------------------------------ subscription + line

    public function test_first_push_creates_subscription_and_recurring_line(): void
    {
        $this->fakeHalo();
        $service = $this->service();

        $this->assertSame('created', $this->push($service));

        $sub = $this->posts('/api/softwarelicence')[0];
        $this->assertArrayNotHasKey('id', $sub);
        $this->assertSame(42, $sub['client_id']);
        $this->assertSame(9, $sub['item_id']);
        $this->assertSame(1, $sub['count']);
        $this->assertSame(80.0, $sub['price']);
        $this->assertSame(40.0, $sub['purchase_price']);
        $this->assertTrue($sub['is_active']);
        $this->assertStringContainsString('[[DOMAINDASH-SVC:DD-1]]', $sub['notes']);

        $line = $this->posts('/api/recurringinvoice/lines')[0];
        $this->assertSame(700, $line['recurring_invoice_id']);
        $this->assertSame(9, $line['_itemid']);
        $this->assertSame(501, $line['quantity_subscriptions'][0]['licence_id']);

        $fresh = $service->fresh();
        $this->assertSame(501, (int) $fresh->halo_subscription_id);
        $this->assertSame(801, (int) $fresh->halo_recurring_line_id);
        $this->assertSame(700, (int) $fresh->halo_recurring_invoice_id);
        $this->assertNotNull($fresh->halo_synced_at);
        $this->assertNull($fresh->halo_sync_error);
    }

    public function test_second_push_with_unchanged_data_is_skipped_without_calls(): void
    {
        $this->fakeHalo();
        $service = $this->service();
        $this->push($service);
        Http::fake(); // from here any request would be recorded below
        Http::preventStrayRequests(false);

        $this->assertSame('skipped', $this->push($service));
        Http::assertNothingSent();
    }

    public function test_price_change_updates_existing_subscription_and_line_instead_of_creating(): void
    {
        $this->fakeHalo();
        $service = $this->service();
        $this->push($service);

        $service->update(['sell_price' => '95.00']);
        $this->fakeHalo(
            subs: [['id' => 501, 'notes' => '[[DOMAINDASH-SVC:DD-1]]']],
            lines: [['id' => 801, 'quantity_subscriptions' => [['licence_id' => 501]]]]
        );

        $this->assertSame('updated', $this->push($service));

        $sub = $this->posts('/api/softwarelicence')[0];
        $this->assertSame(501, $sub['id']);
        $this->assertSame(95.0, $sub['price']);
        $line = $this->posts('/api/recurringinvoice/updatelines')[0];
        $this->assertSame(801, $line['id']);
        $this->assertSame(95.0, $line['unit_price']);
        $this->assertSame([], $this->posts('/api/recurringinvoice/lines'), 'no second line is added');
    }

    public function test_existing_subscription_is_found_by_marker_when_id_is_unknown(): void
    {
        $this->fakeHalo(subs: [['id' => 333, 'notes' => "[[DOMAINDASH-SVC:DD-1]]\nVocus service: DD-1"]]);
        $service = $this->service();

        $this->push($service);

        $this->assertSame(333, $this->posts('/api/softwarelicence')[0]['id']);
        $this->assertSame(333, (int) $service->fresh()->halo_subscription_id);
    }

    public function test_cancellation_sets_end_dates_and_deactivates_without_deleting(): void
    {
        $this->fakeHalo();
        $service = $this->service();
        $this->push($service);

        $service->update(['service_status' => 'INACTIVE', 'last_transaction_state' => 'SUCCESS']);
        $this->fakeHalo(lines: [['id' => 801, 'quantity_subscriptions' => [['licence_id' => 501]]]]);

        $this->assertSame('ended', $this->push($service));

        $sub = $this->posts('/api/softwarelicence')[0];
        $this->assertFalse($sub['is_active']);
        $this->assertNotNull($sub['end_date']);
        $line = $this->posts('/api/recurringinvoice/updatelines')[0];
        $this->assertTrue($line['isinactive']);
        $this->assertNotNull($line['enddate']);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE');
    }

    public function test_service_still_provisioning_is_not_billed(): void
    {
        $this->fakeHalo();
        $service = $this->service(['service_status' => 'INACTIVE', 'last_transaction_state' => 'QUEUED']);

        $this->assertSame('skipped', $this->push($service));
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ errors

    public function test_missing_recurring_invoice_gives_a_clear_error_and_creates_nothing_on_the_invoice(): void
    {
        $this->fakeHalo(invoices: []);
        $service = $this->service();

        try {
            $this->push($service);
            $this->fail('expected SyncItemFailed');
        } catch (SyncItemFailed $e) {
            $this->assertStringContainsString('No recurring invoice for client Acme Pty Ltd - select one', $e->getMessage());
        }
        $this->assertSame([], $this->posts('/api/recurringinvoice/lines'));
    }

    public function test_job_records_error_on_service_and_continues(): void
    {
        $this->fakeHalo(invoices: []);
        $bad = $this->service();
        $noItem = VocusPlan::create(['plan_id' => 'NOITEM', 'name' => 'No item', 'cost_price' => '1.00', 'sell_price' => '2.00', 'active' => true]);
        $other = $this->service(['vocus_service_id' => 'DD-2', 'vocus_plan_id' => $noItem->id]);

        (new PushInternetServicesToHaloJob([$bad->id, $other->id], true))->handle(app(HaloSubscriptionPusher::class));

        $this->assertStringContainsString('select one', $bad->fresh()->halo_sync_error);
        $this->assertStringContainsString('has no Halo item', $other->fresh()->halo_sync_error);
    }

    public function test_strategy_client_requires_a_chosen_invoice_and_chosen_invoice_wins(): void
    {
        $halo = Setting::get('halo');
        $halo['billing']['strategy'] = 'client';
        Setting::put('halo', $halo);
        $this->fakeHalo();
        $service = $this->service();

        try {
            $this->push($service);
            $this->fail('expected SyncItemFailed');
        } catch (SyncItemFailed $e) {
            $this->assertStringContainsString('select one', $e->getMessage());
        }

        $this->client->forceFill(['halo_recurring_invoice_id' => 700])->save();
        $this->assertSame('created', $this->push($service));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/clientcontract'));
    }

    // ------------------------------------------------------------ client invoice picker

    public function test_client_recurring_invoice_can_only_be_one_of_its_own(): void
    {
        $this->fakeHalo();
        $admin = $this->admin();
        $url = '/admin/clients/' . $this->client->id . '/halo/recurring-invoice';

        $this->actingAs($admin)->putJson($url, ['recurring_invoice_id' => 999])->assertStatus(422);
        $this->actingAs($admin)->putJson($url, ['recurring_invoice_id' => 700])->assertOk()->assertJsonPath('selected_id', 700);
        $this->assertSame(700, (int) $this->client->fresh()->halo_recurring_invoice_id);

        $this->actingAs($admin)->putJson($url, ['recurring_invoice_id' => null])->assertOk();
        $this->assertNull($this->client->fresh()->halo_recurring_invoice_id);
    }

    // ------------------------------------------------------------ authz + dispatch

    public function test_sync_routes_need_administrator_and_sync_permission(): void
    {
        $service = $this->service();

        $this->actingAs($this->admin(true, false))->postJson('/admin/services/internet/' . $service->id . '/halo-sync')->assertForbidden();
        $this->actingAs($this->admin(true, false))->postJson('/admin/services/internet/halo-sync')->assertForbidden();
        $this->actingAs($this->admin(false, true))->postJson('/admin/services/internet/halo-sync')->assertForbidden();
    }

    public function test_sync_service_queues_a_run_and_bulk_sync_returns_run(): void
    {
        $this->fakeHalo();
        $service = $this->service();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/services/internet/' . $service->id . '/halo-sync')
            ->assertStatus(202)->assertJsonPath('runs.0.scope', 'subscriptions');
        Queue::assertPushed(PushInternetServicesToHaloJob::class, fn ($j) => $j->serviceIds === [$service->id] && $j->force === true);

        $this->actingAs($admin)->postJson('/admin/services/internet/halo-sync')->assertStatus(202);
    }

    public function test_sync_is_refused_while_billing_is_switched_off(): void
    {
        $halo = Setting::get('halo');
        $halo['billing'] = ['sync_subscriptions' => '0', 'sync_lines' => '0'];
        Setting::put('halo', $halo);
        $service = $this->service();

        $this->actingAs($this->admin())->postJson('/admin/services/internet/' . $service->id . '/halo-sync')->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_observer_queues_a_single_debounced_job_on_client_assign_and_status_change(): void
    {
        $service = $this->service();
        $service->update(['service_status' => 'SUSPEND']);
        $service->update(['client_id' => $this->client->id, 'service_status' => 'ACTIVE']);

        Queue::assertPushed(PushInternetServicesToHaloJob::class, 1);
    }
}
