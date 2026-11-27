<?php

namespace App\Services\Microsoft;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenID Connect authorization-code flow with PKCE (S256) against the multi-tenant `organizations` endpoint.
 *
 * state, nonce and the PKCE verifier live only in the server-side session and are single use.
 * The ID token is verified with firebase/php-jwt against the signing keys of the tenant named in the token
 * (https://login.microsoftonline.com/{tid}/discovery/v2.0/keys): signature (RS256 only), issuer, audience,
 * nonce, exp / nbf / iat (with small skew) and a well-formed tid / oid.
 */
class MicrosoftOidcClient
{
    public const AUTHORITY = 'https://login.microsoftonline.com';
    private const SESSION_KEY = 'microsoft_oidc';
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const FLOW_TTL = 600;
    private const SKEW = 60;

    /** Begin a sign-in. Returns the Microsoft authorize URL. */
    public function begin(Session $session, ?string $loginHint = null): string
    {
        $state = Str::random(48);
        $nonce = Str::random(48);
        $verifier = self::b64url(random_bytes(64));

        $session->put(self::SESSION_KEY, [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'created_at' => time(),
        ]);

        $query = [
            'client_id' => MicrosoftSettings::clientId(),
            'response_type' => 'code',
            'redirect_uri' => MicrosoftSettings::redirectUri(),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        if ($loginHint !== null && $loginHint !== '') {
            $query['login_hint'] = $loginHint;
        }

        return self::AUTHORITY.'/organizations/oauth2/v2.0/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish a sign-in: check state, redeem the code, validate the ID token.
     *
     * @throws MicrosoftOidcException
     */
    public function complete(Session $session, ?string $code, ?string $state): MicrosoftIdentity
    {
        $flow = $session->pull(self::SESSION_KEY); // single use, whatever happens next

        if (! is_array($flow) || ! isset($flow['state'], $flow['nonce'], $flow['verifier'], $flow['created_at'])) {
            throw new MicrosoftOidcException('Your sign-in session expired. Please try again.', 'no_flow');
        }
        if (time() - (int) $flow['created_at'] > self::FLOW_TTL) {
            throw new MicrosoftOidcException('Your sign-in session expired. Please try again.', 'flow_expired');
        }
        if (! is_string($state) || $state === '' || ! hash_equals($flow['state'], $state)) {
            throw new MicrosoftOidcException('Sign-in could not be verified. Please try again.', 'state_mismatch');
        }
        if (! is_string($code) || $code === '' || strlen($code) > 4096) {
            throw new MicrosoftOidcException('Microsoft did not return an authorisation code.', 'no_code');
        }

        $idToken = $this->redeem($code, $flow['verifier']);

        return $this->validateIdToken($idToken, $flow['nonce']);
    }

    private function redeem(string $code, string $verifier): string
    {
        try {
            $response = Http::asForm()->withoutRedirecting()->timeout(10)->acceptJson()
                ->post(self::AUTHORITY.'/organizations/oauth2/v2.0/token', [
                    'client_id' => MicrosoftSettings::clientId(),
                    'client_secret' => MicrosoftSettings::clientSecret(),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => MicrosoftSettings::redirectUri(),
                    'code_verifier' => $verifier,
                    'scope' => 'openid profile email',
                ]);
        } catch (Throwable $e) {
            throw new MicrosoftOidcException('Microsoft could not be reached. Please try again.', 'token_unreachable', ['error' => class_basename($e)]);
        }

        if (! $response->successful()) {
            // The error code is Microsoft's (e.g. invalid_grant); the description may echo input, so it is not stored.
            throw new MicrosoftOidcException('Microsoft refused the sign-in. Please try again.', 'token_rejected', [
                'status' => $response->status(), 'error' => Str::limit((string) $response->json('error'), 64, ''),
            ]);
        }

        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw new MicrosoftOidcException('Microsoft did not return an identity token.', 'no_id_token');
        }

        return $idToken;
    }

    /** @throws MicrosoftOidcException */
    public function validateIdToken(string $jwt, string $expectedNonce): MicrosoftIdentity
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new MicrosoftOidcException('The identity token is malformed.', 'token_malformed');
        }

        // Unverified peek: only to choose WHICH tenant's keys to verify with. The signature is then verified with that
        // tenant's keys and the (signed) issuer must name this same tenant, so a token cannot borrow another tenant's keys.
        $unverified = json_decode((string) @base64_decode(strtr($segments[1], '-_', '+/'), false), true);
        $tid = is_array($unverified) ? (string) ($unverified['tid'] ?? '') : '';
        if (! preg_match(self::GUID, $tid)) {
            throw new MicrosoftOidcException('The identity token has no tenant.', 'tid_missing');
        }
        $tid = strtolower($tid);

        $header = json_decode((string) @base64_decode(strtr($segments[0], '-_', '+/'), false), true);
        $kid = is_array($header) ? (string) ($header['kid'] ?? '') : '';
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new MicrosoftOidcException('The identity token uses an unsupported algorithm.', 'bad_alg');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::SKEW;
        try {
            $claims = JWT::decode($jwt, $this->keysFor($tid, $kid));
        } catch (\Firebase\JWT\ExpiredException) {
            throw new MicrosoftOidcException('The identity token has expired.', 'token_expired');
        } catch (\Firebase\JWT\BeforeValidException) {
            throw new MicrosoftOidcException('The identity token is not valid yet.', 'token_not_yet_valid');
        } catch (\Firebase\JWT\SignatureInvalidException) {
            throw new MicrosoftOidcException('The identity token signature is not valid.', 'bad_signature');
        } catch (MicrosoftOidcException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MicrosoftOidcException('The identity token could not be verified.', 'token_invalid', ['error' => class_basename($e)]);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $c = (array) $claims;

        if (! isset($c['exp']) || ! is_numeric($c['exp'])) {
            throw new MicrosoftOidcException('The identity token has no expiry.', 'exp_missing');
        }
        if (($c['iss'] ?? null) !== self::AUTHORITY.'/'.$tid.'/v2.0') {
            throw new MicrosoftOidcException('The identity token issuer is wrong.', 'bad_issuer', ['tid' => $tid]);
        }
        $aud = $c['aud'] ?? null;
        if (! is_string($aud) || ! hash_equals(MicrosoftSettings::clientId(), $aud)) {
            throw new MicrosoftOidcException('The identity token was issued for a different application.', 'bad_audience');
        }
        if (! is_string($c['nonce'] ?? null) || ! hash_equals($expectedNonce, $c['nonce'])) {
            throw new MicrosoftOidcException('Sign-in could not be verified. Please try again.', 'nonce_mismatch');
        }
        $oid = (string) ($c['oid'] ?? '');
        if (! preg_match(self::GUID, $oid)) {
            throw new MicrosoftOidcException('The identity token has no user identifier.', 'oid_missing');
        }

        $upn = isset($c['preferred_username']) && is_string($c['preferred_username']) ? strtolower(trim($c['preferred_username'])) : null;
        $email = isset($c['email']) && is_string($c['email']) ? strtolower(trim($c['email'])) : null;

        return new MicrosoftIdentity(
            tenantId: $tid,
            objectId: strtolower($oid),
            upn: $upn !== '' ? $upn : null,
            email: $email !== '' ? $email : null,
            emailDomainOwnerVerified: filter_var($c['xms_edov'] ?? false, FILTER_VALIDATE_BOOLEAN),
            name: isset($c['name']) && is_string($c['name']) ? Str::limit($c['name'], 190, '') : null,
        );
    }

    /**
     * Signing keys of one tenant (cached). An unknown kid triggers at most one refresh per five minutes (key rollover).
     *
     * @return array<string,\Firebase\JWT\Key>
     * @throws MicrosoftOidcException
     */
    private function keysFor(string $tid, string $kid): array
    {
        $cacheKey = 'microsoft_jwks:'.$tid;
        $entry = Cache::get($cacheKey);

        $stale = ! is_array($entry);
        $kidUnknown = ! $stale && ($kid === '' || ! $this->hasKid($entry['jwks'], $kid));
        if ($stale || ($kidUnknown && time() - (int) $entry['fetched_at'] > 300)) {
            $entry = ['jwks' => $this->fetchJwks($tid), 'fetched_at' => time()];
            Cache::put($cacheKey, $entry, 3600);
        }

        try {
            return JWK::parseKeySet($entry['jwks'], 'RS256');
        } catch (Throwable $e) {
            throw new MicrosoftOidcException('Microsoft signing keys could not be read.', 'jwks_invalid', ['error' => class_basename($e)]);
        }
    }

    private function hasKid(array $jwks, string $kid): bool
    {
        foreach ((array) ($jwks['keys'] ?? []) as $k) {
            if (($k['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    private function fetchJwks(string $tid): array
    {
        try {
            $response = Http::withoutRedirecting()->timeout(10)->acceptJson()
                ->get(self::AUTHORITY.'/'.$tid.'/discovery/v2.0/keys');
        } catch (Throwable $e) {
            throw new MicrosoftOidcException('Microsoft signing keys could not be fetched.', 'jwks_unreachable', ['error' => class_basename($e)]);
        }

        $jwks = $response->json();
        if (! $response->successful() || ! is_array($jwks) || empty($jwks['keys'])) {
            throw new MicrosoftOidcException('Microsoft signing keys could not be fetched.', 'jwks_unavailable', ['status' => $response->status()]);
        }

        return $jwks;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
