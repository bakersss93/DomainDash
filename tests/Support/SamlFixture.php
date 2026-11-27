<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\SamlConnection;
use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/** Builds (and signs) Entra-shaped SAML responses with throw-away keys generated in the test run. */
class SamlFixture
{
    public const TENANT = '11111111-2222-3333-4444-555555555555';

    /** @return array{key:string, cert:string} cert is bare base64 (no PEM armour) */
    public static function keyPair(string $cn = 'test-idp'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [
            'key' => $keyPem,
            'cert' => preg_replace('/\s+/', '', str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $certPem)),
        ];
    }

    public static function connection(array $attrs = [], array $domains = ['contoso.com'], ?array $cert = null): SamlConnection
    {
        $cert ??= self::keyPair();
        $type = $attrs['type'] ?? 'staff';
        if ($type === 'client' && empty($attrs['client_id'])) {
            $attrs['client_id'] = Client::query()->create(['business_name' => 'Client '.uniqid()])->id;
        }

        $c = SamlConnection::create($attrs + [
            'name' => 'Test '.$type,
            'type' => $type,
            'enabled' => true,
            'entra_tenant_id' => self::TENANT,
            'idp_entity_id' => 'https://sts.windows.net/'.self::TENANT.'/',
            'idp_sso_url' => 'https://login.microsoftonline.com/'.self::TENANT.'/saml2',
            'idp_x509_cert' => $cert['cert'],
            'group_role_map' => [],
            'jit_provisioning' => true,
            'skip_local_mfa' => true,
        ]);
        $c->syncDomains($domains);

        return $c;
    }

    /**
     * @param array $o inResponseTo, key, cert(unused), email, objectId, tenantId, groups, nameId, assertionId, sign,
     *                 destination, recipient, audience, notBefore(int offset s), notOnOrAfter(int offset s), extraAttributes
     */
    public static function response(SamlConnection $c, array $key, array $o = []): string
    {
        $now = time();
        $fmt = fn (int $t) => gmdate('Y-m-d\TH:i:s\Z', $t);
        $acs = $o['recipient'] ?? $c->acsUrl();
        $dest = $o['destination'] ?? $c->acsUrl();
        $aud = $o['audience'] ?? $c->spEntityId();
        $issuer = $o['issuer'] ?? $c->idp_entity_id;
        $irt = $o['inResponseTo'] ?? 'ONELOGIN_missing';
        $aid = $o['assertionId'] ?? '_'.bin2hex(random_bytes(16));
        $email = $o['email'] ?? 'alice@contoso.com';
        $nb = $fmt($now + ($o['notBefore'] ?? -30));
        $noa = $fmt($now + ($o['notOnOrAfter'] ?? 300));
        $groups = $o['groups'] ?? [];
        $tenant = $o['tenantId'] ?? self::TENANT;

        $attr = function (string $name, array $values) {
            $v = '';
            foreach ($values as $val) {
                $v .= '<saml:AttributeValue>'.htmlspecialchars($val, ENT_XML1).'</saml:AttributeValue>';
            }

            return '<saml:Attribute Name="'.htmlspecialchars($name, ENT_XML1).'">'.$v.'</saml:Attribute>';
        };

        $attrs = $attr('http://schemas.microsoft.com/identity/claims/tenantid', [$tenant])
            .$attr('http://schemas.microsoft.com/identity/claims/objectidentifier', [$o['objectId'] ?? 'aaaaaaaa-0000-0000-0000-000000000001'])
            .$attr('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', [$email])
            .$attr('http://schemas.microsoft.com/identity/claims/displayname', [$o['displayName'] ?? 'Alice Example']);
        if ($groups) {
            $attrs .= $attr('http://schemas.microsoft.com/ws/2008/06/identity/claims/groups', $groups);
        }
        foreach ($o['extraAttributes'] ?? [] as $n => $vals) {
            $attrs .= $attr($n, (array) $vals);
        }

        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            .' ID="_resp'.bin2hex(random_bytes(8)).'" Version="2.0" IssueInstant="'.$fmt($now).'" Destination="'.htmlspecialchars($dest, ENT_XML1).'" InResponseTo="'.$irt.'">'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer>'
            .'<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            .'<saml:Assertion ID="'.$aid.'" Version="2.0" IssueInstant="'.$fmt($now).'">'
            .'<saml:Issuer>'.$issuer.'</saml:Issuer>'
            .'<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">'.htmlspecialchars($o['nameId'] ?? $email, ENT_XML1).'</saml:NameID>'
            .'<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData InResponseTo="'.$irt.'" NotOnOrAfter="'.$noa.'" Recipient="'.htmlspecialchars($acs, ENT_XML1).'"/></saml:SubjectConfirmation></saml:Subject>'
            .'<saml:Conditions NotBefore="'.$nb.'" NotOnOrAfter="'.$noa.'"><saml:AudienceRestriction><saml:Audience>'.htmlspecialchars($aud, ENT_XML1).'</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            .'<saml:AttributeStatement>'.$attrs.'</saml:AttributeStatement>'
            .'<saml:AuthnStatement AuthnInstant="'.$fmt($now).'" SessionIndex="'.$aid.'"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            .'</saml:Assertion></samlp:Response>';

        if ($o['sign'] ?? true) {
            $xml = self::signAssertion($xml, $key);
        }

        return base64_encode($xml);
    }

    /** Enveloped RSA-SHA256 signature on the Assertion, placed after its Issuer (as Entra does). */
    public static function signAssertion(string $xml, array $key): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $assertion = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);
        $issuer = $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference($assertion, XMLSecurityDSig::SHA256, [
            'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
            XMLSecurityDSig::EXC_C14N,
        ], ['id_name' => 'ID', 'overwrite' => false]);
        $k = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $k->loadKey($key['key'], false);
        $dsig->sign($k);
        $dsig->add509Cert("-----BEGIN CERTIFICATE-----\n".chunk_split($key['cert'], 64, "\n")."-----END CERTIFICATE-----", true);
        $dsig->insertSignature($assertion, $issuer->nextSibling);

        return $doc->saveXML();
    }
}
