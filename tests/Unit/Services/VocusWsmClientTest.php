<?php

namespace Tests\Unit\Services;

require_once __DIR__ . '/../../Support/soap_polyfill.php';

use App\Services\Vocus\VocusConfigurationException;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use App\Services\Vocus\VocusWsmException;
use Illuminate\Support\Facades\Cache;
use SoapFault;
use Tests\Support\FakeSoap;
use Tests\TestCase;

class VocusWsmClientTest extends TestCase
{
    private int $logins = 0;
    private array $sleeps = [];

    private function fault(string $code, string $tx = 'TX1'): SoapFault
    {
        $detail = (object) ['FaultResponse' => (object) ['TransactionID' => $tx, 'ErrorCode' => $code]];

        return new SoapFault('env:Server', 'Some message', null, $detail);
    }

    private function client(FakeSoap $soap): VocusWsmClient
    {
        Cache::flush();
        $this->logins = 0;
        $this->sleeps = [];

        $client = new VocusWsmClient(
            fn () => $soap,
            ['access_key' => 'AK', 'cert_path' => 'c.p12', 'environment' => 'sandbox'],
            function () {
                $this->logins++;

                return 'SID' . $this->logins;
            }
        );

        // Fake clock/sleeper: sleeping advances time, nothing really waits.
        $now = 1000.0;
        $client->setRateLimiter(new VocusRateLimiter(
            4, 10, 15,
            function (float $s) use (&$now) { $this->sleeps[] = $s; $now += $s; },
            function () use (&$now) { return $now; },
            'test_rate_' . uniqid()
        ));

        return $client;
    }

