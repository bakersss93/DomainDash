<?php

namespace App\Services\Saml;

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use Throwable;

/** Reads an IdP (Entra "Federation Metadata XML") from pasted XML or an HTTPS URL. */
class MetadataImporter
{
    /**
     * @return array{idp_entity_id:string, idp_sso_url:string, idp_slo_url:?string, idp_x509_cert:string, idp_x509_cert_2:?string, entra_tenant_id:?string}
     *
     * @throws SamlException
     */
    public function fromXml(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || strlen($xml) > 1_000_000) {
            throw new SamlException('The metadata XML is empty or too large.', 'bad_metadata');
        }

        try {
            $info = IdPMetadataParser::parseXML($xml, null, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT);
        } catch (Throwable $e) {
            throw new SamlException('The metadata XML could not be parsed.', 'bad_metadata', ['error' => $e->getMessage()]);
        }

        $idp = $info['idp'] ?? [];
        $certs = array_values(array_unique(array_filter(array_merge(
            (array) ($idp['x509certMulti']['signing'] ?? []),
            isset($idp['x509cert']) ? [$idp['x509cert']] : []
        ))));

        if (empty($idp['entityId']) || empty($idp['singleSignOnService']['url']) || $certs === []) {
            throw new SamlException('The metadata is missing the entity ID, sign-on URL or signing certificate.', 'bad_metadata');
        }

        $tenant = null;
        if (preg_match('#^https://sts\.windows\.net/([0-9a-f-]{36})/?$#i', $idp['entityId'], $m)) {
            $tenant = strtolower($m[1]);
        }

        return [
            'idp_entity_id' => $idp['entityId'],
            'idp_sso_url' => $idp['singleSignOnService']['url'],
            'idp_slo_url' => $idp['singleLogoutService']['url'] ?? null,
            'idp_x509_cert' => $certs[0],
            'idp_x509_cert_2' => $certs[1] ?? null,
            'entra_tenant_id' => $tenant,
        ];
    }

    /** @throws SamlException */
    public function fromUrl(string $url): array
    {
        $v = Validator::make(['url' => $url], ['url' => ['required', 'string', new SafeExternalUrl()]]);
        if ($v->fails()) {
            throw new SamlException($v->errors()->first('url'), 'bad_metadata_url');
        }

        try {
            $res = Http::timeout((int) config('saml.metadata_timeout', 10))
                ->withOptions(['allow_redirects' => false])
                ->accept('application/xml,text/xml')
                ->get($url);
        } catch (Throwable $e) {
            throw new SamlException('The metadata URL could not be fetched.', 'metadata_fetch_failed', ['error' => $e->getMessage()]);
        }

        if (! $res->successful()) {
            throw new SamlException('The metadata URL returned HTTP '.$res->status().'.', 'metadata_fetch_failed');
        }

        return $this->fromXml($res->body());
    }
}
