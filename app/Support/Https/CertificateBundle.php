<?php

namespace App\Support\Https;

/** A validated certificate bundle. The key is a normalised unencrypted PKCS#8 PEM. */
final class CertificateBundle
{
    public function __construct(
        public readonly string $certificatePem,
        public readonly string $privateKeyPem,
        public readonly string $chainPem,
        /** @var array{issuer:string,subject:string,sans:list<string>,fingerprint_sha256:string,key_type:string,not_before:\Carbon\Carbon,not_after:\Carbon\Carbon,ocsp:?string} */
        public readonly array $meta,
    ) {
    }

    public function fullchainPem(): string
    {
        return rtrim($this->certificatePem) . "\n" . ($this->chainPem !== '' ? rtrim($this->chainPem) . "\n" : '');
    }
}
