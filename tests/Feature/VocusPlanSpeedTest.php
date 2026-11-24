<?php

namespace Tests\Feature;

require_once __DIR__ . '/../Support/soap_polyfill.php';

use App\Models\InternetService;
use App\Models\Setting;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Vocus\VocusLineSelection;
use App\Services\Vocus\VocusQualification as Q;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeSoap;
use Tests\TestCase;

/**
 * Plan speeds versus line capability (FTTN / FTTB / FTTC copper limits). Not runnable in the build environment
 * (no vendor/, no ext-soap); run on the server:
 *   php artisan test --filter=VocusPlanSpeedTest
 */
class VocusPlanSpeedTest extends TestCase
{
    use RefreshDatabase;

    private const LOC = 'LOC001122334455';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        Setting::put('vocus', ['access_key' => 'AK-SECRET-1', 'cert_path' => 'vocus/client.p12', 'environment' => 'sandbox', 'realms' => ['wba.example.com.au']]);
    }

    // ---------------------------------------------------------------- helpers

    private function admin(): User
    {
        Role::findOrCreate('Administrator', 'web');
        $permission = Permission::findOrCreate('sync.run', 'web');
        $user = User::factory()->create(['two_factor_secret' => encrypt('SECRET'), 'two_factor_confirmed_at' => now()]);
        $user->assignRole('Administrator');
        $user->givePermissionTo($permission);

        return $user;
    }

    private function fakeClient(array $queue): FakeSoap
    {
        $soap = new FakeSoap($queue);
        $client = new VocusWsmClient(fn () => $soap, null, fn () => 'SID');
        $client->setRateLimiter(new VocusRateLimiter(1000, 10, 15, fn () => null, fn () => 1000.0, 'spd_rate_' . uniqid()));
        app()->instance(VocusWsmClient::class, $client);

        return $soap;
    }

    private function ok(): object
    {
        return (object) ['TransactionID' => 'EF99182AC', 'ResponseType' => 'ASYNC'];
    }

    private function pairXml(string $id, string $down, string $up, string $extra = ''): string
    {
        return "<![CDATA[<CopperPairRecord><CopperPairID>$id</CopperPairID><CopperPairStatus>Inactive</CopperPairStatus>"
            . "<ServiceClass>12</ServiceClass><DownloadSpeed>$down</DownloadSpeed><UploadSpeed>$up</UploadSpeed>$extra</CopperPairRecord>]]>";
    }

    /** FTTN qualification: CPI-SLOW 25-50/5-20, CPI-FAST 50-100/20-40. */
    private function copperQualification(string $type = 'FTTN'): array
    {
        return Q::fromParams([
            'Result' => 'PASS', 'ServiceType' => $type,
            'CopperPairRecord' => [$this->pairXml('CPI-SLOW', '25-50', '5-20'), $this->pairXml('CPI-FAST', '50-100', '20-40')],
        ]);
    }

    private function ntdQualification(bool $withSpeeds = true): array
    {
        $speeds = $withSpeeds ? '<DownloadSpeed>1000</DownloadSpeed><UploadSpeed>400</UploadSpeed>' : '';

        return Q::fromParams([
            'Result' => 'PASS', 'ServiceType' => 'FTTP',
            'NTDRecord' => "<![CDATA[<NTDRecord><NTDID>NTD1</NTDID><NTDVersion>2</NTDVersion>$speeds</NTDRecord>]]>",
            'NBNPortRecord' => "<![CDATA[<NBNPortRecord><NTDID>NTD1</NTDID><PortNumber>1</PortNumber><PortName>UNI-D 1</PortName><Available>TRUE</Available><PortType>DATA</PortType></NBNPortRecord>]]>",
        ]);
    }

    private function cacheQualification(array $q): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put('vocus:qualification:' . $token, ['directory_id' => self::LOC, 'qualification' => $q], now()->addHour());

        return $token;
    }

    private function plan(string $id, ?int $down, ?int $up, array $o = []): VocusPlan
    {
        return VocusPlan::create($o + [
            'plan_id' => $id, 'name' => "Plan $id", 'speed_tier' => null, 'download_mbps' => $down, 'upload_mbps' => $up,
            'cost_price' => '50.00', 'sell_price' => '79.95', 'active' => true, 'scopes' => null,
        ]);
    }

    private function orderData(VocusPlan $plan, string $token, array $o = []): array
    {
        return array_merge([
            'vocus_plan_id' => $plan->id, 'scope' => 'RESELLER-CONNECT', 'customer_name' => 'Yogesh Shinde', 'phone' => '0433111222',
            'directory_id' => self::LOC, 'location_reference' => '3000', 'service_type' => 'FTTN', 'qualification_token' => $token,
            'realm' => 'wba.example.com.au', 'username' => 'yshinde', 'password' => 'Abc-123.x', 'service_level' => 'STANDARD',
            // FTTN NEW orders: VoicebandContinuity and CentralSplitter are compulsory (FIBRE 4.1.1.2).
            'voiceband_continuity' => 'FALSE', 'central_splitter' => 'FALSE',
        ], $o);
    }

    // ---------------------------------------------------------------- parsing

    public function test_mbps_parsing_ranges_single_values_suffix_and_junk(): void
    {
        $this->assertSame(100, Q::parseMbps('50-100'));
        $this->assertSame(100, Q::parseMbps('50 - 100'));
        $this->assertSame(40, Q::parseMbps('40'));
        $this->assertSame(100, Q::parseMbps('100 Mbps'));
        $this->assertSame(100, Q::parseMbps('50-100 Mbps'));
        $this->assertSame(100, Q::parseMbps('25,50,100'));
        $this->assertSame(100, Q::parseMbps(100));
        foreach (['', null, 'N/A', 'abc', 'fast', '0', '-', []] as $junk) {
            $this->assertNull(Q::parseMbps($junk), 'junk: ' . json_encode($junk));
        }
    }

    public function test_speed_tier_parsing_used_to_fill_and_backfill(): void
    {
        $this->assertSame([100, 20], VocusPlan::parseSpeedTier('100/20'));
        $this->assertSame([100, 20], VocusPlan::parseSpeedTier('100/20 Mbps'));
        $this->assertSame([100, 20], VocusPlan::parseSpeedTier(' 100 / 20 mbps '));
        $this->assertSame([1000, 50], VocusPlan::parseSpeedTier('1000/50'));
        foreach (['Gigabit', '100', 'up to 100/20 Mbps', '0/0', '99999/20', '', null] as $bad) {
            $this->assertNull(VocusPlan::parseSpeedTier($bad), 'bad: ' . json_encode($bad));
        }
    }

    public function test_migration_backfills_existing_plans_from_speed_tier(): void
    {
        $migration = require base_path('database/migrations/2026_11_26_000002_add_speeds_to_vocus_plans_table.php');
        $migration->down();
        foreach ([['A1', '100/20 Mbps'], ['A2', '1000/50'], ['A3', 'Gigabit'], ['A4', null]] as [$id, $tier]) {
            DB::table('vocus_plans')->insert(['plan_id' => $id, 'name' => $id, 'speed_tier' => $tier, 'cost_price' => 1, 'sell_price' => 2, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration->up();

        $row = fn (string $id) => DB::table('vocus_plans')->where('plan_id', $id)->first();
        $this->assertSame([100, 20], [(int) $row('A1')->download_mbps, (int) $row('A1')->upload_mbps]);
        $this->assertSame([1000, 50], [(int) $row('A2')->download_mbps, (int) $row('A2')->upload_mbps]);
        $this->assertNull($row('A3')->download_mbps);
        $this->assertNull($row('A4')->upload_mbps);
    }

    // ---------------------------------------------------------------- settings CRUD

    public function test_plan_speeds_are_validated_and_filled_from_speed_tier_when_blank(): void
    {
        $admin = $this->admin();
        $body = ['plan_id' => 'SPD-1', 'name' => 'Speedy', 'cost_price' => '10.00', 'sell_price' => '20.00', 'active' => true];

        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), $body + ['speed_tier' => '100/20 Mbps'])
            ->assertCreated()->assertJsonPath('plan.download_mbps', 100)->assertJsonPath('plan.upload_mbps', 20);

        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => 'SPD-2', 'speed_tier' => '100/20', 'download_mbps' => 250]))
            ->assertCreated()->assertJsonPath('plan.download_mbps', 250)->assertJsonPath('plan.upload_mbps', 20);

        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => 'SPD-3', 'speed_tier' => 'Gigabit']))
            ->assertCreated()->assertJsonPath('plan.download_mbps', null)->assertJsonPath('plan.upload_mbps', null);

        foreach ([['download_mbps' => 0], ['download_mbps' => 10001], ['upload_mbps' => -5], ['upload_mbps' => 'fast'], ['download_mbps' => 1.5]] as $i => $bad) {
            $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), array_merge($body, ['plan_id' => "BAD-$i"], $bad))
                ->assertStatus(422)->assertJsonValidationErrors(array_key_first($bad));
        }

        $this->assertSame(['SPD-1' => 100, 'SPD-2' => 250, 'SPD-3' => null], VocusPlan::whereIn('plan_id', ['SPD-1', 'SPD-2', 'SPD-3'])->pluck('download_mbps', 'plan_id')->all());
    }

    // ---------------------------------------------------------------- capability

    public function test_copper_capability_per_pair_and_new_line_fallback(): void
    {
        $q = $this->copperQualification();

        $this->assertSame(100, $q['copper_pairs'][1]['max_download_mbps']);
        $this->assertSame(40, $q['copper_pairs'][1]['max_upload_mbps']);

        $this->assertSame(['download' => 50, 'upload' => 20], Q::lineCapability($q, false, 'CPI-SLOW'));
        $this->assertSame(['download' => 100, 'upload' => 40], Q::lineCapability($q, false, 'CPI-FAST'));
        // New line and nothing chosen: best across pairs; an unknown pair id: unknown.
        $this->assertSame(['download' => 100, 'upload' => 40], Q::lineCapability($q, true));
        $this->assertSame(['download' => 100, 'upload' => 40], Q::lineCapability($q, false));
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability($q, false, 'CPI-NOPE'));
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability(Q::fromParams(['ServiceType' => 'FTTC']), true));
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability(null, true));

        $viaSelection = VocusLineSelection::capability($q, ['line_choice' => 'existing', 'copper_pair_id' => 'CPI-SLOW']);
        $this->assertSame(['download' => 50, 'upload' => 20], $viaSelection);
        $this->assertSame(['download' => 100, 'upload' => 40], VocusLineSelection::capability($q, ['line_choice' => 'new']));
    }

    public function test_tc2_speed_is_never_used_as_a_line_speed(): void
    {
        // Data Types CopperPairRecord: TC2Speed is "Traffic Class 2 supported speed", not a theoretical line rate.
        $noRange = Q::fromParams(['ServiceType' => 'FTTN', 'CopperPairRecord' => "<![CDATA[<CopperPairRecord><CopperPairID>C1</CopperPairID><TC2Speed>25</TC2Speed><TC2Speed>50</TC2Speed></CopperPairRecord>]]>"]);
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability($noRange, false, 'C1'));

        $withRange = Q::fromParams(['ServiceType' => 'FTTN', 'CopperPairRecord' => "<![CDATA[<CopperPairRecord><CopperPairID>C1</CopperPairID><DownloadSpeed>12-25</DownloadSpeed><TC2Speed>100</TC2Speed></CopperPairRecord>]]>"]);
        $this->assertSame(25, Q::lineCapability($withRange, false, 'C1')['download']);
    }

    public function test_ntd_capability_uses_ntd_speeds_only_when_returned(): void
    {
        $this->assertSame(['download' => 1000, 'upload' => 400], Q::lineCapability($this->ntdQualification(), false, null, 'NTD1', 1));
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability($this->ntdQualification(), true));
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability($this->ntdQualification(false), false, null, 'NTD1', 1));

        // The NTDRecord speed meaning is undocumented: the cap can be switched off.
        config(['services.vocus.ntd_speed_is_cap' => false]);
        $this->assertSame(['download' => null, 'upload' => null], Q::lineCapability($this->ntdQualification(), false, null, 'NTD1', 1));
    }

    public function test_plan_problem_only_blocks_when_both_speeds_are_known(): void
    {
        $cap = ['download' => 50, 'upload' => 20];
        $this->assertNull(VocusLineSelection::planProblem(50, 20, $cap, 'P'));
        $this->assertNotNull(VocusLineSelection::planProblem(100, 20, $cap, 'P'));
        $this->assertNotNull(VocusLineSelection::planProblem(25, 40, $cap, 'P'));
        $this->assertNull(VocusLineSelection::planProblem(null, null, $cap, 'P'));
        $this->assertNull(VocusLineSelection::planProblem(1000, 400, ['download' => null, 'upload' => null], 'P'));
        $this->assertNull(VocusLineSelection::planProblem(1000, 400, ['download' => null, 'upload' => 400], 'P'));
    }

    // ---------------------------------------------------------------- order form

    public function test_order_form_carries_plan_and_line_speeds_and_the_filter_notes(): void
    {
        $this->plan('P-FAST', 100, 40);
        $this->plan('P-NOSPEED', null, null);
        $token = $this->cacheQualification($this->copperQualification());

        $html = $this->actingAs($this->admin())
            ->get(route('admin.services.internet.order', ['directory_id' => self::LOC, 'service_type' => 'FTTN', 'qualification_token' => $token]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option[^>]*data-download="100"[^>]*data-upload="40"/', $html);
        $this->assertMatchesRegularExpression('/<option[^>]*data-download=""[^>]*data-upload=""/', $html);
        $this->assertStringContainsString('speed not set', $html);
        $this->assertStringContainsString('Set their download and upload speeds in Settings, Vocus, Plans', $html);
        // Radios in the line picker carry each pair's max (and the new-line option the best across pairs).
        $this->assertMatchesRegularExpression('/data-copper="CPI-SLOW"[^>]*data-max-down="50"[^>]*data-max-up="20"/', $html);
        $this->assertMatchesRegularExpression('/data-copper="CPI-FAST"[^>]*data-max-down="100"[^>]*data-max-up="40"/', $html);
        $this->assertMatchesRegularExpression('/data-choice="new"[^>]*data-max-down="100"[^>]*data-max-up="40"/', $html);
        $this->assertStringContainsString('id="f-plan-cap-note"', $html);
        $this->assertStringContainsString('Showing plans this line supports', $html);
        $this->assertStringContainsString('line not capable', $html);
        $this->assertStringContainsString('Max line speed: ', $html);
    }

    // ---------------------------------------------------------------- server enforcement

    public function test_order_rejects_a_plan_the_selected_pair_cannot_carry_before_any_soap_call(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->copperQualification());
        $fast = $this->plan('P-100', 100, 40);
        $ok   = $this->plan('P-50', 50, 20);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($fast, $token, ['line_choice' => 'existing', 'copper_pair_id' => 'CPI-SLOW']))
            ->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);
        $this->assertSame(0, InternetService::count());

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($ok, $token, ['line_choice' => 'existing', 'copper_pair_id' => 'CPI-SLOW']))
            ->assertSessionHasNoErrors();
        $this->assertCount(1, $soap->calls);
    }

    public function test_new_line_uses_best_pair_and_upload_limit_is_enforced(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->copperQualification());
        $admin = $this->admin();
        $tooFastUp = $this->plan('P-UP', 50, 100);   // download fine, upload 100 > 40
        $fits      = $this->plan('P-100', 100, 40);  // exactly the best pair

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($tooFastUp, $token, ['line_choice' => 'new']))
            ->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($fits, $token, ['line_choice' => 'new']))
            ->assertSessionHasNoErrors();
        $this->assertCount(1, $soap->calls);
    }

    public function test_transfer_rejects_a_plan_the_pair_cannot_carry(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->copperQualification());
        $fast = $this->plan('P-100', 100, 40);

        $this->actingAs($this->admin())->post(route('admin.services.internet.transfer.store'), $this->orderData($fast, $token, [
            'line_choice' => 'existing', 'copper_pair_id' => 'CPI-SLOW', 'ca_date' => now()->format('Y-m-d'),
        ]))->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);
    }

    public function test_unknown_plan_speed_or_unknown_capability_is_never_blocked(): void
    {
        $soap = $this->fakeClient([$this->ok(), $this->ok(), $this->ok()]);
        $admin = $this->admin();
        $copperToken = $this->cacheQualification($this->copperQualification());
        $noSpeedPlan = $this->plan('P-NONE', null, null);
        $bigPlan     = $this->plan('P-1000', 1000, 400);

        // Plan without speeds on a slow pair.
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($noSpeedPlan, $copperToken, ['line_choice' => 'existing', 'copper_pair_id' => 'CPI-SLOW']))
            ->assertSessionHasNoErrors();

        // Copper qualification that returned no pairs: capability unknown, a large plan is allowed.
        $emptyToken = $this->cacheQualification(Q::fromParams(['Result' => 'PASS', 'ServiceType' => 'FTTC']));
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($bigPlan, $emptyToken, ['service_type' => 'FTTC', 'line_choice' => 'new']))
            ->assertSessionHasNoErrors();

        // No qualification token at all (manual entry): no restriction.
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($bigPlan, '', ['qualification_token' => '', 'copper_pair_id' => 'CPI-ANY']))
            ->assertSessionHasNoErrors();
        $this->assertCount(3, $soap->calls);
    }

    public function test_ntd_orders_are_unaffected_without_a_capability(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->ntdQualification(false));
        $bigPlan = $this->plan('P-1000', 1000, 400);

        $this->actingAs($this->admin())->post(route('admin.services.internet.order.store'), $this->orderData($bigPlan, $token, [
            'service_type' => 'FTTP', 'line_choice' => 'existing', 'ntd_id' => 'NTD1', 'uni_port' => 1,
        ]))->assertSessionHasNoErrors();
        $this->assertCount(1, $soap->calls);
    }

    // ---------------------------------------------------------------- qualify page

    public function test_qualify_result_exposes_max_speed_per_pair(): void
    {
        $q = $this->copperQualification();

        $this->assertSame([50, 100], array_column($q['copper_pairs'], 'max_download_mbps'));
        $this->assertSame([20, 40], array_column($q['copper_pairs'], 'max_upload_mbps'));
        $this->actingAs($this->admin())->get(route('admin.services.internet.qualify'))->assertOk()->assertSee('Max line speed', false);
    }
}
