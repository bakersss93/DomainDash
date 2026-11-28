<?php

namespace Tests\Unit;

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeExternalUrlTest extends TestCase
{
    private function passes(?string $url): bool
    {
        return Validator::make(['u' => $url], ['u' => ['nullable', new SafeExternalUrl()]])->passes();
    }

    public static function blocked(): array
    {
        return [
            'http scheme' => ['http://8.8.8.8/'],
            'loopback' => ['https://127.0.0.1/'],
            'localhost' => ['https://localhost/'],
            'rfc1918 10' => ['https://10.1.2.3/'],
            'rfc1918 192' => ['https://192.168.1.10/'],
            'rfc1918 172' => ['https://172.20.0.1/'],
            'metadata' => ['https://169.254.169.254/latest/meta-data'],
            'cgnat' => ['https://100.64.1.1/'],
            'ipv6 loopback' => ['https://[::1]/'],
            'ipv6 ula' => ['https://[fd00::1]/'],
            'ipv6 link-local' => ['https://[fe80::1]/'],
            'ipv4-mapped' => ['https://[::ffff:10.0.0.1]/'],
            'userinfo' => ['https://user:pw@8.8.8.8/'],
            'no host' => ['https:///path'],
            'not a url' => ['not a url'],
        ];
    }

    #[DataProvider('blocked')]
    public function test_blocks(string $url): void
    {
        $this->assertFalse($this->passes($url), $url);
    }

    public function test_allows_public_and_empty(): void
    {
        $this->assertTrue($this->passes('https://8.8.8.8/'));
        $this->assertTrue($this->passes('https://api.itglue.com'));
        $this->assertTrue($this->passes(null));
        $this->assertTrue($this->passes(''));
    }
}
