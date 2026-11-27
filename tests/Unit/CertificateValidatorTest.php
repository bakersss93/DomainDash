<?php

namespace Tests\Unit;

use App\Support\Https\CertificateException;
use App\Support\Https\CertificateValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\CertFactory;

class CertificateValidatorTest extends TestCase
{
    private CertificateValidator $v;

    protected function setUp(): void
    {
        $this->v = new CertificateValidator();
    }

    private function fails(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('expected CertificateException containing: ' . $needle);
        } catch (CertificateException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_valid_certificate_with_chain(): void
    {
        $root = CertFactory::ca('Root');
        $inter = CertFactory::ca('Inter', $root['x509'], $root['key']);
        $leaf = CertFactory::leaf($inter, 'dash.example.com', ['dash.example.com', 'www.example.com']);

        $b = $this->v->validatePem($leaf['cert'], $leaf['key'], $inter['cert'] . "\n" . $root['cert'], 'dash.example.com');

        $this->assertSame(['dash.example.com', 'www.example.com'], $b->meta['sans']);
        $this->assertSame('RSA 2048', $b->meta['key_type']);
        $this->assertStringContainsString('Inter', $b->meta['issuer']);
        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $b->meta['fingerprint_sha256']);
        $this->assertSame(2, substr_count($b->chainPem, 'BEGIN CERTIFICATE'));
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $b->privateKeyPem);
        $this->assertTrue($b->meta['not_after']->isFuture());
    }

    public function test_fullchain_in_certificate_field_is_split(): void
    {
        $root = CertFactory::ca('Root');
        $leaf = CertFactory::leaf($root, 'a.example.com', ['a.example.com']);
        $b = $this->v->validatePem($leaf['cert'] . $root['cert'], $leaf['key'], null, 'a.example.com');
        $this->assertSame(1, substr_count($b->chainPem, 'BEGIN CERTIFICATE'));
    }

    public function test_mismatched_key_rejected(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'a.example.com', ['a.example.com']);
        $other = CertFactory::leaf($ca, 'a.example.com', ['a.example.com']);
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $other['key'], null, 'a.example.com'), 'does not match');
    }

    public function test_expired_rejected(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'a.example.com', ['a.example.com'], 1);
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $leaf['key'], null, 'a.example.com', gmdate('c', time() + 5 * 86400)), 'expired');
    }

    public function test_wrong_hostname_rejected(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'a.example.com', ['a.example.com']);
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $leaf['key'], null, 'b.example.com'), 'does not cover b.example.com');
    }

    public function test_wildcard_match(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, '*.example.com', ['*.example.com']);
        $this->assertNotNull($this->v->validatePem($leaf['cert'], $leaf['key'], null, 'dash.example.com'));
        $this->assertNotNull($this->v->validatePem($leaf['cert'], $leaf['key'], null, '*.example.com'));
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $leaf['key'], null, 'a.b.example.com'), 'does not cover');
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $leaf['key'], null, 'example.com'), 'does not cover');
    }

    public function test_weak_rsa_key_rejected(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'a.example.com', ['a.example.com'], 90, CertFactory::key('rsa', 1024));
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $leaf['key'], null, 'a.example.com'), 'RSA keys must be');
    }

    public function test_ec_p256_and_p384_accepted(): void
    {
        $ca = CertFactory::ca();
        foreach ([256 => 'EC P-256', 384 => 'EC P-384'] as $bits => $label) {
            $leaf = CertFactory::leaf($ca, 'a.example.com', ['a.example.com'], 90, CertFactory::key('ec', $bits));
            $this->assertSame($label, $this->v->validatePem($leaf['cert'], $leaf['key'], null, 'a.example.com')->meta['key_type']);
        }
    }

    public function test_chain_out_of_order_rejected(): void
    {
        $root = CertFactory::ca('Root');
        $inter = CertFactory::ca('Inter', $root['x509'], $root['key']);
        $leaf = CertFactory::leaf($inter, 'a.example.com', ['a.example.com']);
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], $leaf['key'], $root['cert'] . $inter['cert'], 'a.example.com'), 'not in order');
    }

    public function test_private_key_in_certificate_field_and_garbage_rejected(): void
    {
        $ca = CertFactory::ca();
        $leaf = CertFactory::leaf($ca, 'a.example.com', ['a.example.com']);
        $this->fails(fn () => $this->v->validatePem($leaf['cert'] . $leaf['key'], $leaf['key'], null, 'a.example.com'), 'contains a private key');
        $this->fails(fn () => $this->v->validatePem('nonsense', $leaf['key'], null, 'a.example.com'), 'No PEM certificate');
        $this->fails(fn () => $this->v->validatePem($leaf['cert'], 'nonsense', null, 'a.example.com'), 'No PEM private key');
    }

    public function test_ca_certificate_rejected(): void
    {
        $ca = CertFactory::ca('x.example.com');
        openssl_pkey_export($ca['key'], $k);
        $this->fails(fn () => $this->v->validatePem($ca['cert'], $k, null, 'x.example.com'), 'CA certificate');
    }

    public function test_pfx(): void
    {
        $root = CertFactory::ca('Root');
        $leaf = CertFactory::leaf($root, 'dash.example.com', ['dash.example.com']);
        openssl_pkcs12_export($leaf['cert'], $pfx, $leaf['keyObj'], 's3cret', ['extracerts' => [$root['cert']]]);

        $b = $this->v->validatePfx($pfx, 's3cret', 'dash.example.com');
        $this->assertSame(['dash.example.com'], $b->meta['sans']);
        $this->assertSame(1, substr_count($b->chainPem, 'BEGIN CERTIFICATE'));

        $this->fails(fn () => $this->v->validatePfx($pfx, 'wrong', 'dash.example.com'), 'could not be opened');
        $this->fails(fn () => $this->v->validatePfx($pfx, 's3cret', 'other.example.com'), 'does not cover');
    }
}