    private function ok(): object
    {
        return (object) ['TransactionID' => 'T1', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => [
            (object) ['id' => 'ServiceStatus', '_' => 'ACTIVE'],
        ]]];
    }

    public function test_fault_error_code_and_transaction_id_parsed_from_nested_detail(): void
    {
        $client = $this->client(new FakeSoap());
        $f = $client->extractFault($this->fault('WSM-4220', 'ABC'));

        $this->assertSame('WSM-4220', $f['code']);
        $this->assertSame('ABC', $f['transaction_id']);
    }

    public function test_fault_parsed_from_string_detail_and_missing_code(): void
    {
        $client = $this->client(new FakeSoap());
        $xml = '<FaultResponse><TransactionID>Z9</TransactionID><ErrorCode>WSM-4080</ErrorCode></FaultResponse>';
        $this->assertSame('WSM-4080', $client->extractFault(new SoapFault('s', 'm', null, $xml))['code']);
        $this->assertNull($client->extractFault(new SoapFault('s', 'm'))['code']);
    }

    public function test_session_expiry_4005_relogs_in_and_retries_once(): void
    {
        $soap = new FakeSoap([$this->fault('WSM-4005'), $this->ok()]);
        $client = $this->client($soap);

        $res = $client->getService('SVC1');

        $this->assertSame('ACTIVE', $res['params']['ServiceStatus']);
        $this->assertCount(2, $soap->calls);
        $this->assertSame(2, $this->logins, 'a fresh session must be created after WSM-4005');
    }

    public function test_second_4005_is_not_retried_forever(): void
    {
        $soap = new FakeSoap([$this->fault('WSM-4005'), $this->fault('WSM-4005')]);
        $client = $this->client($soap);

        try {
            $client->getService('SVC1');
            $this->fail('expected exception');
        } catch (VocusWsmException $e) {
            $this->assertSame('WSM-4005', $e->errorCode);
            $this->assertSame('TX1', $e->transactionId);
        }
        $this->assertCount(2, $soap->calls);
    }

    public function test_rate_limit_fault_4080_waits_and_retries_once(): void
    {
        $soap = new FakeSoap([$this->fault('WSM-4080'), $this->ok()]);
        $client = $this->client($soap);

        $client->getService('SVC1');

        $this->assertContains(10.0, array_map('floatval', $this->sleeps));
        $this->assertCount(2, $soap->calls);
    }

    public function test_other_fault_becomes_typed_exception_with_code(): void
    {
        $client = $this->client(new FakeSoap([$this->fault('WSM-4220')]));

        try {
            $client->getService('SVC1');
            $this->fail('expected exception');
        } catch (VocusWsmException $e) {
            $this->assertSame('WSM-4220', $e->errorCode);
            $this->assertStringContainsString('WSM-4220', $e->getMessage());
        }
    }

    public function test_rate_limiter_allows_four_per_ten_seconds_then_sleeps(): void
    {
        Cache::flush();
        $now = 0.0;
        $sleeps = [];
        $limiter = new VocusRateLimiter(
            4, 10, 15,
            function (float $s) use (&$now, &$sleeps) { $sleeps[] = $s; $now += $s; },
            function () use (&$now) { return $now; },
            'rl_' . uniqid()
        );

        for ($i = 0; $i < 4; $i++) {
            $limiter->acquire();
        }
        $this->assertSame([], $sleeps);

        $limiter->acquire(); // fifth must wait for the window to slide
        $this->assertNotEmpty($sleeps);
        $this->assertGreaterThanOrEqual(10.0, $now);
    }

    public function test_rate_limiter_gives_up_after_max_wait(): void
    {
        Cache::flush();
        $now = 0.0;
        $limiter = new VocusRateLimiter(
            1, 100, 5,
            function (float $s) use (&$now) { $now += $s; },
            function () use (&$now) { return $now; },
            'rl_' . uniqid()
        );
        $limiter->acquire();

        $this->expectException(VocusWsmException::class);
        $limiter->acquire();
    }

    public function test_poll_cancel_does_not_resolve_to_active(): void
    {
        // SUCCESS of a cancel transaction: live record says INACTIVE.
        $this->assertSame('INACTIVE', VocusWsmClient::resolveServiceStatus('SUCCESS', 'inactive'));
        // No live status available: leave the stored status untouched.
        $this->assertNull(VocusWsmClient::resolveServiceStatus('SUCCESS', null));
        // Non-final / failed transactions never change status.
        $this->assertNull(VocusWsmClient::resolveServiceStatus('FAILED', 'ACTIVE'));
        $this->assertNull(VocusWsmClient::resolveServiceStatus('PROCESSING', 'ACTIVE'));
    }

    public function test_transaction_result_normalised_with_fault_params(): void
    {
        $r = VocusWsmClient::normalizeTransactionResult(['params' => [
            'TransactionState' => 'Failed', 'faultcode' => 'X-1', 'faultmessage' => 'bad',
        ]]);

        $this->assertSame('FAILED', $r['state']);
        $this->assertTrue($r['final']);
        $this->assertSame('bad', $r['fault_message']);
        $this->assertFalse(VocusWsmClient::normalizeTransactionResult(['params' => ['TransactionState' => 'Processing']])['final']);
    }

    // ---------------------------------------------------------------- VW-04 / VW-09 / secrets

    private function clientWith(FakeSoap $soap, array $config): VocusWsmClient
    {
        Cache::flush();
        $this->logins = 0;

        $client = new VocusWsmClient(fn () => $soap, $config, function () {
            $this->logins++;

            return 'SID';
        });
        $client->setRateLimiter(new VocusRateLimiter(100, 10, 15, fn () => null, fn () => 1000.0, 'test_rate_' . uniqid()));

        return $client;
    }

    private function xmlOf(FakeSoap $soap, int $call = 0): string
    {
        $payload = $soap->calls[$call]['args'][0];

        return (string) ($payload->enc_value ?? $payload->data);
    }

    public function test_empty_access_key_throws_typed_exception_before_any_soap_call_or_login(): void
    {
        foreach (['', '   ', null] as $blank) {
            $soap = new FakeSoap([$this->ok()]);
            $client = $this->clientWith($soap, ['access_key' => $blank, 'cert_path' => 'c.p12']);

            try {
                $client->getService('SVC1');
                $this->fail('expected VocusConfigurationException');
            } catch (VocusConfigurationException $e) {
                $this->assertStringContainsString('Vocus Access Key is not configured (or settings could not be decrypted', $e->getMessage());
                $this->assertStringContainsString('APP_KEY', $e->getMessage());
                $this->assertTrue($e->isAccountLevel());
                $this->assertTrue($e->isConfigurationProblem());
            }

            $this->assertSame([], $soap->calls, 'no SOAP call may be made without an Access Key');
            $this->assertSame(0, $this->logins, 'no login may be attempted without an Access Key');
        }
    }

    public function test_configuration_problem_text(): void
    {
        $this->assertNull(VocusWsmClient::configurationProblem(['access_key' => 'K', 'cert_path' => 'vocus/c.p12']));
        $this->assertStringContainsString('Access Key is not configured', VocusWsmClient::configurationProblem(['access_key' => '']));
        $this->assertStringContainsString('certificate', VocusWsmClient::configurationProblem(['access_key' => 'K']));

        \App\Casts\EncryptedSettingValue::$decryptFailures['vocus'] = true;
        try {
            $this->assertStringContainsString('APP_KEY', VocusWsmClient::configurationProblem(['access_key' => '']));
        } finally {
            \App\Casts\EncryptedSettingValue::$decryptFailures = [];
        }
    }

    public function test_account_level_error_codes_are_flagged(): void
    {
        foreach (['WSM-4005', 'WSM-4010', 'WSM-4020', 'WSM-4050'] as $code) {
            $this->assertTrue((new VocusWsmException('x', $code))->isAccountLevel(), $code);
        }
        foreach (['WSM-4220', 'WSM-5030', 'TCAS-4300', null] as $code) {
            $this->assertFalse((new VocusWsmException('x', $code))->isAccountLevel(), (string) $code);
        }
    }

    public function test_every_documented_code_has_a_friendly_message(): void
    {
        foreach (['WSM-0000', 'WSM-4000', 'WSM-4005', 'WSM-4010', 'WSM-4020', 'WSM-4050', 'WSM-4080', 'WSM-4220', 'WSM-4221',
                  'WSM-4224', 'WSM-5000', 'WSM-5010', 'WSM-5030', 'WSM-5050', 'TCAS-4300', 'TCAS-4310'] as $code) {
            $this->assertArrayHasKey($code, VocusWsmClient::FRIENDLY_ERRORS);
        }

        $client = $this->clientWith(new FakeSoap([$this->fault('WSM-4010')]), ['access_key' => 'AK', 'cert_path' => 'c.p12']);
        try {
            $client->getService('S');
            $this->fail('expected exception');
        } catch (VocusWsmException $e) {
            $this->assertStringContainsString('Access key rejected', $e->getMessage());
        }
    }

    public function test_access_key_never_appears_in_exception_messages(): void
    {
        $leaky = new SoapFault('s', 'Rejected request with key SUPERSECRETKEY123 and password PW-HUNTER2', null,
            (object) ['FaultResponse' => (object) ['TransactionID' => 'T9', 'ErrorCode' => 'WSM-4220']]);
        $client = $this->clientWith(new FakeSoap([$leaky]), [
            'access_key' => 'SUPERSECRETKEY123', 'cert_path' => 'c.p12', 'cert_password' => 'PW-HUNTER2',
        ]);

        try {
            $client->getService('S');
            $this->fail('expected exception');
        } catch (VocusWsmException $e) {
            $this->assertStringNotContainsString('SUPERSECRETKEY123', $e->getMessage());
            $this->assertStringNotContainsString('PW-HUNTER2', $e->getMessage());
        }
    }

    public function test_faultcode_and_faultmessage_surface_from_a_failed_transaction(): void
    {
        $soap = new FakeSoap([(object) ['TransactionID' => 'TX', 'ResponseType' => 'SYNC', 'Parameters' => (object) ['Param' => [
            (object) ['id' => 'TransactionState', '_' => 'Failed'],
            (object) ['id' => 'faultcode', '_' => 'DIR-0000'],
            (object) ['id' => 'faultmessage', '_' => 'General error'],
        ]]]]);
        $client = $this->clientWith($soap, ['access_key' => 'AK', 'cert_path' => 'c.p12']);

        $r = VocusWsmClient::normalizeTransactionResult($client->pollTransaction('TX'));

        $this->assertSame('FAILED', $r['state']);
        $this->assertSame('DIR-0000', $r['fault_code']);
        $this->assertSame('General error', $r['fault_message']);
    }

    // ---------------------------------------------------------------- address lookup (production report)

    private function addressResponse(array $records): object
    {
        $params = array_map(fn ($r) => (object) ['id' => 'FibreAddressRecord', '_' => '<BroadbandAddressRecord><DirectoryID>' . $r[0]
            . '</DirectoryID><Carrier>NBN</Carrier><AddressLong>' . $r[1] . '</AddressLong></BroadbandAddressRecord>'], $records);

        return (object) ['TransactionID' => 'T1', 'ResponseType' => 'SYNC',
            'Parameters' => (object) ['Param' => count($params) === 1 ? $params[0] : $params]];
    }

    private function emptyResponse(): object
    {
        return (object) ['TransactionID' => 'T0', 'ResponseType' => 'SYNC'];
    }

    public function test_lookup_sends_the_documented_request_for_the_reported_address(): void
    {
        $soap = new FakeSoap([$this->addressResponse([['LOC000000000001', '4 CUMMINS DR SUMMERTOWN SA 5141']])]);
        $client = $this->clientWith($soap, ['access_key' => 'AK', 'cert_path' => 'c.p12']);

        // The same parameters come out of the free-text parser and of the manual form.
        $params = \App\Support\VocusAddressParser::parse('4 cummins dr, summertown sa 5141');
        $this->assertSame($params['Main.StreetName'], \App\Support\VocusAddressParser::fromStructured([
            'street_number' => '4', 'street_name' => 'Cummins', 'street_type' => 'DR', 'suburb' => 'Summertown', 'state' => 'SA', 'postcode' => '5141',
        ])['Main.StreetName']);

        $res = $client->findAddresses($params);

        $xml = $this->xmlOf($soap);
        $this->assertStringContainsString('<ProductID>DIR</ProductID>', $xml);
        $this->assertStringContainsString('<PlanID>BROADBAND</PlanID>', $xml);
        $this->assertStringNotContainsString('<Scope>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.StreetName">CUMMINS</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.StreetType">DR</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.Suburb">SUMMERTOWN</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.State">SA</std:Param>', $xml);
        $this->assertStringContainsString('<std:Param id="Main.Postcode">5141</std:Param>', $xml, 'spelling used by the Examples lookup sample');
        $this->assertStringContainsString('<std:Param id="SearchMode">DEFAULT</std:Param>', $xml);
        $this->assertStringNotContainsString('Street1stNumber', $xml, 'the documented lookup is street level; the number filters locally');

        $this->assertCount(1, $soap->calls);
        $this->assertCount(1, $res['addresses']);
        $this->assertSame('LOC000000000001', $res['addresses'][0]['directory_id']);
    }

    public function test_lookup_parses_single_and_multiple_records_and_trims_values(): void
    {
        $single = $this->clientWith(new FakeSoap([$this->addressResponse([["\n LOC1 ", 'UNIT 1  99 EXAMPLE ST']])]), ['access_key' => 'AK', 'cert_path' => 'c.p12']);
        $one = $single->lookupAddress(['Main.StreetName' => 'EXAMPLE']);
        $this->assertCount(1, $one['addresses']);
        $this->assertSame('LOC1', $one['addresses'][0]['directory_id']);
        $this->assertSame('UNIT 1 99 EXAMPLE ST', $one['addresses'][0]['address_long']);

        $multi = $this->clientWith(new FakeSoap([$this->addressResponse([
            ['LOC1', 'UNIT 1 99 EXAMPLE ST UPPER MELBOURNE QLD'], ['LOC2', 'UNIT 2 99 EXAMPLE ST UPPER MELBOURNE QLD'], ['LOC3', 'UNIT 3 99 EXAMPLE ST UPPER MELBOURNE QLD'],
        ])]), ['access_key' => 'AK', 'cert_path' => 'c.p12']);
        $this->assertCount(3, $multi->lookupAddress(['Main.StreetName' => 'EXAMPLE'])['addresses']);
    }

    public function test_lookup_tolerates_malformed_record_xml_and_other_record_param_ids(): void
    {
        [$addresses, $diag] = VocusWsmClient::parseAddressRecords([
            'FibreAddressRecord' => '<BroadbandAddressRecord><DirectoryID>LOC9</DirectoryID><AddressLong>UNIT 1 & 2 X ST</AddressLong></BroadbandAddressRecord>',
            'BroadbandAddressRecord' => '<BroadbandAddressRecord><DirectoryID>LOC10</DirectoryID><AddressLong>Y ST</AddressLong></BroadbandAddressRecord>',
            'Other' => 'ignored',
        ]);

        $this->assertSame(['LOC9', 'LOC10'], array_column($addresses, 'directory_id'));
        $this->assertSame(0, $diag['unparsed']);
    }

    public function test_lookup_retries_variants_when_nothing_is_found_and_stops_at_first_hit(): void
    {
        $soap = new FakeSoap([$this->emptyResponse(), $this->emptyResponse(), $this->addressResponse([['LOC5', '4 CUMMINS DR SUMMERTOWN SA 5141']])]);
        $client = $this->clientWith($soap, ['access_key' => 'AK', 'cert_path' => 'c.p12']);

        $res = $client->findAddresses(\App\Support\VocusAddressParser::parse('4 cummins dr, summertown sa 5141'));

        $this->assertCount(3, $soap->calls);
        $this->assertStringContainsString('<std:Param id="Main.Street1stNumber">4</std:Param>', $this->xmlOf($soap, 1), '2nd attempt sends the number');
        $this->assertStringContainsString('id="Main.PostCode"', $this->xmlOf($soap, 2), '3rd attempt uses the alternate postcode spelling');
        $this->assertCount(3, $res['attempts']);
        $this->assertSame('LOC5', $res['addresses'][0]['directory_id']);
    }

    public function test_lookup_reports_no_records_after_every_variant_and_raises_a_fault_only_if_all_faulted(): void
    {
        $soap = new FakeSoap([$this->emptyResponse(), $this->emptyResponse(), $this->emptyResponse(), $this->emptyResponse()]);
        $client = $this->clientWith($soap, ['access_key' => 'AK', 'cert_path' => 'c.p12']);
        $res = $client->findAddresses(\App\Support\VocusAddressParser::parse('4 cummins dr, summertown sa 5141'));
        $this->assertSame([], $res['addresses']);
        $this->assertCount(4, $res['attempts']);

        $client = $this->clientWith(new FakeSoap([$this->fault('WSM-4220'), $this->fault('WSM-4220'), $this->fault('WSM-4220'), $this->fault('WSM-4220')]),
            ['access_key' => 'AK', 'cert_path' => 'c.p12']);
        $this->expectException(VocusWsmException::class);
        $client->findAddresses(\App\Support\VocusAddressParser::parse('4 cummins dr, summertown sa 5141'));
    }

    public function test_lookup_narrows_street_level_results_by_number_and_unit(): void
    {
        $all = [
            ['directory_id' => 'A', 'carrier' => 'NBN', 'address_long' => 'UNIT 1 99 EXAMPLE ST X QLD'],
            ['directory_id' => 'B', 'carrier' => 'NBN', 'address_long' => 'UNIT 2 99 EXAMPLE ST X QLD'],
            ['directory_id' => 'C', 'carrier' => 'NBN', 'address_long' => '4 EXAMPLE ST X QLD'],
            ['directory_id' => 'D', 'carrier' => 'NBN', 'address_long' => '14 EXAMPLE ST X QLD'],
        ];

        $this->assertSame(['C'], array_column(VocusWsmClient::refineAddresses($all, ['Main.Street1stNumber' => 4]), 'directory_id'));
        $this->assertSame(['B'], array_column(VocusWsmClient::refineAddresses($all, ['Main.Street1stNumber' => 99, 'Main.Unit1stNumber' => 2]), 'directory_id'));
    }

    public function test_alias_key_is_sent_but_empty_alias_is_not(): void
    {
        $soap = new FakeSoap([$this->ok()]);
        $this->clientWith($soap, ['access_key' => 'AK', 'alias_key' => '', 'cert_path' => 'c.p12'])->getService('S');
        $this->assertStringNotContainsString('<AliasKey>', $this->xmlOf($soap));
    }
}
