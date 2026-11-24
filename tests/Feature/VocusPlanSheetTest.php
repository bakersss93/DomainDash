<?php

namespace Tests\Feature;

require_once __DIR__ . '/../Support/soap_polyfill.php';

use App\Models\InternetService;
use App\Models\Setting;
use App\Models\User;
use App\Models\VocusPlan;
use App\Services\Vocus\VocusLineSelection;
use App\Services\Vocus\VocusOptions;
use App\Services\Vocus\VocusPlanSheetImporter as Importer;
use App\Services\Vocus\VocusQualification as Q;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeSoap;
use Tests\TestCase;

/**
 * Vocus plan technology / mapping, the plan sheet import, named tiers, Home tier rules and the NBNCPEPlanID
 * dropdown. Not runnable in the build environment (no vendor/, no ext-soap); run on the server:
 *   php artisan test --filter=VocusPlanSheetTest
 */
class VocusPlanSheetTest extends TestCase
{
    use RefreshDatabase;

    private const LOC = 'LOC001122334455';

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        Setting::put('vocus', ['access_key' => 'AK-SECRET-1', 'cert_path' => 'vocus/client.p12', 'environment' => 'sandbox', 'realms' => ['jargonconnect.com.au']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
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
        $client->setRateLimiter(new VocusRateLimiter(1000, 10, 15, fn () => null, fn () => 1000.0, 'sheet_rate_' . uniqid()));
        app()->instance(VocusWsmClient::class, $client);

        return $soap;
    }

    private function ok(): object
    {
        return (object) ['TransactionID' => 'EF99182AC', 'ResponseType' => 'ASYNC'];
    }

    private function ntdQualification(string $type): array
    {
        return Q::fromParams([
            'Result' => 'PASS', 'ServiceType' => $type,
            'NTDRecord' => '<![CDATA[<NTDRecord><NTDID>NTD1</NTDID><NTDVersion>2</NTDVersion></NTDRecord>]]>',
            'NBNPortRecord' => '<![CDATA[<NBNPortRecord><NTDID>NTD1</NTDID><PortNumber>1</PortNumber><PortName>UNI-D 1</PortName><Available>TRUE</Available><PortType>DATA</PortType></NBNPortRecord>]]>',
        ]);
    }

    private function cacheQualification(array $q): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put('vocus:qualification:' . $token, ['directory_id' => self::LOC, 'qualification' => $q], now()->addHour());

        return $token;
    }

    private function plan(string $id, array $o = []): VocusPlan
    {
        return VocusPlan::create($o + [
            'plan_id' => $id, 'name' => "Plan $id", 'speed_tier' => null, 'technology' => null, 'mapping' => null,
            'download_mbps' => null, 'upload_mbps' => null, 'cost_price' => '50.00', 'sell_price' => '79.95', 'active' => true, 'scopes' => null,
        ]);
    }

    private function orderData(VocusPlan $plan, ?string $token, array $o = []): array
    {
        return array_merge([
            'vocus_plan_id' => $plan->id, 'scope' => 'RESELLER-CONNECT', 'customer_name' => 'Yogesh Shinde', 'phone' => '0433111222',
            'directory_id' => self::LOC, 'location_reference' => '3000', 'service_type' => 'FTTP', 'qualification_token' => $token,
            'realm' => 'jargonconnect.com.au', 'username' => 'yshinde', 'password' => 'Abc-123.x', 'service_level' => 'STANDARD',
            'line_choice' => 'new',
        ], array_filter($o, fn ($v) => $v !== null), $token === null ? ['qualification_token' => null] : []);
    }

