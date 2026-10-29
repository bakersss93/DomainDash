<?php

namespace Tests\Unit\Services;

require_once __DIR__ . '/../../Support/soap_polyfill.php';

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
}
