<?php

namespace Tests\Unit;

use App\Services\Vocus\VocusWsmClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VocusWsmClientTest extends TestCase
{
    public function test_request_xml_matches_the_wsm_schema_namespaces(): void
    {
        $client = new TestableVocusWsmClient;

        $xml = $client->requestXml('Get', 'FIBRE', ['ServiceID' => 'A&B'], null, 'SERVICE');

        $document = simplexml_load_string($xml);
        self::assertNotFalse($document);
        self::assertSame('GetRequest', $document->getName());
        self::assertSame(VocusWsmClient::NS_WSM, $document->getNamespaces()['']);
        self::assertStringContainsString('<Parameters><std:Param id="ServiceID">A&amp;B</std:Param></Parameters>', $xml);
    }

    public function test_notifications_use_the_documented_start_datetime_parameter(): void
    {
        $client = new TestableVocusWsmClient;

        $client->getNotifications('20260819093000');

        self::assertSame(['Get', 'FIBRE', ['StartDateTime' => '20260819093000'], null, 'NOTIFICATIONS'], $client->lastCall);
    }

    public function test_notifications_reject_an_invalid_vocus_timestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TestableVocusWsmClient)->getNotifications('2026-08-19');
    }

    public function test_auth_log_uses_oper_auth_log_plan_and_usage_daily_scope(): void
    {
        $client = new TestableVocusWsmClient;

        $client->getAuthLog('SERVICE-1', 'FIBRE');

        self::assertSame(
            ['Get', 'OPER', ['ServiceID' => 'SERVICE-1', 'ProductID' => 'FIBRE'], 'AUTH-LOG', 'USAGE-DAILY'],
            $client->lastCall
        );
    }
}

class TestableVocusWsmClient extends VocusWsmClient
{
    public array $lastCall = [];

    public function __construct()
    {
        $this->config = ['access_key' => 'ACCESS-KEY'];
    }

    public function requestXml(string $operation, string $productId, array $params, ?string $planId, ?string $scope): string
    {
        return $this->buildRequestXml($operation, $productId, $params, $planId, $scope);
    }

    protected function call(string $operation, string $productId, array $params = [], ?string $planId = null, ?string $scope = null): array
    {
        $this->lastCall = [$operation, $productId, $params, $planId, $scope];

        return ['transaction_id' => null, 'response_type' => 'SYNC', 'params' => []];
    }
}
