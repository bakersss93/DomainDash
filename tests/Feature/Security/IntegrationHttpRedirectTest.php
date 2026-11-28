<?php

namespace Tests\Feature\Security;

use App\Services\Integrations\IntegrationHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationHttpRedirectTest extends TestCase
{
    public function test_integration_requests_do_not_follow_redirects(): void
    {
        Http::fake([
            'api.vendor.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            '169.254.169.254/*' => Http::response('SECRET', 200),
        ]);

        $res = IntegrationHttp::pending('https://api.vendor.example', ['X-Api-Key' => 'k'])->get('/x');

        $this->assertSame(302, $res->status());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_pending_request_is_configured_without_redirects(): void
    {
        $opts = IntegrationHttp::pending('https://api.vendor.example')->getOptions();
        $this->assertFalse($opts['allow_redirects']);
    }
}
