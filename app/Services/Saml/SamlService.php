<?php

namespace App\Services\Saml;

use App\Models\SamlConnection;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * Thin, strict wrapper around onelogin/php-saml for Microsoft Entra ID.
 *
 * Security properties enforced here (each has a test in tests/Feature/Sso/SamlAssertionValidationTest):
 *  - assertion must be signed by one of the connection's two configured certificates (library: wantAssertionsSigned)
 *  - strict mode: schema, issuer, audience, destination, recipient, InResponseTo, NotBefore/NotOnOrAfter
 *  - InResponseTo must match an AuthnRequest we issued for THIS connection (single use, stored server side,
 *    because the ACS POST is cross-site and does not carry the session cookie)
 *  - Destination / Recipient / Audience must be present and match exactly (the library only does prefix/loose checks)
 *  - clock skew re-checked at config('saml.clock_skew') seconds (the library's own tolerance is 180s)
 *  - assertion IDs are remembered until they expire; a second presentation is refused
 *  - the tenant ID claim must equal the connection's Entra tenant
 */
class SamlService
{
    public function settingsArray(SamlConnection $c): array
    {
        $certs = $c->signingCerts();

        return [
            'strict' => true,
            'debug' => false,
            'baseurl' => null,
            'sp' => [
                'entityId' => $c->spEntityId(),
                'assertionConsumerService' => [
                    'url' => $c->acsUrl(),
                    'binding' => Constants::BINDING_HTTP_POST,
                ],
                'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
            ],
            'idp' => [
                'entityId' => $c->idp_entity_id,
                'singleSignOnService' => [
                    'url' => $c->idp_sso_url,
                    'binding' => Constants::BINDING_HTTP_REDIRECT,
                ],
                'x509cert' => $certs[0] ?? '',
                'x509certMulti' => ['signing' => $certs],
            ],
            'security' => [
                'authnRequestsSigned' => false,
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantAssertionsEncrypted' => false,
                'wantNameIdEncrypted' => false,
                'wantNameId' => true,
                'wantXMLValidation' => true,
                'requestedAuthnContext' => false, // Entra rejects specific contexts it did not authenticate with
                'relaxDestinationValidation' => false,
                'destinationStrictlyMatches' => true,
                'allowRepeatAttributeName' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
    }

    public function metadataXml(SamlConnection $c): string
    {
        // SP-only settings: do not require IdP data to publish our own metadata.
        $settings = new Settings($this->settingsArray($c), true);

        return $settings->getSPMetadata();
    }

    /** Build the redirect to Entra and remember the request ID. */
    public function beginLogin(SamlConnection $c, ?string $returnTo = null): string
    {
        $auth = new Auth($this->settingsArray($c));
        $url = $auth->login(null, [], false, false, true, false);
        $requestId = $auth->getLastRequestID();

        DB::table('saml_pending_requests')->where('expires_at', '<', now())->delete();
        DB::table('saml_pending_requests')->insert([
            'request_id' => $requestId,
            'saml_connection_id' => $c->id,
            'return_to' => $returnTo,
            'expires_at' => now()->addSeconds((int) config('saml.request_ttl', 600)),
        ]);

        return $url;
    }

    /**
     * Validate a base64 SAMLResponse posted to the ACS of $c.
     *
     * @return array{0: SamlIdentity, 1: ?string} identity and the return_to remembered at login start
     *
     * @throws SamlException
     */
    public function consume(SamlConnection $c, string $samlResponse): array
    {
        $acs = $c->acsUrl();
        $parts = parse_url($acs);
        $saved = [
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'qs' => $_SERVER['QUERY_STRING'] ?? null,
        ];

        try {
            $this->pinSelfUrl($parts);

            return $this->consumePinned($c, $samlResponse, $acs);
        } finally {
            $this->unpinSelfUrl($saved);
        }
    }

    private function consumePinned(SamlConnection $c, string $samlResponse, string $acs): array
    {
        if ($samlResponse === '' || strlen($samlResponse) > 2_000_000) {
            throw new SamlException('The sign-in response was empty or too large.', 'bad_response');
        }

        try {
            $settings = new Settings($this->settingsArray($c));
            $response = new Response($settings, $samlResponse);
        } catch (Throwable $e) {
            throw new SamlException('The sign-in response could not be read.', 'unparseable', ['error' => $e->getMessage()]);
        }

        $doc = $response->getXMLDocument();
        $root = $doc->documentElement;
        $inResponseTo = $root instanceof DOMElement ? trim($root->getAttribute('InResponseTo')) : '';

        if ($inResponseTo === '') {
            throw new SamlException('Sign-in must be started from DomainDash (IdP-initiated sign-in is not accepted).', 'no_in_response_to');
        }

        // One-time use, bound to this connection, not expired.
        $consumed = DB::table('saml_pending_requests')
            ->where('request_id', $inResponseTo)
            ->where('saml_connection_id', $c->id)
            ->where('expires_at', '>', now())
            ->first();
        $deleted = $consumed ? DB::table('saml_pending_requests')->where('id', $consumed->id)->delete() : 0;
        if ($deleted !== 1) {
            throw new SamlException('This sign-in attempt has expired or was not started here. Please try again.', 'unknown_request', ['in_response_to' => $inResponseTo]);
        }

        if (! $response->isValid($inResponseTo)) {
            $e = $response->getErrorException();
            throw new SamlException('The sign-in response failed security validation.', 'invalid_response', ['error' => $e?->getMessage()]);
        }

        $xp = $this->xpath($doc);
        $this->strictChecks($c, $xp, $root, $acs);

        try {
            $assertionId = (string) $response->getAssertionId();
            $attrs = $response->getAttributes();
            $nameId = (string) $response->getNameId();
        } catch (Throwable $e) {
            throw new SamlException('The sign-in response was incomplete.', 'incomplete', ['error' => $e->getMessage()]);
        }

        if ($assertionId === '') {
            throw new SamlException('The sign-in response had no assertion ID.', 'no_assertion_id');
        }

        $claims = (array) config('saml.claims');
        foreach ((array) $claims['groups_overage'] as $overage) {
            if (isset($attrs[$overage])) {
                throw new SamlException(
                    'Your Microsoft account is in too many groups for the sign-in token. An administrator must set the Entra app to emit "Groups assigned to the application" instead of all groups.',
                    'groups_overage'
                );
            }
        }

        $tenant = strtolower((string) ($attrs[$claims['tenant_id']][0] ?? ''));
        if ($tenant === '' || $tenant !== strtolower((string) $c->entra_tenant_id)) {
            throw new SamlException('This Microsoft account belongs to a different organisation than this sign-in connection.', 'tenant_mismatch', ['tenant' => $tenant]);
        }

        $objectId = strtolower((string) ($attrs[$claims['object_id']][0] ?? ''));
        if ($objectId === '') {
            throw new SamlException('The sign-in response did not include the Entra object ID claim.', 'no_object_id');
        }

        $email = $this->firstClaim($attrs, (array) $claims['email']);
        if (! $email && str_contains($nameId, '@')) {
            $email = $nameId;
        }
        $email = $email ? strtolower(trim($email)) : null;
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        $groups = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($attrs[$claims['groups']] ?? [])))));

        $this->rememberAssertion($c, $assertionId, $xp);

        return [
            new SamlIdentity($objectId, $tenant, $nameId, $email, $this->firstClaim($attrs, (array) $claims['name']), $groups, $assertionId),
            $consumed->return_to,
        ];
    }

    /** Checks the library skips or does loosely; all run on the single, signature-verified assertion. */
    private function strictChecks(SamlConnection $c, DOMXPath $xp, DOMElement $root, string $acs): void
    {
        $dest = trim($root->getAttribute('Destination'));
        if ($dest !== $acs) {
            throw new SamlException('The sign-in response was addressed to a different destination.', 'bad_destination', ['destination' => $dest]);
        }

        $aud = [];
        foreach ($xp->query('/samlp:Response/saml:Assertion/saml:Conditions/saml:AudienceRestriction/saml:Audience') as $n) {
            $aud[] = trim($n->textContent);
        }
        if (! in_array($c->spEntityId(), $aud, true)) {
            throw new SamlException('The sign-in response was issued for a different application.', 'bad_audience', ['audience' => $aud]);
        }

        $recipientOk = false;
        foreach ($xp->query('/samlp:Response/saml:Assertion/saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData') as $n) {
            /** @var DOMElement $n */
            if ($n->getAttribute('Recipient') === $acs) {
                $recipientOk = true;
                $this->assertTimeWindow($n->getAttribute('NotBefore'), $n->getAttribute('NotOnOrAfter'), 'subject confirmation');
            }
        }
        if (! $recipientOk) {
            throw new SamlException('The sign-in response named a different recipient.', 'bad_recipient');
        }

        $cond = $xp->query('/samlp:Response/saml:Assertion/saml:Conditions')->item(0);
        if ($cond instanceof DOMElement) {
            $this->assertTimeWindow($cond->getAttribute('NotBefore'), $cond->getAttribute('NotOnOrAfter'), 'conditions');
        }
    }

    private function assertTimeWindow(string $notBefore, string $notOnOrAfter, string $what): void
    {
        $skew = (int) config('saml.clock_skew', 60);
        $now = time();

        if ($notBefore !== '' && Utils::parseSAML2Time($notBefore) > $now + $skew) {
            throw new SamlException('The sign-in response is not valid yet. Check the server clock.', 'not_yet_valid', ['where' => $what]);
        }
        if ($notOnOrAfter !== '' && Utils::parseSAML2Time($notOnOrAfter) + $skew <= $now) {
            throw new SamlException('The sign-in response has expired. Please try again.', 'expired', ['where' => $what]);
        }
    }

    private function rememberAssertion(SamlConnection $c, string $assertionId, DOMXPath $xp): void
    {
        $until = 0;
        foreach ($xp->query('//saml:Conditions/@NotOnOrAfter | //saml:SubjectConfirmationData/@NotOnOrAfter') as $attr) {
            $until = max($until, Utils::parseSAML2Time($attr->value) + (int) config('saml.clock_skew', 60) + 60);
        }
        if ($until === 0) {
            $until = time() + 3600;
        }

        DB::table('saml_assertion_replays')->where('expires_at', '<', now())->delete();

        try {
            DB::table('saml_assertion_replays')->insert([
                'assertion_hash' => hash('sha256', $c->id.'|'.$assertionId),
                'expires_at' => date('Y-m-d H:i:s', max($until, time() + 300)),
            ]);
        } catch (QueryException $e) {
            Log::warning('SAML assertion replay refused', ['connection' => $c->uuid]);
            throw new SamlException('This sign-in response has already been used.', 'replay');
        }
    }

    private function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('samlp', Constants::NS_SAMLP);
        $xp->registerNamespace('saml', Constants::NS_SAML);

        return $xp;
    }

    private function firstClaim(array $attrs, array $names): ?string
    {
        foreach ($names as $n) {
            $v = $attrs[$n][0] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /** Make the library compute "the current URL" from the configured ACS URL, never from request headers. */
    private function pinSelfUrl(array $parts): void
    {
        Utils::setSelfProtocol($parts['scheme'] ?? 'https');
        Utils::setSelfHost($parts['host'] ?? 'localhost');
        Utils::setSelfPort((string) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80)));
        $_SERVER['REQUEST_URI'] = $parts['path'] ?? '/';
        $_SERVER['QUERY_STRING'] = '';
    }

    private function unpinSelfUrl(array $saved): void
    {
        Utils::setSelfProtocol(null);
        Utils::setSelfHost(null);
        Utils::setSelfPort(null);
        foreach (['REQUEST_URI' => 'uri', 'QUERY_STRING' => 'qs'] as $k => $s) {
            if ($saved[$s] === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $saved[$s];
            }
        }
    }
}
