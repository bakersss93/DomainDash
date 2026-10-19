<?php

namespace Tests\Feature\Security;

use App\Support\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function xssPayloads(): array
    {
        return [
            'script tag' => ['<p>hi</p><script>alert(1)</script>'],
            'img slash onerror' => ['<img/src=x/onerror=alert(1)>'],
            'img onerror' => ['<img src="x" onerror="alert(1)">'],
            'unquoted javascript href' => ['<a href=javascript:alert(1)>click</a>'],
            'quoted javascript href' => ['<a href="javascript:alert(1)">click</a>'],
            'entity encoded javascript href' => ['<a href="&#106;avascript:alert(1)">click</a>'],
            'data uri img' => ['<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">'],
            'svg onload' => ['<svg onload=alert(1)></svg>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'style attr' => ['<div style="background:url(javascript:alert(1))">x</div>'],
            'onclick' => ['<p onclick="alert(1)">x</p>'],
            'form' => ['<form action="https://evil.example"><input name=a></form>'],
        ];
    }

    /** @dataProvider xssPayloads */
    #[\PHPUnit\Framework\Attributes\DataProvider('xssPayloads')]
    public function test_xss_payloads_are_neutralised(string $payload): void
    {
        $out = strtolower(HtmlSanitizer::clean($payload));

        foreach (['<script', 'onerror', 'onload', 'onclick', 'javascript:', '<iframe', '<svg', '<form', 'data:text', 'style='] as $needle) {
            $this->assertStringNotContainsString($needle, $out, "payload leaked '{$needle}': {$out}");
        }
    }

    public function test_plain_text_content_survives(): void
    {
        $this->assertStringContainsString('Hello world', HtmlSanitizer::clean('<p>Hello <strong>world</strong></p>'));
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', HtmlSanitizer::clean('   '));
    }
}