    /**
     * A small workbook shaped like the Vocus sheet: title rows, the left plan table, and an NBNCPEPlanID table to the
     * right that must be ignored. Mixes shared strings, an inline string and numeric cells.
     */
    private function makeXlsx(array $rows = null): string
    {
        $rows ??= [
            [5939, 'FIBRE', 'FTTP', 'Tagged', '100/40'],
            ['5939-DM', 'FIBRE', 'FTTP', 'Default Map', '100/40'],
            ['6717-DM', 'FIBRE', 'FTTP', 'Default Map', 'Home Fast'],
            ['5938-DM', 'FIBRE', 'Wireless', 'Default Map', 'Fixed Wireless Plus'],
            ['7027-DM', 'FIBRE', 'HFC', 'Default Map', '250/100'],
        ];

        $strings = [];
        $sid = function (string $v) use (&$strings): int {
            $i = array_search($v, $strings, true);
            if ($i === false) {
                $strings[] = $v;
                $i = count($strings) - 1;
            }

            return $i;
        };
        $cell = function (string $ref, mixed $v) use ($sid): string {
            if (is_int($v)) {
                return "<c r=\"$ref\"><v>$v</v></c>";
            }

            return "<c r=\"$ref\" t=\"s\"><v>" . $sid((string) $v) . '</v></c>';
        };

        $xml = '';
        $xml .= '<row r="2">' . $cell('A2', 'WSM FIBRE RESELLER-CONNECT BUNDLES PLAN ID') . $cell('G2', 'WSM FIBRE NBNCPEPlanID For FTTC') . '</row>';
        $xml .= '<row r="4">' . $cell('A4', 'FIBRE PlanID') . $cell('B4', 'ProductID') . $cell('C4', 'Technology') . $cell('D4', 'Mapping') . $cell('E4', 'LineSpeed')
            . $cell('G4', 'CPE PlanID') . $cell('H4', 'ProductID') . $cell('I4', 'Description') . '</row>';
        $r = 5;
        foreach ($rows as $row) {
            $xml .= "<row r=\"$r\">";
            foreach ($row as $i => $v) {
                $xml .= $cell(chr(65 + $i) . $r, $v);
            }
            if ($r === 5) { // right-hand table noise, one inline string
                $xml .= "<c r=\"G$r\" t=\"inlineStr\"><is><t>FTTCNCDRC</t></is></c>" . $cell("H$r", 'FIBRE');
            }
            $xml .= '</row>';
            $r++;
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>';
        $sst = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">'
            . implode('', array_map(fn ($s) => '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>', $strings)) . '</sst>';
        $wb = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="RC Bundles" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

        $path = tempnam(sys_get_temp_dir(), 'vocus') . '.xlsx';
        $this->tmp[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', $sst);
        $zip->close();

        return $path;
    }

    private function upload(string $path, string $name = 'plans.xlsx'): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    // ---------------------------------------------------------------- xlsx parsing

    public function test_xlsx_is_parsed_from_the_left_table_only(): void
    {
        $parsed = Importer::parseFile($this->makeXlsx(), 'xlsx');

        $this->assertSame([], $parsed['warnings']);
        $this->assertCount(5, $parsed['rows']);
        $this->assertSame(['5939', '5939-DM', '6717-DM', '5938-DM', '7027-DM'], array_column($parsed['rows'], 'plan_id'), 'numeric plan ids come back as text; the right-hand table is ignored');

        $first = $parsed['rows'][0];
        $this->assertSame('FTTP', $first['technology']);
        $this->assertSame('TAGGED', $first['mapping']);
        $this->assertSame('100/40', $first['speed_tier']);
        $this->assertSame([100, 40], [$first['download'], $first['upload']]);

        $wireless = $parsed['rows'][3];
        $this->assertSame('WIRELESS', $wireless['technology']);
        $this->assertSame('DEFAULT_MAP', $wireless['mapping']);
    }

    public function test_csv_is_parsed_and_bad_rows_warn(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'vocus') . '.csv';
        $this->tmp[] = $csv;
        file_put_contents($csv, "\xEF\xBB\xBFFIBRE PlanID,ProductID,Technology,Mapping,LineSpeed\n5939,FIBRE,FTTP,Tagged,100/40\n5940,FIBRE,Satellite,Tagged,12/1\n!!,FIBRE,FTTP,Tagged,12/1\n5941,FIBRE,FTTB,Whatever,12/1\n5939,FIBRE,FTTP,Tagged,100/40\n");

        $parsed = Importer::parseFile($csv, 'csv');

        $this->assertSame(['5939'], array_column($parsed['rows'], 'plan_id'));
        $this->assertCount(4, $parsed['warnings']); // unknown technology, bad id, unknown mapping, duplicate
    }

    public function test_missing_header_and_wrong_type_are_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $csv = tempnam(sys_get_temp_dir(), 'vocus') . '.csv';
        $this->tmp[] = $csv;
        file_put_contents($csv, "a,b,c\n1,2,3\n");
        Importer::parseFile($csv, 'csv');
    }

