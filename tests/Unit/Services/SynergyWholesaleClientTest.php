<?php

namespace Tests\Unit\Services;

require_once __DIR__ . '/../../Support/soap_polyfill.php';

use App\Services\Synergy\SynergyApiException;
use App\Services\Synergy\SynergyAuthException;
use App\Services\Synergy\SynergyWholesaleClient;
use InvalidArgumentException;
use SoapFault;
use Tests\Support\FakeSoap;
use Tests\TestCase;

class SynergyWholesaleClientTest extends TestCase
{
    private function client(FakeSoap $soap): SynergyWholesaleClient
    {
        return new SynergyWholesaleClient(
            null,
            fn () => $soap,
            ['reseller_id' => '42', 'api_key' => 'k3y'],
            ['wsdl' => '/tmp/none.wsdl', 'timeout' => 5]
        );
    }

    public function test_err_status_throws_typed_exception(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'ERR_DOMAIN_NOT_FOUND', 'errorMessage' => 'nope']]);

        try {
            $this->client($soap)->domainInfo('a.com');
            $this->fail('expected exception');
        } catch (SynergyApiException $e) {
            $this->assertSame('ERR_DOMAIN_NOT_FOUND', $e->apiStatus);
            $this->assertSame('domainInfo', $e->operation);
            $this->assertStringContainsString('nope', $e->getMessage());
            $this->assertStringNotContainsString('k3y', $e->getMessage());
        }
    }

    public function test_login_failure_maps_to_auth_exception(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'ERR_LOGIN_FAILED', 'errorMessage' => 'x']]);

        $this->expectException(SynergyAuthException::class);
        $this->client($soap)->balanceQuery();
    }

    public function test_soap_fault_is_wrapped(): void
    {
        $soap = new FakeSoap([new SoapFault('Server', 'boom')]);

        try {
            $this->client($soap)->balanceQuery();
            $this->fail('expected exception');
        } catch (SynergyApiException $e) {
            $this->assertTrue($e->isTransportError());
            $this->assertInstanceOf(SoapFault::class, $e->getPrevious());
        }
    }

    public function test_credentials_are_injected_and_ok_returned(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK', 'balance' => '10.00']]);
        $res = $this->client($soap)->balanceQuery();

        $this->assertSame('10.00', $res['balance']);
        $this->assertSame('balanceQuery', $soap->calls[0]['op']);
        $this->assertSame('42', $soap->calls[0]['args'][0]['resellerID']);
        $this->assertSame('k3y', $soap->calls[0]['args'][0]['apiKey']);
    }

    public function test_register_uses_domainRegister_with_registrant_fields(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK']]);
        $this->client($soap)->registerDomain('example.com.au', 2, [
            'organisation' => 'Acme', 'firstname' => 'Jo', 'lastname' => 'Bloggs',
            'email' => 'jo@example.com', 'phone' => '+61.400000000', 'address' => '1 Main St',
            'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'country' => 'au',
        ], [], [
            'dnsConfig' => 5,
            'eligibilityType' => 'Company', 'eligibilityName' => 'ACME PTY LTD',
            'eligibilityID' => '17165245901', 'eligibilityIDType' => 'ABN',
        ]);

        $call = $soap->calls[0];
        $p = $call['args'][0];
        $this->assertSame('domainRegister', $call['op']);
        $this->assertSame('2', $p['years']);
        $this->assertSame(['1 Main St'], $p['registrant_address']);
        $this->assertSame('AU', $p['registrant_country']);
        $this->assertSame('Jo', $p['registrant_firstname']);
        $this->assertSame(5, $p['dnsConfig']);
        $this->assertArrayNotHasKey('nameServers', $p);
        $elig = json_decode($p['eligibility'], true);
        $this->assertSame('ABN', $elig['registrantIDType']);
        $this->assertSame('17165245901', $elig['registrantID']);
    }

    public function test_register_rejects_incomplete_contact(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new FakeSoap())->registerDomain('a.com', 1, ['email' => 'x@y.z']);
    }

    public function test_transfer_uses_authInfo_and_unprefixed_contact(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK_TRANSFER_PENDING']]);
        $res = $this->client($soap)->transferDomain([
            'domainName' => 'a.com.au', 'authInfo' => 'EPP-1', 'organisation' => 'Acme',
            'firstname' => 'Jo', 'lastname' => 'B', 'address' => ['1 Main St'], 'suburb' => 'M',
            'state' => 'VIC', 'country' => 'AU', 'postcode' => '3000', 'phone' => '+61.4', 'email' => 'a@b.c',
            'doRenewal' => false, 'idProtect' => true,
        ]);

        $p = $soap->calls[0]['args'][0];
        $this->assertSame('transferDomain', $soap->calls[0]['op']);
        $this->assertSame('EPP-1', $p['authInfo']);
        $this->assertSame('0', $p['doRenewal']);
        $this->assertSame('Y', $p['idProtect']);
        $this->assertArrayNotHasKey('authCode', $p);
        $this->assertSame('OK_TRANSFER_PENDING', $res['status']);
    }

    public function test_transfer_requires_auth_info(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new FakeSoap())->transferDomain(['domainName' => 'a.com']);
    }

    public function test_hosting_purchase_uses_hostingPurchaseService_and_planName(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK', 'hoid' => '9']]);
        $this->client($soap)->purchaseHosting('Starter', 'a.com', 'c@a.com', ['first_name' => 'Jo']);

        $this->assertSame('hostingPurchaseService', $soap->calls[0]['op']);
        $p = $soap->calls[0]['args'][0];
        $this->assertSame('Starter', $p['planName']);
        $this->assertSame('Jo', $p['first_name']);
        $this->assertArrayNotHasKey('planID', $p);
    }

    public function test_dns_update_uses_native_operation_and_does_not_delete(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK', 'id' => 'new']]);
        $this->client($soap)->updateDNSRecord('a.com', 'old', 'www', 'a', '1.2.3.4', 300);

        $this->assertCount(1, $soap->calls);
        $this->assertSame('updateDNSRecord', $soap->calls[0]['op']);
        $this->assertSame('old', $soap->calls[0]['args'][0]['recordID']);
        $this->assertSame('A', $soap->calls[0]['args'][0]['recordType']);
    }

    public function test_dns_record_type_validated_and_failed_add_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new FakeSoap())->addDNSRecord('a.com', 'www', 'SRV', 'x');
    }

    public function test_failed_dns_add_status_throws(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'ERR_VAR_EMPTY', 'errorMessage' => 'bad']]);
        $this->expectException(SynergyApiException::class);
        $this->client($soap)->addDNSRecord('a.com', 'www', 'A', '1.1.1.1');
    }

    public function test_bulk_domain_info_batches_by_100_and_normalises(): void
    {
        $names = array_map(fn ($i) => "d{$i}.com", range(1, 150));
        $soap = new FakeSoap([
            (object) ['status' => 'OK', 'domainList' => (object) ['domainName' => 'd1.com', 'nameServers' => 'ns1.x']],
            (object) ['status' => 'OK', 'domainList' => []],
        ]);

        $rows = $this->client($soap)->bulkDomainInfo($names);

        $this->assertCount(2, $soap->calls);
        $this->assertSame('bulkDomainInfo', $soap->calls[0]['op']);
        $this->assertCount(100, $soap->calls[0]['args'][0]['domainList']);
        $this->assertCount(50, $soap->calls[1]['args'][0]['domainList']);
        $this->assertSame(['ns1.x'], $rows[0]['nameServers']);
    }

    public function test_get_nameservers_and_config_wraps_domain_info(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK', 'nameServers' => ['a', 'b'], 'dnsConfig' => 5]]);
        $info = $this->client($soap)->getNameserversAndConfig('a.com');

        $this->assertSame('domainInfo', $soap->calls[0]['op']);
        $this->assertSame(['a', 'b'], $info['nameServers']);
        $this->assertSame(5, $info['dnsConfig']);
    }

    public function test_list_domains_wraps_single_result(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'OK', 'domainList' => (object) ['domainName' => 'a.com']]]);
        $res = $this->client($soap)->listDomains(1, 500);

        $this->assertIsArray($res['domainList']);
        $this->assertCount(1, $res['domainList']);
    }

    public function test_hosting_get_service_accepts_non_ok_success_status(): void
    {
        $soap = new FakeSoap([(object) ['status' => 'Active', 'domain' => 'a.com']]);
        $this->assertSame('Active', $this->client($soap)->hostingGetService('a.com')['status']);
    }

    public function test_untrusted_wsdl_setting_is_ignored(): void
    {
        $captured = null;
        $client = new SynergyWholesaleClient(
            null,
            function ($wsdl) use (&$captured) {
                $captured = $wsdl;
                return new FakeSoap([(object) ['status' => 'OK']]);
            },
            ['reseller_id' => '1', 'api_key' => 'k', 'wsdl_path' => 'http://169.254.169.254/x'],
            ['wsdl' => '/bundled.wsdl']
        );
        $client->balanceQuery();

        $this->assertSame('/bundled.wsdl', $captured);
    }
}
