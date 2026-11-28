<?php

namespace Tests\Feature\Microsoft;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Domain;
use App\Models\PartnerCustomerTenant;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Microsoft\Partner\PartnerGraphClient;
use App\Services\Microsoft\Partner\PartnerGraphException;
use App\Services\Microsoft\Partner\PartnerSettings;
use App\Services\Microsoft\Partner\PartnerTenantSuggester;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Settings > Microsoft Partner: Graph client, sync, suggestions, mapping and access control (all Http::fake). */
class MicrosoftPartnerTest extends MicrosoftTestCase
{
    private const PARTNER = 'dddddddd-1111-2222-3333-444444444444';
    private const PAPP = 'eeeeeeee-1111-2222-3333-444444444444';
    private const PSECRET = 'partner-secret-VALUE-xyz';
    private const T1 = '11111111-0000-4000-8000-000000000001';
    private const T2 = '22222222-0000-4000-8000-000000000002';
    private const T3 = '33333333-0000-4000-8000-000000000003';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->configurePartner();
    }

    private function configurePartner(array $over = []): void
    {
        Setting::put(PartnerSettings::KEY, $over + [
            'partner_tenant_id' => self::PARTNER, 'client_id' => self::PAPP, 'client_secret' => self::PSECRET,
            'source' => PartnerSettings::SOURCE_DELEGATED,
        ]);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $u->assignRole('Administrator');

        return $u;
    }

    private function token(): array
    {
        return ['access_token' => 'graph-token-abc', 'expires_in' => 3600, 'token_type' => 'Bearer'];
    }

    private function fakeGraph(array $routes): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response($this->token())] + $routes);
    }

    private function tenant(string $id, string $name, ?string $domain = null): PartnerCustomerTenant
    {
        return PartnerCustomerTenant::create(['tenant_id' => $id, 'display_name' => $name, 'default_domain' => $domain, 'relationship_status' => 'active', 'last_seen_at' => now()]);
    }

    // ---- Graph client: token, pagination, hosts ------------------------------------------------

    public function test_token_is_requested_with_client_credentials_for_the_partner_tenant_and_pagination_is_followed(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/tenantRelationships/delegatedAdminCustomers?$skiptoken=abc' => Http::response(['value' => [['id' => self::T2, 'displayName' => 'Beta']]]),
            'graph.microsoft.com/v1.0/tenantRelationships/delegatedAdminCustomers?*' => Http::response([
                'value' => [['id' => strtoupper(self::T1), 'displayName' => 'Alpha Pty Ltd']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/tenantRelationships/delegatedAdminCustomers?$skiptoken=abc',
            ]),
            'login.microsoftonline.com/*' => Http::response($this->token()),
        ]);

        $rows = app(PartnerGraphClient::class)->listCustomers();

        $this->assertSame([self::T1, self::T2], array_column($rows, 'tenant_id'));
        Http::assertSent(function (HttpRequest $r) {
            if (! str_contains($r->url(), 'login.microsoftonline.com')) {
                return false;
            }
            $b = $r->data();

            return $r->url() === 'https://login.microsoftonline.com/'.self::PARTNER.'/oauth2/v2.0/token'
                && $b['grant_type'] === 'client_credentials' && $b['scope'] === 'https://graph.microsoft.com/.default'
                && $b['client_id'] === self::PAPP && $b['client_secret'] === self::PSECRET;
        });
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.microsoft.com') && $r->hasHeader('Authorization', 'Bearer graph-token-abc'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/contracts'));
    }

    public function test_contracts_source_reads_domains_and_both_merges_by_tenant_id(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/v1.0/contracts*' => Http::response(['value' => [['customerId' => self::T1, 'displayName' => 'Alpha', 'defaultDomainName' => 'Alpha.com.au']]]),
            'graph.microsoft.com/v1.0/tenantRelationships/*' => Http::response(['value' => [['id' => self::T1, 'displayName' => 'Alpha'], ['id' => self::T2, 'displayName' => 'Beta']]]),
        ]);

        $contracts = app(PartnerGraphClient::class)->listCustomers(PartnerSettings::SOURCE_CONTRACTS);
        $this->assertSame('alpha.com.au', $contracts[0]['default_domain']);

        $both = collect(app(PartnerGraphClient::class)->listCustomers(PartnerSettings::SOURCE_BOTH))->keyBy('tenant_id');
        $this->assertCount(2, $both);
        $this->assertSame('alpha.com.au', $both[self::T1]['default_domain']);
        $this->assertNull($both[self::T2]['default_domain']);
    }

    public function test_next_link_to_another_host_is_refused_and_never_requested(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/*' => Http::response(['value' => [['id' => self::T1, 'displayName' => 'A']], '@odata.nextLink' => 'https://evil.example.com/steal?x=1']),
            'evil.example.com/*' => Http::response(['value' => []]),
        ]);

        try {
            app(PartnerGraphClient::class)->listCustomers();
            $this->fail('expected an exception');
        } catch (PartnerGraphException $e) {
            $this->assertSame('unsafe_next_link', $e->reason);
        }
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example.com'));
    }

    public function test_graph_url_check_rejects_lookalikes_http_ports_and_credentials(): void
    {
        $this->assertTrue(PartnerGraphClient::isGraphUrl('https://graph.microsoft.com/v1.0/contracts?$skiptoken=1'));
        foreach ([
            'http://graph.microsoft.com/v1.0/contracts',
            'https://graph.microsoft.com.evil.com/v1.0/contracts',
            'https://evilgraph.microsoft.com/v1.0',
            'https://graph.microsoft.com@evil.com/x',
            'https://user:pw@graph.microsoft.com/x',
            'https://graph.microsoft.com:8443/x',
            'https://login.microsoftonline.com/x',
            '//graph.microsoft.com/x',
        ] as $bad) {
            $this->assertFalse(PartnerGraphClient::isGraphUrl($bad), $bad);
        }
    }

    public function test_invalid_guid_ids_are_rejected_before_any_request_and_bad_rows_are_skipped(): void
    {
        Http::fake();
        $this->configurePartner(['partner_tenant_id' => 'not-a-guid/../evil']);
        try {
            app(PartnerGraphClient::class)->listCustomers();
            $this->fail('expected an exception');
        } catch (PartnerGraphException $e) {
            $this->assertSame('invalid_tenant_id', $e->reason);
        }
        Http::assertNothingSent();

        $this->configurePartner(['client_id' => 'nope']);
        $this->expectException(PartnerGraphException::class);
        app(PartnerGraphClient::class)->listCustomers();
    }

    public function test_rows_with_non_guid_tenant_ids_are_skipped(): void
    {
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['value' => [['id' => 'bad-id', 'displayName' => 'X'], ['id' => self::T1, 'displayName' => 'Good']]])]);

        $this->assertSame([self::T1], array_column(app(PartnerGraphClient::class)->listCustomers(), 'tenant_id'));
    }

    public function test_token_is_cached_until_expiry_and_a_rotated_secret_gets_a_new_one(): void
    {
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['value' => []])]);
        $graph = app(PartnerGraphClient::class);

        $graph->listCustomers();
        $graph->listCustomers();
        $tokenCalls = fn () => Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'login.microsoftonline.com'))->count();
        $this->assertSame(1, $tokenCalls());

        $this->configurePartner(['client_secret' => 'rotated']);
        $graph->listCustomers();
        $this->assertSame(2, $tokenCalls());

        // Never stored in clear text.
        $this->assertStringNotContainsString('graph-token-abc', json_encode(Cache::get('ms-partner-token:'.hash('sha256', self::PARTNER.'|'.self::PAPP.'|rotated'))));
    }

    public function test_graph_and_token_errors_become_safe_messages_without_the_secret(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided. Trace ID: x '.self::PSECRET], 401),
        ]);
        try {
            app(PartnerGraphClient::class)->test();
            $this->fail('expected an exception');
        } catch (PartnerGraphException $e) {
            $this->assertSame('token_failed', $e->reason);
            $this->assertStringContainsString('AADSTS7000215', $e->getMessage());
            $this->assertStringNotContainsString(self::PSECRET, $e->getMessage());
        }

        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges ' . self::PSECRET]], 403)]);
        try {
            app(PartnerGraphClient::class)->listCustomers();
            $this->fail('expected an exception');
        } catch (PartnerGraphException $e) {
            $this->assertSame('forbidden', $e->reason);
            $this->assertStringContainsString('Authorization_RequestDenied', $e->getMessage());
            $this->assertStringNotContainsString(self::PSECRET, $e->getMessage());
        }
    }

    public function test_redirects_from_graph_are_not_followed(): void
    {
        $this->fakeGraph([
            'graph.microsoft.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.com/x']),
            'evil.example.com/*' => Http::response(['value' => []]),
        ]);

        try {
            app(PartnerGraphClient::class)->listCustomers();
            $this->fail('expected an exception');
        } catch (PartnerGraphException $e) {
            $this->assertSame('redirect', $e->reason);
        }
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example.com'));
    }

    // ---- Settings: secret handling, admin only -----------------------------------------------

    public function test_secret_is_encrypted_at_rest_masked_in_the_form_and_kept_when_blank(): void
    {
        $admin = $this->admin();
        Setting::query()->delete();

        $this->actingAs($admin)->post(route('admin.partner.update'), [
            'partner_tenant_id' => strtoupper(self::PARTNER), 'client_id' => self::PAPP, 'client_secret' => self::PSECRET, 'source' => PartnerSettings::SOURCE_CONTRACTS,
        ])->assertRedirect();

        $this->assertStringNotContainsString(self::PSECRET, (string) DB::table('settings')->where('key', PartnerSettings::KEY)->value('value'));
        $this->assertSame(self::PSECRET, PartnerSettings::clientSecret());
        $this->assertSame(self::PARTNER, PartnerSettings::partnerTenantId());

        $page = $this->actingAs($admin)->get('/admin/settings/microsoft-partner?tab=connection');
        $page->assertOk()->assertDontSee(self::PSECRET, false)->assertSee('********', false);

        foreach (['', '********'] as $kept) {
            $this->actingAs($admin)->post(route('admin.partner.update'), [
                'partner_tenant_id' => self::PARTNER, 'client_id' => self::PAPP, 'client_secret' => $kept, 'source' => PartnerSettings::SOURCE_DELEGATED,
            ])->assertRedirect();
            $this->assertSame(self::PSECRET, PartnerSettings::clientSecret());
        }

        $this->actingAs($admin)->post(route('admin.partner.update'), [
            'partner_tenant_id' => self::PARTNER, 'client_id' => self::PAPP, 'client_secret' => 'new-secret-value', 'source' => PartnerSettings::SOURCE_DELEGATED,
        ]);
        $this->assertSame('new-secret-value', PartnerSettings::clientSecret());

        $audit = json_encode(AuditLog::where('action', 'partner.settings.updated')->get()->toArray());
        $this->assertStringNotContainsString(self::PSECRET, $audit);
        $this->assertStringNotContainsString('new-secret-value', $audit);
    }

    public function test_settings_validation_requires_guids_and_a_known_source(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.partner.update'), ['partner_tenant_id' => 'x', 'client_id' => 'y', 'source' => 'bogus'])
            ->assertSessionHasErrors(['partner_tenant_id', 'client_id', 'source']);
        $this->assertSame(self::PARTNER, PartnerSettings::partnerTenantId());
    }

    public function test_only_administrators_can_use_the_partner_screens(): void
    {
        $tenant = $this->tenant(self::T1, 'Alpha');
        $client = Client::create(['business_name' => 'Alpha']);
        $tech = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
        $tech->assignRole('Technician');

        $calls = [
            ['get', '/admin/settings/microsoft-partner'],
            ['post', route('admin.partner.update'), []],
            ['post', route('admin.partner.test')],
            ['post', route('admin.partner.sync')],
            ['post', route('admin.partner.map', $tenant), ['client' => $client->id]],
            ['delete', route('admin.partner.clear', $tenant)],
        ];
        foreach ($calls as $c) {
            $this->actingAs($tech)->{$c[0]}($c[1], $c[2] ?? [])->assertForbidden();
        }
        $this->assertNull($client->fresh()->microsoft_tenant_id);

        auth()->logout();
        foreach ($calls as $c) {
            $this->{$c[0]}($c[1], $c[2] ?? [])->assertRedirect();
        }
        Http::assertNothingSent();
    }

    public function test_test_connection_reports_success_and_failure(): void
    {
        $admin = $this->admin();
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['value' => []])]);
        $this->actingAs($admin)->post(route('admin.partner.test'))->assertRedirect()->assertSessionHas('success');

        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)]);
        $this->actingAs($admin)->post(route('admin.partner.test'))->assertRedirect()
            ->assertSessionHas('partner_error', fn ($m) => str_contains($m, 'Authorization_RequestDenied') && ! str_contains($m, self::PSECRET));
        $this->assertTrue(AuditLog::where('action', 'partner.sync.test_failed')->exists());
    }

    // ---- Sync --------------------------------------------------------------------------------

    public function test_sync_now_mirrors_tenants_keeps_domains_flags_missing_ones_and_audits(): void
    {
        $admin = $this->admin();
        $this->configurePartner(['source' => PartnerSettings::SOURCE_BOTH]);
        $gone = $this->tenant(self::T3, 'Gone Customer');
        $this->fakeGraph([
            'graph.microsoft.com/v1.0/contracts*' => Http::response(['value' => [['customerId' => self::T1, 'displayName' => 'Alpha', 'defaultDomainName' => 'alpha.com.au']]]),
            'graph.microsoft.com/v1.0/tenantRelationships/*' => Http::response(['value' => [['id' => self::T1, 'displayName' => 'Alpha'], ['id' => self::T2, 'displayName' => 'Beta']]]),
        ]);

        $this->actingAs($admin)->post(route('admin.partner.sync'))->assertRedirect()->assertSessionHas('success');

        $alpha = PartnerCustomerTenant::where('tenant_id', self::T1)->first();
        $this->assertSame('alpha.com.au', $alpha->default_domain);
        $this->assertSame(['alpha.com.au'], $alpha->domains);
        $this->assertSame('active', $alpha->relationship_status);
        $this->assertNotNull(PartnerCustomerTenant::where('tenant_id', self::T2)->first());
        $this->assertSame('not_seen', $gone->fresh()->relationship_status);

        $run = SyncRun::where('provider', 'microsoft_partner')->latest('id')->first();
        $this->assertSame(SyncRun::OK, $run->status);
        $this->assertSame(2, (int) $run->total);
        foreach (['partner.sync.started', 'partner.sync.completed'] as $a) {
            $this->assertTrue(AuditLog::where('action', $a)->exists(), $a);
        }

        // A later domain-less (delegated only) sync does not erase the learned domain.
        $this->configurePartner();
        $this->actingAs($admin)->post(route('admin.partner.sync'))->assertRedirect();
        $this->assertSame('alpha.com.au', $alpha->fresh()->default_domain);
    }

    public function test_failed_sync_records_the_run_and_audits_without_the_secret(): void
    {
        $admin = $this->admin();
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)]);

        $this->actingAs($admin)->post(route('admin.partner.sync'))->assertRedirect();

        $run = SyncRun::where('provider', 'microsoft_partner')->latest('id')->first();
        $this->assertSame(SyncRun::FAILED, $run->status);
        $this->assertStringContainsString('HTTP 403', $run->error);
        $this->assertTrue(AuditLog::where('action', 'partner.sync.failed')->exists());
        $this->assertStringNotContainsString(self::PSECRET, json_encode(AuditLog::all()->toArray()));
    }

    public function test_sync_is_skipped_when_not_configured(): void
    {
        $admin = $this->admin();
        Setting::query()->where('key', PartnerSettings::KEY)->delete();
        Http::fake();

        $this->actingAs($admin)->post(route('admin.partner.sync'))->assertRedirect()->assertSessionHas('partner_error');
        Http::assertNothingSent();
    }

    public function test_scheduled_task_command_queues_a_run_and_the_schedule_registers_when_enabled(): void
    {
        $this->fakeGraph(['graph.microsoft.com/*' => Http::response(['value' => [['id' => self::T1, 'displayName' => 'Alpha']]])]);

        $this->artisan('domaindash:sync-task', ['task' => 'sync-microsoft-partner'])->assertSuccessful();
        $this->assertSame(1, PartnerCustomerTenant::count());

        Setting::put('sync_schedule', ['sync_microsoft_partner' => ['enabled' => true, 'frequency' => 'daily', 'time' => '04:10']]);
        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        \App\Console\ScheduleRegistrar::register($schedule);
        $names = collect($schedule->events())->map(fn ($e) => $e->description)->all();
        $this->assertContains('Command: domaindash:sync-task sync-microsoft-partner', $names);
    }

    // ---- Suggestions -------------------------------------------------------------------------

    public function test_suggestion_by_domain_match_against_managed_domains_and_emails(): void
    {
        $byDomain = Client::create(['business_name' => 'Totally Different Name']);
        Domain::create(['client_id' => $byDomain->id, 'name' => 'alpha.com.au']);
        $byEmail = Client::create(['business_name' => 'Zed Holdings', 'email' => 'accounts@zedco.net']);
        $byUserEmail = Client::create(['business_name' => 'Yankee']);
        $u = User::factory()->create(['email' => 'amy@yankee-co.com']);
        $byUserEmail->users()->attach($u->id);

        $s = new PartnerTenantSuggester();
        $a = $s->suggest($this->tenant(self::T1, 'Alpha', 'alpha.com.au'));
        $this->assertSame($byDomain->id, $a['client']->id);
        $this->assertSame(1.0, $a['score']);
        $this->assertStringContainsString('Domain match', $a['reason']);

        $this->assertSame($byEmail->id, $s->suggest($this->tenant(self::T2, 'Q', 'zedco.net'))['client']->id);
        $this->assertSame($byUserEmail->id, $s->suggest($this->tenant('44444444-0000-4000-8000-000000000004', 'R', 'yankee-co.com'))['client']->id);
    }

    public function test_suggestion_by_name_similarity_ignores_noise_free_mail_and_low_scores(): void
    {
        $acme = Client::create(['business_name' => 'Acme Plumbing Pty Ltd']);
        Client::create(['business_name' => 'Gmail Fan', 'email' => 'x@gmail.com']);
        $s = new PartnerTenantSuggester();

        $hit = $s->suggest($this->tenant(self::T1, 'ACME Plumbing', 'acmeplumbing.onmicrosoft.com'));
        $this->assertSame($acme->id, $hit['client']->id);
        $this->assertStringContainsString('Name similarity', $hit['reason']);

        // onmicrosoft prefix is used when the display name is useless.
        $this->assertSame($acme->id, $s->suggest($this->tenant(self::T2, 'Tenant', 'acme-plumbing.onmicrosoft.com'))['client']->id);

        $this->assertNull($s->suggest($this->tenant(self::T3, 'Wholly Unrelated Bakery', 'bakery.onmicrosoft.com')));
        // gmail.com is a free provider, never a domain match
        $this->assertNull($s->suggest($this->tenant('55555555-0000-4000-8000-000000000005', 'Nothing', 'gmail.com')));

        $this->assertGreaterThan(0.9, PartnerTenantSuggester::nameSimilarity('Smith & Sons Pty. Ltd.', 'smith and sons'));
        $this->assertSame('', PartnerTenantSuggester::normaliseName('  '));
    }

    public function test_clients_already_mapped_are_not_suggested(): void
    {
        Client::create(['business_name' => 'Acme Plumbing', 'microsoft_tenant_id' => self::T2]);

        $this->assertNull((new PartnerTenantSuggester())->suggest($this->tenant(self::T1, 'Acme Plumbing')));
    }

    // ---- Mapping -----------------------------------------------------------------------------

    public function test_confirming_a_mapping_sets_the_tenant_revokes_sessions_and_audits(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $client = Client::create(['business_name' => 'Alpha']);
        $user = $this->customer('amy@alpha.com', $client);
        $bystander = $this->customer('bob@other.com');
        DB::table('sessions')->insert([
            ['id' => 'a1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'b1', 'user_id' => $bystander->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $tenant = $this->tenant(self::T1, 'Alpha Pty');

        $this->actingAs($admin)->post(route('admin.partner.map', $tenant), ['client' => "Alpha (#{$client->id})"])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(self::T1, $client->fresh()->microsoft_tenant_id);
        $this->assertSame($client->id, $tenant->fresh()->client->id);
        $this->assertNull(DB::table('sessions')->where('id', 'a1')->first());
        $this->assertNotNull(DB::table('sessions')->where('id', 'b1')->first());
        $this->assertTrue(AuditLog::where('action', 'client.sso_settings.changed')->exists());
        $log = AuditLog::where('action', 'partner.mapping.confirmed')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, (int) $log->new_values['sessions_revoked']);

        // Re-confirming the same mapping changes nothing and revokes nothing further.
        DB::table('sessions')->insert(['id' => 'a2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->post(route('admin.partner.map', $tenant), ['client' => $client->id])->assertRedirect();
        $this->assertNotNull(DB::table('sessions')->where('id', 'a2')->first());
        $this->assertSame(1, AuditLog::where('action', 'partner.mapping.confirmed')->count());
    }

    public function test_one_tenant_maps_to_one_client_reassignment_clears_the_previous_client(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $a = Client::create(['business_name' => 'Client A']);
        $b = Client::create(['business_name' => 'Client B']);
        $ua = $this->customer('a@a.com', $a);
        DB::table('sessions')->insert(['id' => 'sa', 'user_id' => $ua->id, 'payload' => '', 'last_activity' => time()]);
        $tenant = $this->tenant(self::T1, 'Shared');

        $this->actingAs($admin)->post(route('admin.partner.map', $tenant), ['client' => $a->id]);
        $this->actingAs($admin)->post(route('admin.partner.map', $tenant), ['client' => $b->id])->assertRedirect();

        $this->assertNull($a->fresh()->microsoft_tenant_id);
        $this->assertSame(self::T1, $b->fresh()->microsoft_tenant_id);
        $this->assertSame(1, Client::where('microsoft_tenant_id', self::T1)->count());
        $this->assertNull(DB::table('sessions')->where('id', 'sa')->first());
        $this->assertTrue(AuditLog::where('action', 'partner.mapping.cleared')->exists());
    }

    public function test_changing_a_clients_tenant_replaces_its_old_one(): void
    {
        $admin = $this->admin();
        $c = Client::create(['business_name' => 'Solo', 'microsoft_tenant_id' => self::T1]);
        $t2 = $this->tenant(self::T2, 'Second');

        $this->actingAs($admin)->post(route('admin.partner.map', $t2), ['client' => $c->id])->assertRedirect();

        $this->assertSame(self::T2, $c->fresh()->microsoft_tenant_id);
        $log = AuditLog::where('action', 'partner.mapping.confirmed')->first();
        $this->assertSame(self::T1, $log->new_values['old_microsoft_tenant_id']);
    }

    public function test_clearing_a_mapping_revokes_sessions_and_audits(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->admin();
        $client = Client::create(['business_name' => 'Alpha', 'microsoft_tenant_id' => self::T1]);
        $user = $this->customer('amy@alpha.com', $client);
        DB::table('sessions')->insert(['id' => 'a1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $tenant = $this->tenant(self::T1, 'Alpha');

        $this->actingAs($admin)->delete(route('admin.partner.clear', $tenant))->assertRedirect();

        $this->assertNull($client->fresh()->microsoft_tenant_id);
        $this->assertNull(DB::table('sessions')->where('id', 'a1')->first());
        $this->assertTrue(AuditLog::where('action', 'partner.mapping.cleared')->exists());
        // Clearing again is a harmless no-op.
        $this->actingAs($admin)->delete(route('admin.partner.clear', $tenant))->assertRedirect();
        $this->assertSame(1, AuditLog::where('action', 'partner.mapping.cleared')->count());
    }

    public function test_mapping_requires_a_real_client(): void
    {
        $admin = $this->admin();
        $tenant = $this->tenant(self::T1, 'Alpha');

        $this->actingAs($admin)->post(route('admin.partner.map', $tenant), ['client' => 'Nobody (#9999)'])->assertSessionHas('partner_error');
        $this->actingAs($admin)->post(route('admin.partner.map', $tenant), [])->assertSessionHasErrors('client');
        $this->assertSame(0, Client::whereNotNull('microsoft_tenant_id')->count());
    }

    public function test_client_form_cannot_reuse_a_tenant_mapped_to_another_client(): void
    {
        $admin = $this->admin();
        Client::create(['business_name' => 'Owner', 'microsoft_tenant_id' => self::T1]);
        $other = Client::create(['business_name' => 'Other']);
        $base = ['business_name' => 'Other', 'active' => 1, 'login_method' => 'inherit'];

        $this->actingAs($admin)->put(route('admin.clients.update', $other), ['microsoft_tenant_id' => strtoupper(self::T1)] + $base)
            ->assertSessionHasErrors('microsoft_tenant_id');
        $this->assertNull($other->fresh()->microsoft_tenant_id);

        // Saving the owner's own form with its own tenant stays valid.
        $owner = Client::where('business_name', 'Owner')->first();
        $this->actingAs($admin)->put(route('admin.clients.update', $owner), ['business_name' => 'Owner', 'active' => 1, 'login_method' => 'inherit', 'microsoft_tenant_id' => self::T1])
            ->assertSessionHasNoErrors();
    }

    // ---- UI ----------------------------------------------------------------------------------

    public function test_customers_tab_lists_tenants_with_suggestions_and_client_page_shows_the_mapped_tenant(): void
    {
        $admin = $this->admin();
        $acme = Client::create(['business_name' => 'Acme Plumbing']);
        $mapped = Client::create(['business_name' => 'Mapped Co', 'microsoft_tenant_id' => self::T2]);
        $this->tenant(self::T1, 'Acme Plumbing Pty Ltd', 'acme.onmicrosoft.com');
        $this->tenant(self::T2, 'Mapped Company', 'mapped.com');

        $page = $this->actingAs($admin)->get('/admin/settings/microsoft-partner?tab=customers');
        $page->assertOk()->assertSee('Acme Plumbing Pty Ltd')->assertSee('Suggested')->assertSee('Confirm suggestion')
            ->assertSee('Mapped Co')->assertSee('Clear');

        $this->actingAs($admin)->get('/admin/settings/microsoft-partner?tab=customers&filter=unmapped')->assertOk()
            ->assertSee('Acme Plumbing Pty Ltd')->assertDontSee('Mapped Company');

        $this->actingAs($admin)->get(route('admin.clients.edit', $mapped))->assertOk()
            ->assertSee('Microsoft partner customer')->assertSee('Mapped Company');
        $this->actingAs($admin)->get(route('admin.clients.edit', $acme))->assertOk()->assertDontSee('Microsoft partner customer');

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Microsoft Partner');
    }
}
