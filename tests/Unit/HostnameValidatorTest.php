<?php

namespace Tests\Unit;

use App\Support\Https\HostnameValidator as H;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostnameValidatorTest extends TestCase
{
    #[DataProvider('good')]
    public function test_valid_hostnames(string $h, bool $wild = false): void
    {
        $this->assertSame($h, H::hostname($h, $wild));
    }

    public static function good(): array
    {
        return [['dash.example.com'], ['a-b.example.com.au'], ['xn--bcher-kva.example'], ['*.example.com', true], [str_repeat('a', 63) . '.example.com']];
    }

    #[DataProvider('bad')]
    public function test_invalid_hostnames(string $h): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::hostname($h);
    }

    public static function bad(): array
    {
        return [[''], ['localhost'], ['Dash.Example.com'], ['a b.example.com'], ['-a.example.com'], ['a-.example.com'], ['a..example.com'],
            ['example.com;rm -rf /'], ['$(id).example.com'], ['1.2.3.4'], ['*.example.com'], ['foo.*.example.com'], ['a.example.123'],
            [str_repeat('a', 64) . '.example.com'], [str_repeat('a.', 130) . 'com'], ['../etc.passwd.com'], ["a.example.com\n"]];
    }

    public function test_wildcard_only_leading_even_when_allowed(): void
    {
        $this->assertFalse(H::isValidHostname('a.*.example.com', true));
        $this->assertFalse(H::isValidHostname('*.*.example.com', true));
    }

    public function test_emails(): void
    {
        $this->assertSame('ops@example.com', H::email('ops@example.com'));
        foreach (['', 'a@b', '-x@example.com', 'a b@example.com', 'a@example.com;id', "a@example.com\nBcc: x@y.com", '"x"@example.com', str_repeat('a', 65) . '@example.com'] as $bad) {
            try {
                H::email($bad);
                $this->fail("accepted: {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_covers_is_wildcard_aware(): void
    {
        $this->assertTrue(H::covers('a.example.com', ['*.example.com']));
        $this->assertFalse(H::covers('a.b.example.com', ['*.example.com']));
        $this->assertFalse(H::covers('example.com', ['*.example.com']));
        $this->assertTrue(H::covers('*.example.com', ['*.example.com']));
        $this->assertFalse(H::covers('*.example.com', ['a.example.com']));
        $this->assertFalse(H::covers('evilexample.com', ['*.example.com']));
    }

    public function test_cert_name_is_path_safe(): void
    {
        $this->assertSame('wildcard.example.com', H::certName('*.example.com'));
        $this->assertSame('dash.example.com', H::certName('dash.example.com'));
    }

    public function test_acme_validation_token(): void
    {
        $this->assertSame(str_repeat('a', 43), H::acmeValidation(str_repeat('a', 43)));
        foreach (['short', str_repeat('a', 43) . ' x', str_repeat('a', 43) . '"', str_repeat('a', 200)] as $bad) {
            try {
                H::acmeValidation($bad);
                $this->fail('accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