    public function test_unsupported_extension_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $f = tempnam(sys_get_temp_dir(), 'vocus');
        $this->tmp[] = $f;
        file_put_contents($f, 'x');
        Importer::parseFile($f, 'pdf');
    }

    // ---------------------------------------------------------------- preview and upsert

    public function test_preview_counts_new_updated_and_unchanged(): void
    {
        // 5939: same technology / mapping / speed as the sheet => unchanged. 5939-DM: exists with no technology => updated.
        $this->plan('5939', ['technology' => 'FTTP', 'mapping' => 'TAGGED', 'speed_tier' => '100/40', 'download_mbps' => 100, 'upload_mbps' => 40]);
        $this->plan('5939-DM');
        $rows = Importer::parseFile($this->makeXlsx(), 'xlsx')['rows'];

        $all = Importer::plan($rows, null);
        $this->assertSame([3, 1, 1, 0], [count($all['new']), count($all['updated']), count($all['unchanged']), $all['skipped']]);

        $dm = Importer::plan($rows, 'DEFAULT_MAP'); // the Tagged 5939 row is left out
        $this->assertSame([3, 1, 0, 1], [count($dm['new']), count($dm['updated']), count($dm['unchanged']), $dm['skipped']]);
    }

    public function test_apply_keeps_existing_name_prices_and_active_and_creates_new_plans_inactive(): void
    {
        $existing = $this->plan('5939-DM', ['name' => 'Gold Fibre 100', 'cost_price' => '61.00', 'sell_price' => '89.00', 'active' => true, 'scopes' => ['RESELLER-CONNECT']]);
        $rows = Importer::parseFile($this->makeXlsx(), 'xlsx')['rows'];

        $counts = Importer::apply($rows, 'DEFAULT_MAP');

        $this->assertSame(['new' => 3, 'updated' => 1, 'unchanged' => 0, 'skipped' => 1], $counts);

        $existing->refresh();
        $this->assertSame('Gold Fibre 100', $existing->name);
        $this->assertSame('61.00', (string) $existing->cost_price);
        $this->assertSame('89.00', (string) $existing->sell_price);
        $this->assertTrue($existing->active);
        $this->assertSame(['RESELLER-CONNECT'], $existing->scopes);
        $this->assertSame(['FTTP', 'DEFAULT_MAP', '100/40', 100, 40], [$existing->technology, $existing->mapping, $existing->speed_tier, $existing->download_mbps, $existing->upload_mbps]);

        $new = VocusPlan::where('plan_id', '6717-DM')->first();
        $this->assertSame('FTTP Home Fast (Default Map)', $new->name);
        $this->assertFalse($new->active);
        $this->assertSame(['0.00', '0.00'], [(string) $new->cost_price, (string) $new->sell_price]);
        $this->assertSame([500, 50], [$new->download_mbps, $new->upload_mbps], 'named tier speeds');

        $fw = VocusPlan::where('plan_id', '5938-DM')->first();
        $this->assertSame('Wireless Fixed Wireless Plus (Default Map)', $fw->name);
        $this->assertNull($fw->download_mbps, 'Fixed Wireless Plus has no known speed');

        $this->assertNull(VocusPlan::where('plan_id', '5939')->first(), 'the Tagged row was skipped by the Default Map only option');

        // Re-running changes nothing.
        $again = Importer::apply($rows, 'DEFAULT_MAP');
        $this->assertSame([0, 0, 4], [$again['new'], $again['updated'], $again['unchanged']]);
    }

    public function test_an_unknown_named_tier_does_not_wipe_existing_speeds(): void
    {
        $this->plan('5938-DM', ['download_mbps' => 50, 'upload_mbps' => 10]);
        Importer::apply(Importer::parseFile($this->makeXlsx(), 'xlsx')['rows'], null);

        $p = VocusPlan::where('plan_id', '5938-DM')->first();
        $this->assertSame([50, 10], [$p->download_mbps, $p->upload_mbps]);
        $this->assertSame('Fixed Wireless Plus', $p->speed_tier);
    }

    public function test_import_endpoints_preview_then_apply_and_audit_counts_only(): void
    {
        $admin = $this->admin();
        $file = $this->upload($this->makeXlsx());

        $preview = $this->actingAs($admin)->post(route('admin.settings.vocus.plans.import.preview'), ['file' => $file, 'only_mapping' => '1'])->assertOk();
        $preview->assertJsonPath('counts.new', 4)->assertJsonPath('counts.skipped', 1)->assertJsonPath('total_rows', 5);
        $this->assertSame(0, VocusPlan::count(), 'a preview writes nothing');
        $token = $preview->json('token');

        // Unticking the option re-previews from the cached rows (no re-upload).
        $this->actingAs($admin)->post(route('admin.settings.vocus.plans.import.preview'), ['token' => $token, 'only_mapping' => '0'])
            ->assertOk()->assertJsonPath('counts.new', 5)->assertJsonPath('counts.skipped', 0);

        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.import.apply'), ['token' => $token, 'only_mapping' => true])
            ->assertOk()->assertJsonPath('counts.new', 4);
        $this->assertSame(4, VocusPlan::count());
        $this->assertSame(0, VocusPlan::where('active', true)->count());

        // The token is single use.
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.import.apply'), ['token' => $token])->assertStatus(422);
    }

    public function test_import_rejects_wrong_file_types_and_other_users_tokens(): void
    {
        $admin = $this->admin();
        $bad = UploadedFile::fake()->create('plans.pdf', 10, 'application/pdf');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.import.preview'), ['file' => $bad])->assertStatus(422);

        $notZip = UploadedFile::fake()->createWithContent('plans.xlsx', 'this is not a zip');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.import.preview'), ['file' => $notZip])->assertStatus(422);

        $token = $this->actingAs($admin)->post(route('admin.settings.vocus.plans.import.preview'), ['file' => $this->upload($this->makeXlsx())])->json('token');
        $this->actingAs($this->admin())->postJson(route('admin.settings.vocus.plans.import.apply'), ['token' => $token])->assertStatus(422);
    }

    // ---------------------------------------------------------------- settings: mapping, named tiers, plan CRUD

    public function test_plan_mapping_defaults_to_default_map_and_is_saved(): void
    {
        $this->assertSame('DEFAULT_MAP', VocusOptions::planMapping());

        $this->actingAs($this->admin())->putJson(route('admin.settings.vocus.plan-mapping'), ['plan_mapping' => 'TAGGED'])->assertOk();
        $this->assertSame('TAGGED', VocusOptions::planMapping());
        $this->assertSame('AK-SECRET-1', Setting::get('vocus')['access_key'], 'other vocus settings are kept');

        $this->actingAs($this->admin())->putJson(route('admin.settings.vocus.plan-mapping'), ['plan_mapping' => 'NOPE'])->assertStatus(422);
    }

    public function test_named_tier_defaults_lookup_and_overrides(): void
    {
        $this->assertSame([500, 50], VocusOptions::speedsForTier('Home Fast'));
        $this->assertSame([750, 50], VocusOptions::speedsForTier('Home SuperFast'), 'spacing and case do not matter');
        $this->assertSame([1000, 100], VocusOptions::speedsForTier('Home Ultra Fast'));
        $this->assertSame([2000, 200], VocusOptions::speedsForTier('home hyper fast'));
        $this->assertNull(VocusOptions::speedsForTier('Fixed Wireless Plus'));
        $this->assertSame([100, 40], VocusOptions::speedsForTier('100/40'));
        $this->assertNull(VocusOptions::speedsForTier('Mystery'));

        $this->actingAs($this->admin())->putJson(route('admin.settings.vocus.named-tiers'), ['tiers' => [
            'Home Fast' => ['download' => '400', 'upload' => '40'], 'Home Super Fast' => ['download' => '', 'upload' => ''],
        ]])->assertOk();
        $this->assertSame([400, 40], VocusOptions::speedsForTier('Home Fast'));
        $this->assertNull(VocusOptions::speedsForTier('Home Super Fast'));

        $this->actingAs($this->admin())->putJson(route('admin.settings.vocus.named-tiers'), ['tiers' => ['Home Fast' => ['download' => '400', 'upload' => '']]])->assertStatus(422);
    }

    public function test_plan_crud_stores_technology_mapping_and_fills_speeds_from_a_named_tier(): void
    {
        $admin = $this->admin();
        $body = ['plan_id' => '6717-DM', 'name' => 'Home Fast FTTP', 'speed_tier' => 'Home Fast', 'technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'cost_price' => '60.00', 'sell_price' => '85.00', 'active' => true];

        $res = $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), $body)->assertCreated();
        $res->assertJsonPath('plan.technology', 'FTTP')->assertJsonPath('plan.mapping', 'DEFAULT_MAP')->assertJsonPath('plan.download_mbps', 500)->assertJsonPath('plan.upload_mbps', 50);
        $id = $res->json('plan.id');

        // Updating without technology / mapping keeps them; plan_id stays unique.
        unset($body['technology'], $body['mapping']);
        $body['name'] = 'Renamed';
        $this->actingAs($admin)->putJson(route('admin.settings.vocus.plans.update', $id), $body)->assertOk()->assertJsonPath('plan.technology', 'FTTP')->assertJsonPath('plan.mapping', 'DEFAULT_MAP');
        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), $body)->assertStatus(422);

        $this->actingAs($admin)->postJson(route('admin.settings.vocus.plans.store'), ['technology' => 'ADSL', 'plan_id' => 'X1', 'name' => 'x', 'cost_price' => '1', 'sell_price' => '1'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- form filtering

    private function orderHtml(?string $token, string $type = 'FTTP'): string
    {
        $params = ['directory_id' => self::LOC, 'service_type' => $type];
        if ($token) {
            $params['qualification_token'] = $token;
        }

        return $this->actingAs($this->admin())->get(route('admin.services.internet.order', $params))->assertOk()->getContent();
    }

    public function test_order_form_offers_only_plans_for_the_qualified_technology_and_mapping(): void
    {
        $this->plan('P-FTTP-DM', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP']);
        $this->plan('P-FTTP-TAG', ['technology' => 'FTTP', 'mapping' => 'TAGGED']);
        $this->plan('P-FTTN-DM', ['technology' => 'FTTN', 'mapping' => 'DEFAULT_MAP']);
        $this->plan('P-ANY', ['technology' => null, 'mapping' => null]);
        $this->plan('P-FTTP-NOMAP', ['technology' => 'FTTP', 'mapping' => null]);
        $this->plan('P-OFF', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'active' => false]);
        $token = $this->cacheQualification($this->ntdQualification('FIBRE')); // QUALIFY says FIBRE = FTTP

        $html = $this->orderHtml($token);

        $this->assertStringContainsString('data-plan-code="P-FTTP-DM"', $html);
        $this->assertStringContainsString('data-plan-code="P-ANY"', $html);
        $this->assertStringContainsString('data-plan-code="P-FTTP-NOMAP"', $html);
        $this->assertStringNotContainsString('data-plan-code="P-FTTP-TAG"', $html);
        $this->assertStringNotContainsString('data-plan-code="P-FTTN-DM"', $html);
        $this->assertStringNotContainsString('data-plan-code="P-OFF"', $html);
        $this->assertStringContainsString('plans on the', $html);
        $this->assertStringContainsString('Default Map', $html);

        // Switching the account mapping flips the choice.
        Setting::put('vocus', array_merge(Setting::get('vocus'), ['plan_mapping' => 'TAGGED']));
        $html = $this->orderHtml($token);
        $this->assertStringContainsString('data-plan-code="P-FTTP-TAG"', $html);
        $this->assertStringNotContainsString('data-plan-code="P-FTTP-DM"', $html);
    }

    public function test_fixed_wireless_qualification_maps_to_wireless_plans(): void
    {
        $this->plan('P-WL', ['technology' => 'WIRELESS', 'mapping' => 'DEFAULT_MAP']);
        $this->plan('P-FTTP', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP']);
        $token = $this->cacheQualification(Q::fromParams(['Result' => 'PASS', 'ServiceType' => 'WIRELESS', 'NTDRecord' => '<![CDATA[<NTDRecord><NTDID>N1</NTDID></NTDRecord>]]>']));

        $html = $this->orderHtml($token, 'WIRELESS');

        $this->assertStringContainsString('data-plan-code="P-WL"', $html);
        $this->assertStringNotContainsString('data-plan-code="P-FTTP"', $html);
    }

    public function test_without_a_qualification_the_technology_is_not_filtered_but_the_mapping_is(): void
    {
        $this->plan('P-FTTP-DM', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP']);
        $this->plan('P-FTTN-DM', ['technology' => 'FTTN', 'mapping' => 'DEFAULT_MAP']);
        $this->plan('P-FTTN-TAG', ['technology' => 'FTTN', 'mapping' => 'TAGGED']);

        $html = $this->orderHtml(null, 'FTTN');

        $this->assertStringContainsString('data-plan-code="P-FTTP-DM"', $html);
        $this->assertStringContainsString('data-plan-code="P-FTTN-DM"', $html);
        $this->assertStringNotContainsString('data-plan-code="P-FTTN-TAG"', $html);
    }

    // ---------------------------------------------------------------- server enforcement

    public function test_order_rejects_a_plan_for_another_technology_before_any_soap_call(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->ntdQualification('FIBRE'));
        $wrong = $this->plan('P-FTTN', ['technology' => 'FTTN', 'mapping' => 'DEFAULT_MAP']);
        $right = $this->plan('P-FTTP', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($wrong, $token))->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);
        $this->assertSame(0, InternetService::count());

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($right, $token))->assertSessionHasNoErrors();
        $this->assertCount(1, $soap->calls);
    }

    public function test_order_and_transfer_reject_a_plan_on_the_other_mapping(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->ntdQualification('FTTP'));
        $tagged = $this->plan('P-TAG', ['technology' => 'FTTP', 'mapping' => 'TAGGED']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($tagged, $token))->assertSessionHasErrors('vocus_plan_id');
        $this->actingAs($admin)->post(route('admin.services.internet.transfer.store'), $this->orderData($tagged, $token, [
            'line_choice' => 'existing', 'uni_port' => '1', 'ntd_id' => 'NTD1', 'order_type' => 'OUTSTANDING', 'ca_date' => now()->format('Y-m-d'),
        ]))->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);
    }

    public function test_transfer_rejects_a_plan_for_another_technology(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->ntdQualification('FTTP'));
        $hfc = $this->plan('P-HFC', ['technology' => 'HFC', 'mapping' => 'DEFAULT_MAP']);

        $this->actingAs($this->admin())->post(route('admin.services.internet.transfer.store'), $this->orderData($hfc, $token, [
            'line_choice' => 'existing', 'uni_port' => '1', 'ntd_id' => 'NTD1', 'order_type' => 'OUTSTANDING', 'ca_date' => now()->format('Y-m-d'),
        ]))->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);
    }

    public function test_no_qualification_means_no_technology_check_but_the_mapping_is_still_enforced(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $admin = $this->admin();
        $tagged = $this->plan('P-TAG', ['technology' => 'FTTN', 'mapping' => 'TAGGED']);
        $dm     = $this->plan('P-DM', ['technology' => 'FTTN', 'mapping' => 'DEFAULT_MAP']);
        $manual = ['service_type' => 'FTTP'];

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($tagged, null, $manual))->assertSessionHasErrors('vocus_plan_id');
        $this->assertCount(0, $soap->calls);
        // An FTTN plan on a manual FTTP order passes: nothing qualified the address.
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($dm, null, $manual))->assertSessionDoesntHaveErrors('vocus_plan_id');
    }

    // ---------------------------------------------------------------- Home tier rules

    public function test_home_tiers_are_standard_service_level_only(): void
    {
        $home  = new VocusPlan(['speed_tier' => 'Home Fast']);
        $super = new VocusPlan(['speed_tier' => 'Home SuperFast']);
        $plain = new VocusPlan(['speed_tier' => '100/40']);

        $this->assertTrue($home->isHomeTier());
        $this->assertTrue($super->isHomeTier());
        $this->assertFalse($plain->isHomeTier());
        $this->assertSame(['STANDARD'], $home->allowedServiceLevels());
        $this->assertContains('ENHANCED4', $plain->allowedServiceLevels());
    }

    public function test_order_rejects_an_enhanced_service_level_on_a_home_tier_plan(): void
    {
        $soap = $this->fakeClient([$this->ok()]);
        $token = $this->cacheQualification($this->ntdQualification('FTTP'));
        $home = $this->plan('P-HOME', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'speed_tier' => 'Home Fast']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($home, $token, ['service_level' => 'ENHANCED4']))->assertSessionHasErrors('service_level');
        $this->assertCount(0, $soap->calls);

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $this->orderData($home, $token, ['service_level' => 'STANDARD']))->assertSessionHasNoErrors();
        $this->assertCount(1, $soap->calls);
    }

    public function test_form_marks_home_tier_plans_standard_only_and_high_tiers_for_sq(): void
    {
        $this->plan('P-HOME', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'speed_tier' => 'Home Fast']);
        $this->plan('P-HYPER', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'speed_tier' => 'Home Hyper Fast']);
        $this->plan('P-2000', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'speed_tier' => '2000/500']);
        $this->plan('P-100', ['technology' => 'FTTP', 'mapping' => 'DEFAULT_MAP', 'speed_tier' => '100/40']);
        $html = $this->orderHtml($this->cacheQualification($this->ntdQualification('FTTP')));

        $this->assertMatchesRegularExpression('/data-plan-code="P-HOME"[^>]*data-standard-only="1" data-sq="0"/', $html);
        $this->assertMatchesRegularExpression('/data-plan-code="P-HYPER"[^>]*data-standard-only="1" data-sq="1"/', $html);
        $this->assertMatchesRegularExpression('/data-plan-code="P-2000"[^>]*data-standard-only="0" data-sq="1"/', $html);
        $this->assertMatchesRegularExpression('/data-plan-code="P-100"[^>]*data-standard-only="0" data-sq="0"/', $html);
        $this->assertStringContainsString('Availability depends on the SQ result', $html);
    }

    public function test_sq_note_rules_per_technology(): void
    {
        $mk = fn (string $tech, string $tier, ?int $d = null, ?int $u = null) => (new VocusPlan(['technology' => $tech, 'speed_tier' => $tier, 'download_mbps' => $d, 'upload_mbps' => $u]))->needsSqCheck();

        $this->assertTrue($mk('HFC', '250/100', 250, 100));
        $this->assertTrue($mk('HFC', 'Home Ultra Fast'));
        $this->assertTrue($mk('HFC', 'Home Hyper Fast'));
        $this->assertFalse($mk('HFC', '100/40', 100, 40));
        $this->assertTrue($mk('FTTP', 'Home Hyper Fast'));
        $this->assertTrue($mk('FTTP', '2000/500', 2000, 500));
        $this->assertFalse($mk('FTTP', 'Home Ultra Fast'));
        $this->assertFalse($mk('FTTN', 'Home Hyper Fast'));
    }

    // ---------------------------------------------------------------- NBNCPEPlanID

    public function test_nbn_cpe_catalogue_is_grouped_by_technology_from_the_sheet(): void
    {
        $c = VocusOptions::NBN_CPE_PLANS;

        $this->assertSame(['FTTCNCDNBNPROFINSTALL', 'FTTCNCDRC'], array_keys($c['FTTC']));
        $this->assertSame(['HFCNTDNBNPROFINSTALL', 'HFCNTDNBNPROFINSTALL-MISSING', 'HFCNTDWHS', 'HFCNTDWHS-MISSING'], array_keys($c['HFC']));
        $this->assertSame(['FTTPNTDNBNPROFINSTALL-V3-1P', 'FTTPNTDNBNPROFINSTALL-V3-1P-R', 'FTTPNTDNBNPROFINSTALL-V4-4P', 'FTTPNTDNBNPROFINSTALL-V4-4P-R'], array_keys($c['FTTP']));
        $this->assertSame('Next Generation 1 Port NTD - Replacement', $c['FTTP']['FTTPNTDNBNPROFINSTALL-V3-1P-R'][0]);
        $this->assertArrayNotHasKey('WIRELESS', $c);
        $this->assertSame('TPLink Vx220 FTTC', config('services.vocus.router_cpe_plans')['VX220FTTC']);
    }

    public function test_nbn_install_dropdown_is_filtered_by_technology(): void
    {
        $fttp = $this->orderHtml($this->cacheQualification($this->ntdQualification('FTTP')), 'FTTP');
        $this->assertStringContainsString('id="f-nbncpe"', $fttp);
        $this->assertStringContainsString('value="FTTPNTDNBNPROFINSTALL-V3-1P"', $fttp);
        $this->assertStringContainsString('value="FTTPNTDNBNPROFINSTALL-V4-4P-R"', $fttp);
        $this->assertStringNotContainsString('value="HFCNTDNBNPROFINSTALL"', $fttp);
        $this->assertStringContainsString('professional install only', $fttp);

        $hfc = $this->orderHtml($this->cacheQualification($this->ntdQualification('HFC')), 'HFC');
        $this->assertStringContainsString('value="HFCNTDNBNPROFINSTALL"', $hfc);
        $this->assertStringContainsString('value="HFCNTDNBNPROFINSTALL-MISSING"', $hfc);
        $this->assertMatchesRegularExpression('/<option value="HFCNTDWHS"[^>]*disabled/', $hfc, 'self install needs a shipping address and is not offered');
        $this->assertStringNotContainsString('value="FTTPNTDNBNPROFINSTALL-V3-1P"', $hfc);

        $wireless = $this->orderHtml($this->cacheQualification($this->ntdQualification('WIRELESS')), 'WIRELESS');
        $this->assertStringNotContainsString('id="f-nbncpe"', $wireless);
    }

    public function test_nbn_cpe_selection_is_validated_server_side(): void
    {
        $q = $this->ntdQualification('HFC');

        // New HFC NTD with a chosen (valid) option sends that option instead of the default.
        $ok = VocusLineSelection::resolve($q, 'HFC', ['line_choice' => 'new', 'nbn_cpe_plan_id' => 'HFCNTDNBNPROFINSTALL-MISSING'], false);
        $this->assertSame([], $ok['errors']);
        $this->assertSame(['NTDID' => 'NEW', 'NBNCPEPlanID' => 'HFCNTDNBNPROFINSTALL-MISSING'], $ok['params']);

        // A value for another technology, or one that ships equipment, is refused.
        $wrongTech = VocusLineSelection::resolve($q, 'HFC', ['line_choice' => 'new', 'nbn_cpe_plan_id' => 'FTTPNTDNBNPROFINSTALL-V3-1P'], false);
        $this->assertArrayHasKey('nbn_cpe_plan_id', $wrongTech['errors']);
        $ships = VocusLineSelection::resolve($q, 'HFC', ['line_choice' => 'new', 'nbn_cpe_plan_id' => 'HFCNTDWHS'], false);
        $this->assertArrayHasKey('nbn_cpe_plan_id', $ships['errors']);

        // FTTP replacement needs the NTD being replaced.
        $fttp = $this->ntdQualification('FTTP');
        $noNtd = VocusLineSelection::resolve($fttp, 'FTTP', ['line_choice' => 'new', 'nbn_cpe_plan_id' => 'FTTPNTDNBNPROFINSTALL-V4-4P-R'], false);
        $this->assertArrayHasKey('nbn_cpe_plan_id', $noNtd['errors']);
        $withNtd = VocusLineSelection::resolve($fttp, 'FTTP', ['line_choice' => 'existing', 'ntd_id' => 'NTD1', 'nbn_cpe_plan_id' => 'FTTPNTDNBNPROFINSTALL-V4-4P-R'], false);
        $this->assertSame('FTTPNTDNBNPROFINSTALL-V4-4P-R', $withNtd['params']['NBNCPEPlanID']);
    }
}
