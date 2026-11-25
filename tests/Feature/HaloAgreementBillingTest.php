<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\Setting;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Halo\HaloAgreementResolver;
use App\Services\Halo\HaloBillingSettings;
use App\Services\Halo\HaloLineTemplate;
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
 * Agreement matching (Contract Type + Agreement Subtype + Reference), per-service override, client pin fallback, line
 * text templates and the contract type / subtype pickers. Payloads are swagger-shaped (docs/review/16).
 *   php artisan test --filter=HaloAgreementBillingTest
 */
class HaloAgreementBillingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private VocusPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        $this->billing([]);

        $this->client = Client::create(['business_name' => 'Example Client Pty Ltd', 'halopsa_reference' => '42']);
        $this->plan = VocusPlan::create([
            'plan_id' => 'FTTP-1000-50', 'name' => 'Fibre1000', 'speed_tier' => '1000/50', 'technology' => 'FTTP',
            'cost_price' => '300.00', 'sell_price' => '605.00', 'active' => true, 'halo_item_id' => 9, 'halo_item_name' => 'Connectivity - Fibre1000',
        ]);
    }

    private function billing(array $over): void
    {
        Setting::put('halo', [
            'base_url' => 'https://halo.example.com', 'client_id' => 'cid', 'api_key' => 'secret-api-key',
            'billing' => $over + ['sync_subscriptions' => '0', 'sync_lines' => '1', 'strategy' => 'contract'],
        ]);
    }

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('Administrator');
        $user->givePermissionTo($permission);

        return $user;
    }

    private function service(array $attrs = []): InternetService
    {
        return InternetService::create($attrs + [
            'vocus_service_id' => 'DD-1', 'client_id' => $this->client->id, 'plan_id' => 'FTTP-1000-50',
            'vocus_plan_id' => $this->plan->id, 'cost_price' => '300.00', 'sell_price' => '605.00',
            'service_status' => 'ACTIVE', 'address_long' => '1 Test St, Sydney NSW 2000', 'username' => 'user1@example.com.au',
            'avc_id' => 'AVC000011112222', 'directory_id' => 'LOC000012345678',
        ]);
    }

    private function contract(int $id, string $ref, int $type = 3, int $subtype = 7, string $typeName = 'Internet Service'): array
    {
        return ['id' => $id, 'client_id' => 42, 'ref' => $ref, 'contracttype' => $type, 'contracttype_name' => $typeName, 'subtype' => $subtype, 'active' => true];
    }

    private function invoice(int $id, int $contractId, string $ref, string $name = 'Example Client Pty Ltd'): array
    {
        return ['id' => $id, 'name' => $name, 'client_id' => 42, 'contract_id' => $contractId, 'contract_ref' => $ref];
    }

    /** Contracts are filtered by the query like Halo does; invoices come back for the whole client. */
    private function fakeHalo(array $contracts, array $invoices, array $lookup = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'halo.example.com/auth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'halo.example.com/api/client/42*' => Http::response(['id' => 42, 'name' => 'Example Client Pty Ltd', 'ref' => 'EXA001']),
            // $lookup: rows per lookupid
            'halo.example.com/api/lookup*' => function (HttpRequest $r) use ($lookup) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

                return Http::response($lookup[(int) ($q['lookupid'] ?? 0)] ?? []);
            },
            'halo.example.com/api/clientcontract*' => function (HttpRequest $r) use ($contracts) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $rows = array_values(array_filter($contracts, fn ($c) => (! isset($q['contract_type']) || $c['contracttype'] == $q['contract_type'])
                    && (! isset($q['contract_sub_type']) || $c['subtype'] == $q['contract_sub_type'])));

                return Http::response(['page_no' => 1, 'page_size' => 100, 'record_count' => count($rows), 'contracts' => $rows]);
            },
            'halo.example.com/api/recurringinvoice/lines*' => Http::response([['id' => 801]]),
            'halo.example.com/api/recurringinvoice/updatelines*' => Http::response([['id' => 801]]),
            'halo.example.com/api/recurringinvoice/*' => fn (HttpRequest $r) => Http::response(['id' => 1, 'lines' => []]),
            'halo.example.com/api/recurringinvoice*' => Http::response(['record_count' => count($invoices), 'invoices' => $invoices]),
        ]);
    }

    private function push(InternetService $service): string
    {
        return app(HaloSubscriptionPusher::class)->push(
            $service->fresh(['client', 'vocusPlan']), app(HaloPsaClient::class), HaloBillingSettings::fromSettings(), true
        );
    }

    private function lines(): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/recurringinvoice/lines'))
            ->map(fn ($pair) => $pair[0]->data()[0] ?? [])->values()->all();
    }

    private function failure(callable $fn): string
    {
        try {
            $fn();
        } catch (SyncItemFailed $e) {
            return $e->getMessage();
        }
        $this->fail('expected SyncItemFailed');
    }

    // ------------------------------------------------------------ pickers

    public function test_contract_type_and_subtype_pickers_read_the_halo_lookups_and_are_cached(): void
    {
        $this->fakeHalo(
            [$this->contract(1, 'Connectivity - Fibre1000 - EXA001')],
            [],
            [
                28 => [['id' => 30, 'name' => 'Internet Service', 'prefix' => 'ISP'], ['id' => 22, 'name' => 'Hosting Services'], ['id' => 9, 'name' => 'Old type', 'inactive' => true]],
                76 => [['id' => 20, 'name' => 'Example NBN Link'], ['id' => 22, 'name' => 'Fibre Link'], ['id' => 8, 'name' => 'Retired subtype', 'inactive' => true]],
            ],
        );
        $admin = $this->admin();

        $types = $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.contract-types'))->assertOk()->json('options');
        $this->assertSame([['id' => '22', 'label' => 'Hosting Services'], ['id' => '30', 'label' => 'Internet Service']], $types);

        $sub = $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.contract-subtypes'))->assertOk()->json('options');
        $this->assertSame([['id' => '20', 'label' => 'Example NBN Link'], ['id' => '22', 'label' => 'Fibre Link']], $sub, 'agreement references are never offered as subtypes');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/lookup') && str_contains($r->url(), 'lookupid=28'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/lookup') && str_contains($r->url(), 'lookupid=76'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/clientcontract'));

        $before = Http::recorded()->count();
        $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.contract-subtypes'))->assertOk()->assertJson(['cached' => true]);
        $this->assertSame($before, Http::recorded()->count(), 'second load comes from the cache');
        $this->actingAs($admin)->getJson(route('admin.settings.options', ['halo.contract-subtypes', 'refresh' => 1]))->assertOk();
        $this->assertGreaterThan($before, Http::recorded()->count(), 'refresh bypasses the cache');
    }

    public function test_lookup_ids_can_be_changed_in_settings(): void
    {
        $this->billing(['type_lookup_id' => '40', 'subtype_lookup_id' => '41']);
        $this->fakeHalo([], [], [40 => [['id' => 1, 'name' => 'Custom type']], 41 => [['id' => 2, 'name' => 'Custom subtype']]]);
        $admin = $this->admin();

        $this->assertSame('Custom type', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.contract-types'))->json('options.0.label'));
        $this->assertSame('Custom subtype', $this->actingAs($admin)->getJson(route('admin.settings.options', 'halo.contract-subtypes'))->json('options.0.label'));
    }

    public function test_contract_types_fall_back_to_agreements_when_the_lookup_is_empty(): void
    {
        $this->fakeHalo([$this->contract(1, 'Connectivity - Fibre1000 - EXA001'), $this->contract(2, 'Hosting - EXA001', 4, 9, 'Hosting')], []);

        $types = $this->actingAs($this->admin())->getJson(route('admin.settings.options', 'halo.contract-types'))->assertOk()->json('options');
        $this->assertSame([['id' => '4', 'label' => 'Hosting'], ['id' => '3', 'label' => 'Internet Service']], $types);

        $sub = $this->actingAs($this->admin())->getJson(route('admin.settings.options', 'halo.contract-subtypes'))->assertOk()->json('options');
        $this->assertSame([], $sub, 'subtypes come only from the lookup');
    }

    // ------------------------------------------------------------ agreement selection

    public function test_agreement_is_found_by_contract_type_and_subtype(): void
    {
        $this->billing(['contract_type_id' => '3', 'contract_subtype_id' => '7']);
        $this->fakeHalo(
            [$this->contract(1, 'Connectivity - Fibre1000 - EXA001'), $this->contract(2, 'Managed - EXA001', 5, 7, 'Managed'), $this->contract(3, 'Other link', 3, 8)],
            [$this->invoice(205, 1, 'Connectivity - Fibre1000 - EXA001'), $this->invoice(300, 2, 'Managed - EXA001'), $this->invoice(301, 3, 'Other link')],
        );
        $service = $this->service();

        $this->push($service);

        $this->assertSame(205, $this->lines()[0]['recurring_invoice_id']);
        $fresh = $service->fresh();
        $this->assertSame(205, (int) $fresh->halo_recurring_invoice_id);
        $this->assertSame(1, (int) $fresh->halo_contract_id);
        $this->assertSame('Connectivity - Fibre1000 - EXA001', $fresh->halo_contract_ref);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/clientcontract')
            && str_contains($r->url(), 'contract_type=3') && str_contains($r->url(), 'contract_sub_type=7'));
    }

    public function test_client_with_one_agreement_per_service_picks_the_agreement_naming_the_service(): void
    {
        $this->billing(['contract_type_id' => '3', 'contract_subtype_id' => '7']);
        $this->fakeHalo(
            [$this->contract(1, 'Connectivity - Fibre1000 - DD-1'), $this->contract(2, 'Connectivity - Fibre100 - DD-2')],
            [$this->invoice(205, 1, 'Connectivity - Fibre1000 - DD-1'), $this->invoice(206, 2, 'Connectivity - Fibre100 - DD-2')],
        );
        $a = $this->service();
        $b = $this->service(['vocus_service_id' => 'DD-2', 'username' => 'user2@example.com.au', 'avc_id' => 'AVC000099998888']);

        $this->push($a);
        $this->push($b);

        $this->assertSame(205, (int) $a->fresh()->halo_recurring_invoice_id);
        $this->assertSame(206, (int) $b->fresh()->halo_recurring_invoice_id);
    }

    public function test_plan_name_in_the_reference_picks_between_agreements(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo(
            [$this->contract(1, 'Connectivity - Fibre1000 - EXA001'), $this->contract(2, 'Connectivity - Fibre100 - EXA001')],
            [$this->invoice(205, 1, 'x'), $this->invoice(206, 2, 'y')],
        );
        $service = $this->service();

        $this->push($service);

        $this->assertSame(205, (int) $service->fresh()->halo_recurring_invoice_id);
    }

    public function test_reference_pattern_tokens_are_filled_from_the_service_and_client(): void
    {
        $this->billing(['contract_ref' => 'Connectivity - {plan} - {client_code}']);
        $this->fakeHalo(
            [$this->contract(1, 'Connectivity - Fibre1000 - EXA001'), $this->contract(2, 'Connectivity - Fibre1000 - ZZZ999')],
            [$this->invoice(205, 1, 'x'), $this->invoice(206, 2, 'y')],
        );
        $service = $this->service();

        $this->push($service);

        $this->assertSame(205, (int) $service->fresh()->halo_recurring_invoice_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/client/42'));
    }

    public function test_one_matching_agreement_is_used_even_if_it_does_not_name_the_service(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Internet agreement')], [$this->invoice(205, 1, 'x')]);
        $service = $this->service();

        $this->push($service);

        $this->assertSame(205, (int) $service->fresh()->halo_recurring_invoice_id);
    }

    public function test_ambiguous_agreements_give_an_error_listing_the_candidates_and_write_nothing(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo(
            [$this->contract(1, 'Link A'), $this->contract(2, 'Link B')],
            [$this->invoice(205, 1, 'x'), $this->invoice(206, 2, 'y')],
        );

        $message = $this->failure(fn () => $this->push($this->service()));

        $this->assertStringContainsString('2 matching agreements', $message);
        $this->assertStringContainsString('Link A, Link B', $message);
        $this->assertSame([], $this->lines());
    }

    public function test_agreement_without_a_recurring_invoice_is_a_clear_error(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A')], []);

        $message = $this->failure(fn () => $this->push($this->service()));

        $this->assertStringContainsString('No recurring invoice for client Example Client Pty Ltd - select one', $message);
        $this->assertStringContainsString('"Link A"', $message);
    }

    public function test_no_agreement_for_the_rule_is_a_clear_error_naming_the_rule(): void
    {
        $this->billing(['contract_type_id' => '3', 'contract_subtype_id' => '99']);
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'x')]);

        $message = $this->failure(fn () => $this->push($this->service()));

        $this->assertStringContainsString('No active agreement found for client Example Client Pty Ltd (contract type #3, subtype #99)', $message);
    }

    public function test_explicit_service_override_wins_without_looking_up_agreements(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A'), $this->contract(2, 'Link B')], [$this->invoice(205, 1, 'x'), $this->invoice(206, 2, 'y')]);
        $this->client->forceFill(['halo_recurring_invoice_id' => 205])->save();
        $service = $this->service(['halo_override_invoice_id' => 206, 'halo_override_invoice_name' => 'y - Link B']);

        $this->push($service);

        $this->assertSame(206, $this->lines()[0]['recurring_invoice_id']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/clientcontract'));
    }

    public function test_client_pin_is_the_fallback_when_the_rule_finds_nothing_or_is_ambiguous(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A'), $this->contract(2, 'Link B')], [$this->invoice(205, 1, 'x'), $this->invoice(206, 2, 'y')]);
        $this->client->forceFill(['halo_recurring_invoice_id' => 777, 'halo_recurring_invoice_name' => 'Pinned'])->save();

        $this->push($this->service()); // ambiguous

        $this->assertSame(777, $this->lines()[0]['recurring_invoice_id']);

        $this->billing(['contract_type_id' => '3', 'contract_subtype_id' => '99']);
        $this->fakeHalo([$this->contract(1, 'Link A')], []);
        $this->push($this->service(['vocus_service_id' => 'DD-9'])); // none
        $this->assertSame(777, $this->lines()[0]['recurring_invoice_id']);
    }

    public function test_a_matching_agreement_beats_the_client_pin(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'x')]);
        $this->client->forceFill(['halo_recurring_invoice_id' => 777])->save();
        $service = $this->service();

        $this->push($service);

        $this->assertSame(205, (int) $service->fresh()->halo_recurring_invoice_id);
    }

    public function test_resolver_reports_the_source(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'x')]);
        $service = $this->service()->fresh(['client', 'vocusPlan']);
        $cfg = HaloBillingSettings::fromSettings();
        $resolver = new HaloAgreementResolver(app(HaloPsaClient::class));

        $r = $resolver->resolve($service, $service->client, $cfg, HaloLineTemplate::variables($service));
        $this->assertSame(['invoice_id' => 205, 'contract_id' => 1, 'contract_ref' => 'Link A', 'source' => 'agreement'], $r);

        $service->halo_override_invoice_id = 9;
        $this->assertSame('service', $resolver->resolve($service, $service->client, $cfg, [])['source']);
    }

    // ------------------------------------------------------------ line text

    public function test_halo_variables_stay_untouched_and_placeholders_are_filled(): void
    {
        $service = $this->service()->fresh(['client', 'vocusPlan']);
        $vars = HaloLineTemplate::variables($service, null, 'EXA001');

        $out = HaloLineTemplate::render("{plan_name} - \$PERIODSTARTDATE - \$PERIODENDDATE\n{service_id}/{avc_id}/{loc_id}/{username}/{speed}/{technology}/{client_code}/{unknown}", $vars);

        $this->assertSame("Fibre1000 - \$PERIODSTARTDATE - \$PERIODENDDATE\nDD-1/AVC000011112222/LOC000012345678/user1@example.com.au/1000/50/FTTP/EXA001/{unknown}", $out);
        $this->assertSame('Fibre1000', HaloLineTemplate::render('{plan}', $vars));
        $this->assertSame('1 Test St, Sydney NSW 2000', HaloLineTemplate::render('{address}', $vars));
    }

    public function test_values_cannot_inject_control_characters_or_further_placeholders(): void
    {
        $service = $this->service(['username' => "bad\nname {avc_id}"])->fresh(['client', 'vocusPlan']);

        $out = HaloLineTemplate::render('{username}|{avc_id}', HaloLineTemplate::variables($service));

        $this->assertSame('bad name {avc_id}|AVC000011112222', $out);
    }

    public function test_default_templates_are_used_and_the_line_payload_uses_swagger_field_names(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'x')]);

        $this->push($this->service());

        $line = $this->lines()[0];
        $this->assertSame('Fibre1000 - $PERIODSTARTDATE - $PERIODENDDATE', $line['item_shortdescription']);
        $this->assertSame("Fibre1000 - Billing Period: \$PERIODSTARTDATE - \$PERIODENDDATE\nService ID: DD-1\nAVC: AVC000011112222", $line['item_longdescription']);
        $this->assertSame(9, $line['_itemid']);
        $this->assertSame(1, $line['qty_order']);
        $this->assertEquals(605.0, $line['unit_price']);
        $this->assertEquals(300.0, $line['unit_cost']);
        $this->assertSame(205, $line['recurring_invoice_id']);
    }

    public function test_custom_templates_are_sent_and_a_template_change_updates_the_line(): void
    {
        $this->billing(['contract_type_id' => '3', 'line_description' => 'IP-LINE {plan_name} - $PERIODSTARTDATE', 'line_long_description' => "NTU: {avc_id}\nUser: {username}"]);
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'x')]);
        $service = $this->service();

        $this->push($service);

        $line = $this->lines()[0];
        $this->assertSame('IP-LINE Fibre1000 - $PERIODSTARTDATE', $line['item_shortdescription']);
        $this->assertSame("NTU: AVC000011112222\nUser: user1@example.com.au", $line['item_longdescription']);

        $cfgBefore = HaloBillingSettings::fromSettings()->fingerprint();
        $this->billing(['contract_type_id' => '3', 'line_description' => 'Other']);
        $this->assertNotSame($cfgBefore, HaloBillingSettings::fromSettings()->fingerprint(), 'a template change re-pushes once');
    }

    public function test_empty_templates_fall_back_to_the_defaults(): void
    {
        $cfg = HaloBillingSettings::fromArray(['line_description' => '  ', 'line_long_description' => null]);

        $this->assertSame(HaloBillingSettings::DEFAULT_DESCRIPTION, $cfg->lineDescription);
        $this->assertSame(HaloBillingSettings::DEFAULT_LONG_DESCRIPTION, $cfg->lineLongDescription);
    }

    // ------------------------------------------------------------ preview and per-service picker routes

    public function test_preview_renders_text_and_agreement_without_writing(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'x')]);
        $service = $this->service();

        $json = $this->actingAs($this->admin())->postJson(route('admin.settings.halo.billing.preview'), [
            'service_id' => $service->id,
            'line_description' => 'IP-LINE {plan_name} - $PERIODSTARTDATE - $PERIODENDDATE',
            'line_long_description' => "NTU: {avc_id}",
        ])->assertOk()->json();

        $this->assertSame('IP-LINE Fibre1000 - $PERIODSTARTDATE - $PERIODENDDATE', $json['description']);
        $this->assertSame('NTU: AVC000011112222', $json['long_description']);
        $this->assertSame('Connectivity - Fibre1000', $json['item']);
        $this->assertEquals(605.0, $json['price']);
        $this->assertTrue($json['agreement']['ok']);
        $this->assertStringContainsString('#205', $json['agreement']['message']);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() !== 'GET' && ! str_contains($r->url(), '/auth/token'));
        $this->assertNull($service->fresh()->halo_recurring_line_id);
    }

    public function test_preview_reports_an_ambiguous_agreement_and_needs_administrator(): void
    {
        $this->billing(['contract_type_id' => '3']);
        $this->fakeHalo([$this->contract(1, 'Link A'), $this->contract(2, 'Link B')], [$this->invoice(205, 1, 'x'), $this->invoice(206, 2, 'y')]);
        $service = $this->service();

        $json = $this->actingAs($this->admin())->postJson(route('admin.settings.halo.billing.preview'), ['service_id' => $service->id])->assertOk()->json();
        $this->assertFalse($json['agreement']['ok']);
        $this->assertStringContainsString('Link A, Link B', $json['agreement']['message']);

        $plain = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($plain)->postJson(route('admin.settings.halo.billing.preview'), ['service_id' => $service->id])->assertForbidden();
    }

    public function test_service_override_can_only_be_one_of_the_clients_invoices_and_can_be_cleared(): void
    {
        $this->fakeHalo([$this->contract(1, 'Link A')], [$this->invoice(205, 1, 'Link A')]);
        $service = $this->service();
        $admin = $this->admin();

        $list = $this->actingAs($admin)->getJson(route('admin.services.internet.halo.recurringInvoices', $service))->assertOk()->json();
        $this->assertSame(205, $list['invoices'][0]['id']);
        $this->assertSame('Link A', $list['invoices'][0]['contract_ref']);

        $this->actingAs($admin)->putJson(route('admin.services.internet.halo.recurringInvoice', $service), ['recurring_invoice_id' => 999])->assertStatus(422);
        $this->assertNull($service->fresh()->halo_override_invoice_id);

        $this->actingAs($admin)->putJson(route('admin.services.internet.halo.recurringInvoice', $service), ['recurring_invoice_id' => 205])
            ->assertOk()->assertJson(['selected_id' => 205]);
        $this->assertSame(205, (int) $service->fresh()->halo_override_invoice_id);
        $this->assertStringContainsString('Link A', (string) $service->fresh()->halo_override_invoice_name);

        $this->actingAs($admin)->putJson(route('admin.services.internet.halo.recurringInvoice', $service), ['recurring_invoice_id' => null])->assertOk();
        $this->assertNull($service->fresh()->halo_override_invoice_id);
    }

    public function test_service_override_routes_need_the_sync_permission(): void
    {
        $service = $this->service();
        Role::findOrCreate('Administrator', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('Administrator');

        $this->actingAs($user)->putJson(route('admin.services.internet.halo.recurringInvoice', $service), ['recurring_invoice_id' => null])->assertForbidden();
    }

    public function test_billing_settings_save_the_new_keys_and_validate_them(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'halo' => ['billing' => [
                'contract_type_id' => '3', 'contract_subtype_id' => '7', 'subtype_lookup_id' => '35', 'type_lookup_id' => '36',
                'contract_ref' => 'Connectivity - {plan} - {client_code}', 'line_description' => 'D {plan_name}', 'line_long_description' => "L1\nL2",
            ]],
            '_return' => '/admin/settings/halopsa#billing',
        ])->assertRedirect('/admin/settings/halopsa#billing')->assertSessionHasNoErrors();

        $cfg = HaloBillingSettings::fromSettings();
        $this->assertSame(3, $cfg->contractTypeId);
        $this->assertSame(7, $cfg->contractSubtypeId);
        $this->assertSame(35, $cfg->subtypeLookupId);
        $this->assertSame(36, $cfg->typeLookupId);
        $this->assertSame(28, HaloBillingSettings::fromArray([])->typeLookupId);
        $this->assertSame(76, HaloBillingSettings::fromArray([])->subtypeLookupId);
        $this->assertSame('Connectivity - {plan} - {client_code}', $cfg->contractRef);
        $this->assertSame("L1\nL2", $cfg->lineLongDescription);

        $this->actingAs($admin)->post(route('admin.settings.update'), ['halo' => ['billing' => ['contract_subtype_id' => '0']]])->assertSessionHasErrors('halo.billing.contract_subtype_id');
    }

    public function test_billing_tab_has_the_three_sections_and_the_home_has_no_second_billing_entry(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/settings/halopsa?tab=billing')->assertOk()
            ->assertSee('Agreements')->assertSee('Recurring invoice lines')->assertSee('Subscriptions')
            ->assertSee('$PERIODSTARTDATE', false);

        $home = $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
        $home->assertDontSee('Halo billing');
        $this->actingAs($admin)->get('/admin/settings/halo-billing')->assertRedirect('/admin/settings/halopsa?tab=billing');
    }
}
