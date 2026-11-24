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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeSoap;
use Tests\TestCase;

/**
 * Technology-aware qualify -> order flow: copper pairs (FTTN / FTTC) versus NTD ports (FTTP / HFC / Fixed Wireless).
 * Not runnable in the build environment (no vendor/, no ext-soap); run on the server:
 *   php artisan test --filter=VocusLineSelectionTest
 */
class VocusLineSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        Setting::put('vocus', ['access_key' => 'AK-SECRET-1', 'cert_path' => 'vocus/client.p12', 'environment' => 'sandbox', 'realms' => ['jargonconnect.com.au']]);
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
        $client->setRateLimiter(new VocusRateLimiter(1000, 10, 15, fn () => null, fn () => 1000.0, 'sel_rate_' . uniqid()));
        app()->instance(VocusWsmClient::class, $client);

        return $soap;
    }

    private function xmlOf(FakeSoap $soap, int $call): string
    {
        $payload = $soap->calls[$call]['args'][0];

        return (string) ($payload->enc_value ?? $payload->data);
    }

    /** @param list<array{0:string,1:string}> $params */
    private function response(array $params): object
    {
        $list = array_map(fn ($p) => (object) ['id' => $p[0], '_' => $p[1]], $params);

        return (object) ['TransactionID' => 'Q1', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => $list]];
    }

    private function ok(): object
    {
        return (object) ['TransactionID' => 'EF99182AC', 'ResponseType' => 'ASYNC'];
    }

    private function pair(string $id, string $status = 'Inactive'): string
    {
        return "<![CDATA[<CopperPairRecord><CopperPairID>$id</CopperPairID><CopperPairStatus>$status</CopperPairStatus>"
            . '<NBNServiceStatus>Line In Use</NBNServiceStatus><ServiceClass>12</ServiceClass><UploadSpeed>20-40</UploadSpeed>'
            . '<DownloadSpeed>50-100</DownloadSpeed><NetworkCoExist>TRUE</NetworkCoExist><ExtraCharge>FALSE</ExtraCharge></CopperPairRecord>]]>';
    }

    /** One NBNPortRecord CDATA per port; $used lists the port numbers that are in use. */
    private function portRecords(int $count, array $used, string $ntd = 'NTD000111222'): array
    {
        $out = [];
        for ($n = 1; $n <= $count; $n++) {
            $free = in_array($n, $used, true) ? 'FALSE' : 'TRUE';
            $out[] = ['NBNPortRecord', "<![CDATA[<NBNPortRecord><NTDID>$ntd</NTDID><NTDVersion>2</NTDVersion><PortNumber>$n</PortNumber>"
                . "<PortName>UNI-D $n</PortName><Available>$free</Available><PortType>DATA</PortType></NBNPortRecord>]]>"];
        }

        return $out;
    }

    private function ntdRecord(string $ntd = 'NTD000111222'): array
    {
        return ['NTDRecord', "<![CDATA[<NTDRecord><NTDID>$ntd</NTDID><NTDType>NTD-FTTP</NTDType><NTDVersion>2</NTDVersion><Battery>TRUE</Battery>"
            . '<DownloadSpeed>1000</DownloadSpeed><UploadSpeed>400</UploadSpeed></NTDRecord>]]>'];
    }

    private function fttpQualify(int $ports = 4, array $used = [1, 3]): object
    {
        return $this->response(array_merge(
            [['Result', 'PASS'], ['ServiceType', 'FTTP'], ['ServiceClass', '3'], ['Zone', 'Urban'], $this->ntdRecord()],
            $this->portRecords($ports, $used),
        ));
    }

    private function plan(): VocusPlan
    {
        return VocusPlan::create(['plan_id' => 'RC-100-20', 'name' => 'Fast 100', 'speed_tier' => '100/20', 'cost_price' => '50.00', 'sell_price' => '79.95', 'active' => true, 'scopes' => null]);
    }

    /** Run the qualify endpoint and return [qualification_token, json]. */
    private function qualify(User $admin, object $response): array
    {
        $json = $this->actingAs($admin)->postJson(route('admin.services.internet.qualify.check'), ['loc_id' => 'LOC001122334455'])->assertOk();

        return [$json->json('qualification_token'), $json->json()];
    }

    private function order(array $o = []): array
    {
        return array_merge([
            'vocus_plan_id' => $this->plan()->id, 'scope' => 'RESELLER-CONNECT', 'customer_name' => 'Yogesh Shinde', 'phone' => '0433111222',
            'directory_id' => 'LOC001122334455', 'location_reference' => '3000', 'realm' => 'jargonconnect.com.au', 'username' => 'yshinde',
            'service_level' => 'STANDARD',
        ], $o);
    }

    // ---------------------------------------------------------------- parsing

    public function test_single_and_multiple_copper_pair_records_are_parsed(): void
    {
        $one = Q::fromParams(['ServiceType' => 'FTTC', 'CopperPairRecord' => $this->pair('CPI300010022999')]);
        $this->assertSame(Q::GROUP_COPPER, $one['technology_group']);
        $this->assertCount(1, $one['copper_pairs']);
        $this->assertSame('CPI300010022999', $one['copper_pairs'][0]['copper_pair_id']);
        $this->assertSame('INACTIVE', $one['copper_pairs'][0]['status']);
        $this->assertSame('Line In Use', $one['copper_pairs'][0]['nbn_service_status']);
        $this->assertSame('50-100', $one['copper_pairs'][0]['download_speed']);
        $this->assertTrue($one['copper_pairs'][0]['network_co_exist']);
        $this->assertFalse($one['copper_pairs'][0]['extra_charge']);

        $many = Q::fromParams(['ServiceType' => 'FTTN', 'CopperPairRecord' => [$this->pair('CPI1'), $this->pair('CPI2', 'Active')]]);
        $this->assertSame(['CPI1', 'CPI2'], array_column($many['copper_pairs'], 'copper_pair_id'));

        // Several records inside one CDATA, and already decoded arrays.
        $combined = Q::fromParams(['CopperPairRecord' => '<![CDATA[<CopperPairRecord><CopperPairID>A</CopperPairID></CopperPairRecord><CopperPairRecord><CopperPairID>B</CopperPairID></CopperPairRecord>]]>']);
        $this->assertSame(['A', 'B'], array_column($combined['copper_pairs'], 'copper_pair_id'));
        $decoded = Q::fromParams(['CopperPairRecord' => [['CopperPairID' => 'C'], ['CopperPairID' => 'D']]]);
        $this->assertSame(['C', 'D'], array_column($decoded['copper_pairs'], 'copper_pair_id'));
    }

    public function test_four_port_fttp_ntd_groups_ports_by_ntdid_with_in_use_ports(): void
    {
        $q = Q::fromParams([
            'Result' => 'PASS', 'ServiceType' => 'FTTP', 'ServiceClass' => '3', 'Zone' => 'Urban', 'DevelopmentCharge' => 'FALSE',
            'NTDRecord' => $this->ntdRecord()[1],
            'NBNPortRecord' => array_column($this->portRecords(4, [1, 3]), 1),
        ]);

        $this->assertSame(Q::GROUP_NTD, $q['technology_group']);
        $this->assertSame('FTTP', $q['service_class_info']['label']);
        $this->assertFalse($q['development_charge']);
        $this->assertCount(1, $q['ntds']);
        $ntd = $q['ntds'][0];
        $this->assertSame('NTD000111222', $ntd['ntd_id']);
        $this->assertSame(1, $ntd['index']);
        $this->assertTrue($ntd['battery']);
        $this->assertSame([1, 2, 3, 4], array_column($ntd['ports'], 'port_number'));
        $this->assertSame([false, true, false, true], array_column($ntd['ports'], 'available'));
        $this->assertSame([2, 4], array_column(Q::dataPorts($q['ntds']), 'port_number'));
        $this->assertFalse($q['needs_ntd_install']);
    }

    public function test_single_port_ntd_and_two_ntds_and_voice_ports(): void
    {
        $single = Q::fromParams(['ServiceType' => 'HFC', 'NBNPortRecord' => array_column($this->portRecords(1, []), 1)]);
        $this->assertSame(Q::GROUP_NTD, $single['technology_group']);
        $this->assertCount(1, $single['ntds'][0]['ports']);
        $this->assertCount(1, Q::dataPorts($single['ntds']));

        $two = Q::fromParams(['ServiceType' => 'FTTP', 'NBNPortRecord' => array_merge(
            array_column($this->portRecords(2, [], 'NTDA'), 1), array_column($this->portRecords(2, [], 'NTDB'), 1))]);
        $this->assertSame(['NTDA', 'NTDB'], array_column($two['ntds'], 'ntd_id'));
        $this->assertSame([1, 2], array_column($two['ntds'], 'index'));

        $voice = Q::fromParams(['ServiceType' => 'FTTP', 'NBNPortRecord' => '<NBNPortRecord><NTDID>N</NTDID><PortNumber>5</PortNumber><Available>TRUE</Available><PortType>VOICE</PortType></NBNPortRecord>']);
        $this->assertSame([], Q::dataPorts($voice['ntds']), 'voice ports are never offered for a data service');
    }

    public function test_technology_grouping_and_new_install_detection(): void
    {
        foreach (['FTTN' => Q::GROUP_COPPER, 'FTTC' => Q::GROUP_COPPER, 'FTTB' => Q::GROUP_COPPER, 'FTTP' => Q::GROUP_NTD, 'HFC' => Q::GROUP_NTD,
                  'Fixed Wireless' => Q::GROUP_NTD, 'FW' => Q::GROUP_NTD, 'Satellite' => Q::GROUP_OTHER, '' => Q::GROUP_OTHER] as $tech => $group) {
            $this->assertSame($group, Q::group($tech), $tech);
        }
        // The records Vocus returns win over the type string.
        $this->assertSame(Q::GROUP_COPPER, Q::group('FTTB', true, false));
        $this->assertSame(Q::GROUP_NTD, Q::group(null, false, true));

        $this->assertTrue(Q::fromParams(['ServiceType' => 'FTTP'])['needs_ntd_install']);
        $this->assertFalse(Q::fromParams(['ServiceType' => 'FTTC'])['needs_ntd_install']);
        $this->assertSame('FTTC', Q::serviceClassInfo('33')['label']);
        $this->assertSame('HFC', Q::serviceClassInfo('21')['label']);
        $this->assertNull(Q::serviceClassInfo('15'));
    }

    public function test_client_qualify_returns_normalised_qualification(): void
    {
        $this->fakeClient([$this->fttpQualify()]);
        $result = app(VocusWsmClient::class)->qualifyAddress('LOC001122334455');

        $this->assertSame('NTD', $result['qualification']['technology_group']);
        $this->assertCount(4, $result['qualification']['ntds'][0]['ports']);
        $this->assertIsArray($result['params']['NBNPortRecord'][0], 'legacy params stay decoded');
    }

    // ---------------------------------------------------------------- qualify endpoint / views

    public function test_qualify_endpoint_returns_qualification_and_token(): void
    {
        $this->fakeClient([$this->fttpQualify()]);
        [$token, $json] = $this->qualify($this->admin(), $this->fttpQualify());

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $token);
        $this->assertSame('NTD', $json['qualification']['technology_group']);
        $this->assertSame('FTTP', $json['service_type']);
    }

    public function test_order_form_lists_ports_with_in_use_ports_disabled_and_requires_a_choice(): void
    {
        $this->plan();
        $admin = $this->admin();
        $this->fakeClient([$this->fttpQualify()]);
        [$token] = $this->qualify($admin, $this->fttpQualify());

        $html = $this->actingAs($admin)->get(route('admin.services.internet.order', [
            'directory_id' => 'LOC001122334455', 'service_type' => 'FTTP', 'location_reference' => '3000', 'qualification_token' => $token,
        ]))->assertOk()->assertSee('Select line / port')->assertSee('Order a new line / port')->assertSee('In use')->assertSee('Free')->getContent();

        // Ports 1 and 3 are in use: their radios are disabled; 2 and 4 are selectable.
        $this->assertMatchesRegularExpression('/data-port="1"[^>]*\sdisabled/', $html);
        $this->assertMatchesRegularExpression('/data-port="3"[^>]*\sdisabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-port="2"[^>]*\sdisabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-port="4"[^>]*\sdisabled/', $html);
        $this->assertStringContainsString('data-wire="1-UNID-2"', $html);
    }

    public function test_transfer_form_has_no_new_line_option(): void
    {
        $this->plan();
        $admin = $this->admin();
        $this->fakeClient([$this->fttpQualify()]);
        [$token] = $this->qualify($admin, $this->fttpQualify());

        $this->actingAs($admin)->get(route('admin.services.internet.transfer', [
            'directory_id' => 'LOC001122334455', 'service_type' => 'FTTP', 'location_reference' => '3000', 'qualification_token' => $token,
        ]))->assertOk()->assertSee('Select line / port')->assertDontSee('Order a new line');
    }

    public function test_single_free_port_and_single_copper_pair_are_preselected_but_several_are_not(): void
    {
        $this->plan();
        $admin = $this->admin();

        $this->fakeClient([$this->fttpQualify(1, [])]);
        [$token] = $this->qualify($admin, $this->fttpQualify(1, []));
        $this->actingAs($admin)->get(route('admin.services.internet.order', ['directory_id' => 'LOC001122334455', 'service_type' => 'FTTP', 'qualification_token' => $token]))
            ->assertSee('name="uni_port" id="lc-port" value="1"', false);

        $this->fakeClient([$this->response([['Result', 'PASS'], ['ServiceType', 'FTTC'], ['CopperPairRecord', $this->pair('CPI1')], ['CopperPairRecord', $this->pair('CPI2')]])]);
        [$token2] = $this->qualify($admin, $this->fttpQualify());
        $this->actingAs($admin)->get(route('admin.services.internet.order', ['directory_id' => 'LOC001122334455', 'service_type' => 'FTTC', 'qualification_token' => $token2]))
            ->assertSee('name="copper_pair_id" id="lc-copper" value=""', false)->assertSee('Network co-exist');
    }

    public function test_installation_note_only_when_an_ntd_install_is_needed(): void
    {
        $this->plan();
        $admin = $this->admin();
        $this->fakeClient([$this->response([['Result', 'PASS'], ['ServiceType', 'FTTP']])]);
        [$token] = $this->qualify($admin, $this->fttpQualify());

        // The note is in the markup either way (it is shown by script); the JSON flag decides.
        $this->assertTrue(Q::fromParams(['ServiceType' => 'FTTP'])['needs_ntd_install']);
        $this->actingAs($admin)->get(route('admin.services.internet.order', ['directory_id' => 'LOC001122334455', 'service_type' => 'FTTP', 'qualification_token' => $token]))
            ->assertSee('No NTD or ports were returned')->assertSee('installation appointment may be required');
    }

    // ---------------------------------------------------------------- Create payload

    public function test_copper_order_sends_copper_pair_id_and_requires_a_choice_when_several(): void
    {
        $admin = $this->admin();
        $soap = $this->fakeClient([
            $this->response([['Result', 'PASS'], ['ServiceType', 'FTTC'], ['CopperPairRecord', $this->pair('CPI1')], ['CopperPairRecord', $this->pair('CPI2')]]),
            $this->ok(),
        ]);
        [$token] = $this->qualify($admin, $this->fttpQualify());
        $base = $this->order(['service_type' => 'FTTC', 'qualification_token' => $token]);

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base)->assertSessionHasErrors('copper_pair_id');
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base + ['line_choice' => 'existing', 'copper_pair_id' => 'CPI999'])->assertSessionHasErrors('copper_pair_id');
        $this->assertCount(1, $soap->calls, 'nothing is sent to Vocus for an invalid choice');

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base + ['line_choice' => 'existing', 'copper_pair_id' => 'CPI2'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('<std:Param id="CopperPairID">CPI2</std:Param>', $this->xmlOf($soap, 1));
        $this->assertSame('CPI2', InternetService::firstOrFail()->copper_pair_id);
    }

    public function test_ntd_order_sends_configured_port_param_and_stores_ntd_and_port(): void
    {
        $admin = $this->admin();
        $soap = $this->fakeClient([$this->fttpQualify(), $this->ok()]);
        [$token] = $this->qualify($admin, $this->fttpQualify());
        $base = $this->order(['service_type' => 'FTTP', 'qualification_token' => $token]);

        // No choice with free ports on offer, an in-use port and a voice/unknown port are all refused.
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base)->assertSessionHasErrors('uni_port');
        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base + ['line_choice' => 'existing', 'ntd_id' => 'NTD000111222', 'uni_port' => 1])->assertSessionHasErrors('uni_port');
        $this->assertCount(1, $soap->calls);

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base + ['line_choice' => 'existing', 'ntd_id' => 'NTD000111222', 'uni_port' => 2])->assertSessionHasNoErrors();
        $xml = $this->xmlOf($soap, 1);
        $this->assertStringContainsString('<std:Param id="DataPortID">1-UNID-2</std:Param>', $xml);
        $this->assertStringNotContainsString('CopperPairID', $xml);
        $svc = InternetService::firstOrFail();
        $this->assertSame('NTD000111222', $svc->ntd_id);
        $this->assertSame('UNI-D-2', $svc->uni_port);
        $this->assertSame('3', $svc->service_class);
    }

    public function test_port_param_name_format_and_off_switch_are_configurable(): void
    {
        config(['services.vocus.order_port_param' => 'NBNPortID', 'services.vocus.order_port_format' => '{ntd_id}:{port_name}']);
        $r = VocusLineSelection::resolve(Q::fromParams(['ServiceType' => 'FTTP', 'NBNPortRecord' => array_column($this->portRecords(2, []), 1)]), 'FTTP',
            ['line_choice' => 'existing', 'ntd_id' => 'NTD000111222', 'uni_port' => '2'], false);
        $this->assertSame(['NBNPortID' => 'NTD000111222:UNI-D 2'], $r['params']);

        config(['services.vocus.send_port_param' => false]);
        $r = VocusLineSelection::resolve(Q::fromParams(['ServiceType' => 'FTTP', 'NBNPortRecord' => array_column($this->portRecords(2, []), 1)]), 'FTTP',
            ['line_choice' => 'existing', 'ntd_id' => 'NTD000111222', 'uni_port' => '2'], false);
        $this->assertSame([], $r['params']);
        $this->assertSame('UNI-D-2', $r['record']['uni_port'], 'the choice is still stored locally');
    }

    public function test_new_line_omits_line_and_port_params_and_is_refused_for_transfers(): void
    {
        $admin = $this->admin();
        $soap = $this->fakeClient([$this->fttpQualify(), $this->ok()]);
        [$token] = $this->qualify($admin, $this->fttpQualify());
        $base = $this->order(['service_type' => 'FTTP', 'qualification_token' => $token]);

        $this->actingAs($admin)->post(route('admin.services.internet.order.store'), $base + ['line_choice' => 'new'])->assertSessionHasNoErrors();
        $xml = $this->xmlOf($soap, 1);
        foreach (['DataPortID', 'CopperPairID', 'NBNNewLine'] as $absent) {
            $this->assertStringNotContainsString($absent, $xml);
        }

        $this->actingAs($admin)->post(route('admin.services.internet.transfer.store'), $base + ['line_choice' => 'new', 'ca_date' => now()->format('Y-m-d')])
            ->assertSessionHasErrors('line_choice');
        $this->assertCount(2, $soap->calls);
    }

    public function test_transfer_with_a_chosen_port_sends_the_port_param(): void
    {
        $admin = $this->admin();
        $soap = $this->fakeClient([$this->fttpQualify(), $this->ok()]);
        [$token] = $this->qualify($admin, $this->fttpQualify());

        $this->actingAs($admin)->post(route('admin.services.internet.transfer.store'), $this->order(['service_type' => 'FTTP', 'qualification_token' => $token,
            'line_choice' => 'existing', 'ntd_id' => 'NTD000111222', 'uni_port' => 4, 'ca_date' => now()->format('Y-m-d')]))->assertSessionHasNoErrors();
        $xml = $this->xmlOf($soap, 1);
        $this->assertStringContainsString('<std:Param id="OrderType">TRANSFER</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="DataPortID">1-UNID-4</std:Param>', $xml);
    }

    public function test_qualification_for_another_address_is_not_used(): void
    {
        $admin = $this->admin();
        $this->fakeClient([$this->fttpQualify(), $this->ok()]);
        [$token] = $this->qualify($admin, $this->fttpQualify());

        // Different directory id: the cached result is ignored, so an in-use port number is not validated (fallback path).
        $this->actingAs($admin)->get(route('admin.services.internet.order', ['directory_id' => 'LOC999999999999', 'service_type' => 'FTTP', 'qualification_token' => $token]))
            ->assertOk()->assertDontSee('Select line / port');
    }
}
