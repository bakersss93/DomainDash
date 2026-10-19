<?php

namespace App\Support\Https;

use Illuminate\Support\Carbon;

/**
 * Validates an uploaded certificate (PEM or PFX) for use as the platform TLS certificate.
 * Used by the web app (on upload) AND re-run by the root helper (it does not trust the DB).
 */
class CertificateValidator
{
    private const CERT_RE = '/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+?-----END CERTIFICATE-----/';

    /**
     * @throws CertificateException
     */
    public function validatePem(string $certificate, string $privateKey, ?string $chain, string $host, ?string $now = null): CertificateBundle
    {
        $certificate = $this->clean($certificate);
        $privateKey = $this->clean($privateKey);
        $chain = $this->clean((string) $chain);

        if (str_contains($certificate, 'PRIVATE KEY') || str_contains($chain, 'PRIVATE KEY')) {
            throw new CertificateException('The certificate/chain field contains a private key. Paste the key in the private key field only.');
        }

        preg_match_all(self::CERT_RE, $certificate, $certBlocks);
        preg_match_all(self::CERT_RE, $chain, $chainBlocks);
        if (empty($certBlocks[0])) {
            throw new CertificateException('No PEM certificate found. Expected a "-----BEGIN CERTIFICATE-----" block.');
        }

        $leaf = $certBlocks[0][0];
        // Allow a pasted fullchain: extra blocks in the certificate field are treated as chain.
        $extras = array_merge(array_slice($certBlocks[0], 1), $chainBlocks[0]);

        if (! preg_match('/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/', $privateKey)) {
            throw new CertificateException('No PEM private key found.');
        }
        if (str_contains($privateKey, 'ENCRYPTED')) {
            throw new CertificateException('The private key is passphrase-protected. Remove the passphrase first (openssl pkey -in key.pem -out key-plain.pem) or upload a PFX.');
        }

        $key = @openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new CertificateException('The private key could not be read.');
        }

