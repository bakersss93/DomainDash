<?php

namespace Tests\Feature\Monitoring;

require_once __DIR__ . '/../../Support/soap_polyfill.php';

use App\Models\IntegrationRequest;
use App\Services\Integrations\IntegrationHttp;
use App\Services\Ip2whois\Ip2whoisClient;
use App\Services\Monitoring\IntegrationRequestLogger;
use App\Services\Synergy\SynergyApiException;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeSoap;
use Tests\Support\MonitoringHelpers;
use Tests\TestCase;

class OutboundLoggingTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    private const KEY = 'ITG.7474abcdefghijklmnopqrstuvwxyz';

    private function itglueCall(array $body = ['name' => 'Example Pty Ltd']): void
    {
        IntegrationHttp::pending('', ['x-api-key' => self::KEY, 'Authorization' => 'Bearer abc.def.ghi'], 'itglue')
            ->post('https://api.itglue.com/organizations?api_key=querysecret&page=2', $body);
    }

    public function test_errors_only_is_the_default_and_successful_calls_are_not_stored(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->itglueCall();

        $this->assertSame(0, IntegrationRequest::count());
    }

    public function test_failed_call_is_stored_even_in_errors_only_mode(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['title' => 'Boom']]], 500)]);

        $this->itglueCall();

        $row = IntegrationRequest::sole();
        $this->assertTrue($row->is_error);
        $this->assertSame(500, $row->response_status);
        $this->assertSame('itglue', $row->provider);
        $this->assertSame('outbound', $row->direction);
        $this->assertStringContainsString('HTTP 500', $row->error_message);
    }

    public function test_all_level_stores_every_call_with_masked_headers_redacted_body_and_clean_url(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        Http::fake(['*' => Http::response(['data' => ['id' => 1], 'access_token' => 'tok-secret-123'], 200)]);

        $this->itglueCall(['name' => 'Example Pty Ltd', 'password' => 'hunter2', 'contact_email' => 'jane@example.com']);

        $row = IntegrationRequest::sole();
        $this->assertFalse($row->is_error);
        $this->assertSame('ITG.7474…', $row->request_headers['x-api-key']);
        $this->assertSame('Bearer [REDACTED]', $row->request_headers['Authorization']);
        $this->assertStringNotContainsString('querysecret', $row->url);
        $this->assertStringContainsString('page=2', $row->url);

        $request = json_decode($row->request_body, true);
        $this->assertSame('Example Pty Ltd', $request['name']);
        $this->assertSame('[REDACTED]', $request['password']);
        $this->assertSame('j***@example.com', $request['contact_email']);
        $this->assertStringContainsString("\n", $row->request_body, 'bodies are stored pretty-printed');

        $this->assertStringNotContainsString('tok-secret-123', $row->response_body);
        $this->assertStringNotContainsString('hunter2', json_encode($row->toArray()));
        $this->assertStringNotContainsString(self::KEY, json_encode($row->toArray()));
    }

    public function test_form_encoded_token_requests_never_store_the_client_secret(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        Http::fake(['*' => Http::response(['access_token' => 'halo-token-xyz', 'expires_in' => 3600], 200)]);

        IntegrationHttp::pending('', [], 'halo')->asForm()->post('https://halo.example.test/auth/token?tenant=acme', [
            'grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => 'halo-client-secret', 'scope' => 'all',
        ]);

        $row = IntegrationRequest::sole();
        $this->assertStringNotContainsString('halo-client-secret', json_encode($row->toArray()));
        $this->assertStringNotContainsString('halo-token-xyz', json_encode($row->toArray()));
        $this->assertSame('[REDACTED]', json_decode($row->request_body, true)['client_secret']);
    }

    public function test_large_bodies_are_truncated(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        $limit = (int) config('monitoring.body_limit');
        Http::fake(['*' => Http::response(['rows' => array_fill(0, 20000, 'lorem ipsum dolor sit amet')], 200)]);

        IntegrationHttp::pending('', [], 'halo')->get('https://halo.example.test/api/Asset');

        $row = IntegrationRequest::sole();
        $this->assertTrue($row->response_truncated);
        $this->assertLessThanOrEqual($limit + 100, strlen($row->response_body));
    }

    public function test_connection_failures_are_logged_without_leaking_query_secrets(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout for https://api.ip2whois.com/v2?key=querysecret&domain=a.com');
        });

        try {
            IntegrationHttp::pending('', [], 'ip2whois')->get('https://api.ip2whois.com/v2', ['key' => 'querysecret', 'domain' => 'a.com']);
            $this->fail('exception expected');
        } catch (ConnectionException) {
        }

        $row = IntegrationRequest::sole();
        $this->assertTrue($row->is_error);
        $this->assertNull($row->response_status);
        $this->assertStringNotContainsString('querysecret', json_encode($row->toArray()));
        $this->assertSame('a.com', $row->reference);
    }

    public function test_ip2whois_lookups_are_recorded_with_the_key_stripped(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        \App\Models\Setting::put('ip2whois', ['api_key' => 'ip2-key-secret']);
        Http::fake(['*' => Http::response(['domain' => 'example.com', 'registrant' => ['email' => 'owner@example.com']], 200)]);

        (new Ip2whoisClient())->lookup('example.com');

        $row = IntegrationRequest::sole();
        $this->assertSame('ip2whois', $row->provider);
        $this->assertSame('example.com', $row->reference);
        $this->assertStringNotContainsString('ip2-key-secret', json_encode($row->toArray()));
    }

    public function test_logging_failure_never_breaks_the_integration_call(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        Schema::drop('integration_requests');
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $response = IntegrationHttp::pending('', [], 'halo')->get('https://halo.example.test/api/Asset');

        $this->assertTrue($response->successful());
        $this->assertSame(['ok' => true], $response->json());
    }

    public function test_calls_made_inside_a_context_carry_entity_and_reference(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        Http::fake(['*' => Http::response([], 200)]);

        app(IntegrationRequestLogger::class)->withContext(['entity_type' => 'domain', 'reference' => 'example.com'], function () {
            IntegrationHttp::pending('', [], 'halo')->get('https://halo.example.test/api/Asset');
        });

        $row = IntegrationRequest::sole();
        $this->assertSame('domain', $row->entity_type);
        $this->assertSame('example.com', $row->reference);
    }

    // ---- SOAP -------------------------------------------------------------

    private function synergy(FakeSoap $soap): SynergyWholesaleClient
    {
        return new SynergyWholesaleClient(null, fn () => $soap, ['reseller_id' => '42', 'api_key' => 'syn-api-key-secret-value'], ['wsdl' => '/tmp/none.wsdl', 'timeout' => 5]);
    }

    public function test_soap_request_xml_redacts_credentials_and_epp_codes(): void
    {
        $this->monitoring(['outbound_level' => 'all']);
        $soap = new FakeSoap([(object) ['status' => 'OK', 'domainName' => 'example.com', 'domain_password' => 'epp-secret-code']]);

        $this->synergy($soap)->domainInfo('example.com');

        $row = IntegrationRequest::sole();
        $this->assertSame('synergy', $row->provider);
        $this->assertSame('SOAP', $row->method);
        $this->assertSame('domainInfo', $row->operation);
        $this->assertSame('example.com', $row->reference);
        $this->assertStringContainsString('<domainName>example.com</domainName>', $row->request_body);
        $this->assertStringContainsString('<apiKey>[REDACTED]</apiKey>', $row->request_body);
        $this->assertStringNotContainsString('syn-api-key-secret-value', json_encode($row->toArray()));
        $this->assertStringNotContainsString('epp-secret-code', json_encode($row->toArray()));
        $this->assertSame('OK', $row->response_headers['soap-status']);
    }

    public function test_soap_errors_are_logged_in_errors_only_mode_and_successes_are_not(): void
    {
        $this->synergy(new FakeSoap([(object) ['status' => 'OK']]))->domainInfo('ok.example.com');
        $this->assertSame(0, IntegrationRequest::count());

        try {
            $this->synergy(new FakeSoap([(object) ['status' => 'ERR_DOMAIN_NOT_FOUND', 'errorMessage' => 'nope']]))->domainInfo('bad.example.com');
            $this->fail('exception expected');
        } catch (SynergyApiException) {
        }

        $row = IntegrationRequest::sole();
        $this->assertTrue($row->is_error);
        $this->assertSame('nope', $row->error_message);
        $this->assertSame('bad.example.com', $row->reference);
    }

    public function test_soap_faults_are_logged(): void
    {
        try {
            $this->synergy(new FakeSoap([new \SoapFault('Server', 'Could not connect')]))->domainInfo('fault.example.com');
            $this->fail('exception expected');
        } catch (SynergyApiException) {
        }

        $row = IntegrationRequest::sole();
        $this->assertTrue($row->is_error);
        $this->assertSame(500, $row->response_status);
        $this->assertStringContainsString('Could not connect', $row->response_body);
    }

    public function test_vocus_request_xml_hides_access_key_and_secret_params(): void
    {
        $this->monitoring(['outbound_level' => 'all']);

        app(IntegrationRequestLogger::class)->logSoap(
            'vocus', 'Get', 'https://wsm.example.test/soap',
            '<GetRequest xmlns="urn:x" xmlns:std="urn:y"><AccessKey>vocus-access-key-1</AccessKey><ProductID>FIBRE</ProductID>'
            . '<Parameters><std:Param id="ServiceID">DD-1</std:Param><std:Param id="PPPoEPassword">pppoe-secret</std:Param></Parameters></GetRequest>',
            (object) ['TransactionID' => 'T1', 'ResponseType' => 'Success'], null, 12, 'DD-1', 'internet_service', 'Success'
        );

        $row = IntegrationRequest::sole();
        $dump = json_encode($row->toArray());
        $this->assertStringNotContainsString('vocus-access-key-1', $dump);
        $this->assertStringNotContainsString('pppoe-secret', $dump);
        $this->assertStringContainsString('DD-1', $row->request_body);
        $this->assertSame('vocus', $row->provider);
    }
}
