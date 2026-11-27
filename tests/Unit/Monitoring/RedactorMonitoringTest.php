<?php

namespace Tests\Unit\Monitoring;

use App\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorMonitoringTest extends TestCase
{
    public function test_credential_headers_show_a_prefix_only_or_nothing(): void
    {
        $out = Redactor::maskHeaders([
            'x-api-key' => 'ITG.74742abcdefghijklmnop',
            'X-Api-Key' => 'short',
            'Authorization' => 'Bearer abc.def.ghi',
            'Cookie' => 'session=abcdef',
            'Accept' => 'application/json',
        ]);

        $this->assertSame('ITG.7474…', $out['x-api-key']);
        $this->assertSame('[REDACTED]', $out['X-Api-Key']);
        $this->assertSame('Bearer [REDACTED]', $out['Authorization']);
        $this->assertSame('[REDACTED]', $out['Cookie']);
        $this->assertSame('application/json', $out['Accept']);
    }

    public function test_response_headers_are_an_allow_list(): void
    {
        $out = Redactor::filterResponseHeaders(['Content-Type' => ['application/json'], 'Set-Cookie' => ['a=b'], 'X-Internal' => ['1'], 'Retry-After' => ['30']]);

        $this->assertSame(['Content-Type' => 'application/json', 'Retry-After' => '30'], $out);
    }

    public function test_urls_lose_credentials_and_secret_query_values(): void
    {
        $this->assertSame(
            'https://api.example.test/v2?key=[REDACTED]&domain=a.com&page=2',
            Redactor::stripUrl('https://user:pass@api.example.test/v2?key=SECRET&domain=a.com&page=2')
        );
    }

    public function test_xml_secrets_are_masked_by_element_name_and_param_id(): void
    {
        $xml = '<Req><AccessKey>abc</AccessKey><ns:apiKey>k</ns:apiKey><std:Param id="PPPoEPassword">pw</std:Param><Name>Visible</Name></Req>';
        $out = Redactor::redactXml($xml);

        $this->assertStringNotContainsString('>abc<', $out);
        $this->assertStringNotContainsString('>k<', $out);
        $this->assertStringNotContainsString('>pw<', $out);
        $this->assertStringContainsString('<Name>Visible</Name>', $out);
    }

    public function test_text_fallback_masks_pairs_without_breaking_json_structure(): void
    {
        $out = Redactor::redactText("client_secret=abc&x=1\n{\"password\": \"pw\", \"nested\": {\"token\": \"t\"}, \"name\": \"ok\"}");

        $this->assertStringNotContainsString('abc', $out);
        $this->assertStringNotContainsString('"pw"', $out);
        $this->assertStringContainsString('"name": "ok"', $out);
        $this->assertNotNull(json_decode(substr($out, strpos($out, '{', 5)), true));
    }

    public function test_pii_masking(): void
    {
        $out = Redactor::maskPii(['contact' => ['email' => 'jane@example.com', 'mobile' => '0412 345 678', 'name' => 'Jane']]);

        $this->assertSame('j***@example.com', $out['contact']['email']);
        $this->assertStringEndsWith('678', $out['contact']['mobile']);
        $this->assertStringNotContainsString('0412', $out['contact']['mobile']);
        $this->assertSame('Jane', $out['contact']['name']);
    }
}