        return $this->finish($leaf, $key, $extras, $host, true, $now);
    }

    /**
     * @throws CertificateException
     */
    public function validatePfx(string $pfx, string $password, string $host, ?string $now = null): CertificateBundle
    {
        $parts = [];
        if (! @openssl_pkcs12_read($pfx, $parts, $password)) {
            throw new CertificateException('The PFX could not be opened. Check the password (legacy RC2-encrypted PFX files may need re-exporting with a modern cipher).');
        }
        if (empty($parts['cert']) || empty($parts['pkey'])) {
            throw new CertificateException('The PFX must contain both a certificate and its private key.');
        }

        $key = @openssl_pkey_get_private($parts['pkey']);
        if ($key === false) {
            throw new CertificateException('The private key inside the PFX could not be read.');
        }

        $extras = [];
        foreach ((array) ($parts['extracerts'] ?? []) as $pem) {
            $extras[] = $this->clean($pem);
        }

        return $this->finish($this->clean($parts['cert']), $key, $extras, $host, false, $now);
    }

    /**
     * Metadata for a single certificate PEM (also used for Let's Encrypt output).
     *
     * @return array{issuer:string,subject:string,cn:string,sans:list<string>,fingerprint_sha256:string,key_type:string,not_before:Carbon,not_after:Carbon,ocsp:?string,is_ca:bool}
     */
    public function inspect(string $certificatePem): array
    {
        $x509 = @openssl_x509_read($certificatePem);
        $info = $x509 === false ? false : openssl_x509_parse($x509);
        if ($info === false) {
            throw new CertificateException('The certificate could not be parsed.');
        }

        $sans = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $sans[] = strtolower(substr($entry, 4));
            }
        }

        $fp = strtoupper((string) openssl_x509_fingerprint($x509, 'sha256'));

        $ocsp = null;
        if (preg_match('/OCSP - URI:(\S+)/', (string) ($info['extensions']['authorityInfoAccess'] ?? ''), $m)) {
            $ocsp = $m[1];
        }

        $pub = openssl_pkey_get_public($x509);
        $details = $pub ? openssl_pkey_get_details($pub) : [];

        return [
            'issuer' => $this->dn($info['issuer'] ?? []),
            'subject' => $this->dn($info['subject'] ?? []),
            'cn' => strtolower((string) ($info['subject']['CN'] ?? '')),
            'sans' => array_values(array_unique($sans)),
            'fingerprint_sha256' => implode(':', str_split($fp, 2)),
            'key_type' => $this->keyLabel($details),
            'not_before' => Carbon::createFromTimestampUTC((int) $info['validFrom_time_t']),
            'not_after' => Carbon::createFromTimestampUTC((int) $info['validTo_time_t']),
            'ocsp' => $ocsp,
            'is_ca' => str_contains((string) ($info['extensions']['basicConstraints'] ?? ''), 'CA:TRUE'),
        ];
    }

    /** @param list<string> $extras */
    private function finish(string $leaf, \OpenSSLAsymmetricKey $key, array $extras, string $host, bool $strictChainOrder, ?string $now): CertificateBundle
    {
        $host = strtolower(trim($host));
        try {
            HostnameValidator::hostname($host, true);
        } catch (\InvalidArgumentException $e) {
            throw new CertificateException($e->getMessage());
        }

        $meta = $this->inspect($leaf);
        $x509 = openssl_x509_read($leaf);

        if ($meta['is_ca']) {
            throw new CertificateException('This is a CA certificate, not a server certificate.');
        }

        $this->assertKeyStrength(openssl_pkey_get_details($key) ?: []);

        if (! openssl_x509_check_private_key($x509, $key)) {
            throw new CertificateException('The private key does not match the certificate.');
        }

        $nowTs = $now ? Carbon::parse($now)->getTimestamp() : time();
        if ($meta['not_after']->getTimestamp() <= $nowTs) {
            throw new CertificateException('The certificate expired on ' . $meta['not_after']->toDateString() . '.');
        }
        if ($meta['not_before']->getTimestamp() > $nowTs + 300) {
            throw new CertificateException('The certificate is not valid until ' . $meta['not_before']->toDateString() . '.');
        }

        $names = $meta['sans'] ?: ($meta['cn'] !== '' ? [$meta['cn']] : []);
        if (! HostnameValidator::covers($host, $names)) {
            throw new CertificateException(sprintf(
                'The certificate does not cover %s (it covers: %s).',
                $host,
                $names ? implode(', ', array_slice($names, 0, 8)) : 'no names'
            ));
        }

        $chainPem = $this->orderChain($leaf, $extras, $strictChainOrder);

        if (! openssl_pkey_export($key, $exportedKey)) {
            throw new CertificateException('The private key could not be normalised.');
        }

        unset($meta['is_ca'], $meta['cn']);

        return new CertificateBundle(rtrim($leaf) . "\n", $exportedKey, $chainPem, $meta);
    }

    private function assertKeyStrength(array $details): void
    {
        $type = $details['type'] ?? null;
        if ($type === OPENSSL_KEYTYPE_RSA) {
            $bits = (int) ($details['bits'] ?? 0);
            if ($bits < 2048 || $bits > 8192) {
                throw new CertificateException("RSA keys must be between 2048 and 8192 bits (got {$bits}).");
            }

            return;
        }
        if ($type === OPENSSL_KEYTYPE_EC) {
            $curve = $details['ec']['curve_name'] ?? '';
            if (! in_array($curve, ['prime256v1', 'secp384r1'], true)) {
                throw new CertificateException("Only EC P-256 and P-384 keys are accepted (got {$curve}).");
            }

            return;
        }

        throw new CertificateException('Unsupported private key type. Use RSA (2048+) or EC (P-256/P-384).');
    }

    /**
     * Build the chain PEM. Strict mode (PEM upload): the supplied order must already be
     * issuer-after-subject. Non-strict (PFX, unordered): we sort and drop unrelated certs.
     *
     * @param list<string> $extras
     */
    private function orderChain(string $leaf, array $extras, bool $strict): string
    {
        if ($extras === []) {
            return '';
        }

        $ordered = [];
        $current = $leaf;
        $pool = $extras;

        if ($strict) {
            foreach ($pool as $i => $cert) {
                if (! $this->issuedBy($current, $cert)) {
                    throw new CertificateException(sprintf(
                        'The chain is not in order: certificate #%d of the chain did not sign the previous certificate. Order must be leaf, intermediate(s), then (optionally) root.',
                        $i + 1
                    ));
                }
                $ordered[] = $cert;
                $current = $cert;
            }
        } else {
            while ($pool) {
                $found = null;
                foreach ($pool as $i => $cert) {
                    if ($this->issuedBy($current, $cert)) {
                        $found = $i;
                        break;
                    }
                }
                if ($found === null) {
                    break;
                }
                $ordered[] = $pool[$found];
                $current = $pool[$found];
                unset($pool[$found]);
                $pool = array_values($pool);
            }
        }

        return implode("\n", array_map('rtrim', $ordered)) . ($ordered ? "\n" : '');
    }

    private function issuedBy(string $childPem, string $issuerPem): bool
    {
        $child = @openssl_x509_read($childPem);
        $issuer = @openssl_x509_read($issuerPem);
        if ($child === false || $issuer === false) {
            return false;
        }
        $pub = openssl_pkey_get_public($issuer);

        return $pub !== false && openssl_x509_verify($child, $pub) === 1;
    }

    private function keyLabel(array $d): string
    {
        return match ($d['type'] ?? null) {
            OPENSSL_KEYTYPE_RSA => 'RSA ' . ($d['bits'] ?? '?'),
            OPENSSL_KEYTYPE_EC => 'EC ' . match ($d['ec']['curve_name'] ?? '') {
                'prime256v1' => 'P-256',
                'secp384r1' => 'P-384',
                'secp521r1' => 'P-521',
                default => (string) ($d['ec']['curve_name'] ?? '?'),
            },
            default => 'unknown',
        };
    }

    private function dn(array $dn): string
    {
        $cn = (string) ($dn['CN'] ?? '');
        $o = $dn['O'] ?? '';
        $o = is_array($o) ? implode(' ', $o) : (string) $o;

        return trim($cn . ($o !== '' ? ' (' . $o . ')' : '')) ?: 'unknown';
    }

    private function clean(string $pem): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $pem));
    }
}
