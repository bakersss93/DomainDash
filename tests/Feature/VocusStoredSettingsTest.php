<?php

namespace Tests\Feature;

require_once __DIR__ . '/../Support/soap_polyfill.php';

use App\Models\Setting;
use App\Services\Vocus\VocusRateLimiter;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeSoap;
use Tests\TestCase;

class VocusStoredSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_carries_access_key_loaded_from_stored_settings(): void
    {
        Cache::flush();
        Setting::put('vocus', [
            'access_key' => 'STORED-AK',
            'alias_key' => 'ALIAS-1',
            'cert_path' => 'vocus/cert.p12',
            'environment' => 'sandbox',
        ]);

        $soap = new FakeSoap([(object) ['TransactionID' => 'T1', 'ResponseType' => 'SYNC']]);

        // No injected config: the client must load it from settings, as in production.
        $client = new VocusWsmClient(fn () => $soap, null, fn () => 'SID');
        $client->setRateLimiter(new VocusRateLimiter(4, 10, 15, fn () => null, fn () => 1000.0, 'test_rate_' . uniqid()));

        $client->getService('SVC123');

        $payload = $soap->calls[0]['args'][0];
        $xml = $payload->enc_value ?? $payload->data;

        $this->assertStringContainsString('<AccessKey>STORED-AK</AccessKey>', $xml);
        $this->assertStringContainsString('<AliasKey>ALIAS-1</AliasKey>', $xml);
    }
}
