<?php

namespace Tests\Support;

/** Generates throw-away CAs / certificates at runtime with ext-openssl. */
class CertFactory
{
    private static ?string $cnf = null;

    private static function cnf(string $sans = ''): string
    {
        $path = sys_get_temp_dir() . '/dd-openssl-' . md5($sans) . '.cnf';
        file_put_contents($path, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=keyCertSign,cRLSign\n"
            . "[v3_leaf]\nbasicConstraints=CA:FALSE\n" . ($sans !== '' ? "subjectAltName={$sans}\n" : ''));

        return $path;
    }

    public static function key(string $type = 'rsa', int $bits = 2048): \OpenSSLAsymmetricKey
    {
        $opts = [];
        if ($type === 'ec') {
            $opts += ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $bits === 384 ? 'secp384r1' : 'prime256v1'];
        } else {
            $opts += ['config' => self::cnf(), 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits];
        }

        return openssl_pkey_new($opts) ?: throw new \RuntimeException('key gen failed: ' . openssl_error_string());
    }

    /** @return array{cert:string,key:\OpenSSLAsymmetricKey,x509:\OpenSSLCertificate} */
    public static function ca(string $cn = 'Test CA', $issuer = null, $issuerKey = null): array
    {
        $key = self::key();
        $csr = openssl_csr_new(['commonName' => $cn, 'organizationName' => 'DD Test'], $key, ['config' => self::cnf(), 'digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, $issuer, $issuerKey ?? $key, 3650, ['config' => self::cnf(), 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pem);

        return ['cert' => $pem, 'key' => $key, 'x509' => $x509];
    }

    /**
     * @param list<string> $sans
     * @return array{cert:string,key:string,keyObj:\OpenSSLAsymmetricKey}
     */
    public static function leaf(array $ca, string $cn, array $sans, int $days = 90, ?\OpenSSLAsymmetricKey $key = null): array
    {
        $key ??= self::key();
        $san = implode(',', array_map(fn ($s) => 'DNS:' . $s, $sans));
        $cnf = self::cnf($san);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['config' => $cnf, 'digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, $ca['x509'], $ca['key'], $days, ['config' => $cnf, 'x509_extensions' => 'v3_leaf', 'digest_alg' => 'sha256']);
        openssl_x509_export($x509, $pem);
        openssl_pkey_export($key, $keyPem);

        return ['cert' => $pem, 'key' => $keyPem, 'keyObj' => $key];
    }

    /** Self-signed leaf (no CA) convenience. */
    public static function selfSigned(string $host): array
    {
        $ca = self::ca($host);
        $leaf = self::leaf($ca, $host, [$host]);

        return $leaf + ['ca' => $ca];
    }
}
