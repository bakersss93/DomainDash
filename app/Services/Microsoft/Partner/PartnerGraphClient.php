<?php

namespace App\Services\Microsoft\Partner;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only Microsoft Graph client for the partner's customer list (application permissions, client credentials).
 *
 *  - fixed hosts only: login.microsoftonline.com for the token, graph.microsoft.com for data;
 *  - redirects are never followed; @odata.nextLink is followed only when it points at https://graph.microsoft.com;
 *  - the partner tenant ID and the app ID are validated as GUIDs before they reach a URL or a request body;
 *  - the access token is cached encrypted until shortly before it expires; the secret is never logged or returned.
 *
 * Endpoints (Microsoft Learn, Graph v1.0):
 *   GET /contracts                                  Directory.Read.All (application)  -> customerId, defaultDomainName, displayName
 *   GET /tenantRelationships/delegatedAdminCustomers DelegatedAdminRelationship.Read.All -> id (tenant ID), displayName
 */
class PartnerGraphClient
{
    public const LOGIN_HOST = 'login.microsoftonline.com';
    public const GRAPH_HOST = 'graph.microsoft.com';
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const MAX_PAGES = 100;

    /**
     * @return list<array{tenant_id:string,display_name:?string,default_domain:?string,source:string}>
     */
    public function listCustomers(?string $source = null): array
    {
        $source ??= PartnerSettings::source();

        $byTenant = [];
        if ($source === PartnerSettings::SOURCE_DELEGATED || $source === PartnerSettings::SOURCE_BOTH) {
            foreach ($this->delegatedAdminCustomers() as $row) {
                $byTenant[$row['tenant_id']] = $row;
            }
        }
        if ($source === PartnerSettings::SOURCE_CONTRACTS || $source === PartnerSettings::SOURCE_BOTH) {
            foreach ($this->contracts() as $row) {
                $existing = $byTenant[$row['tenant_id']] ?? null;
                $byTenant[$row['tenant_id']] = $existing === null ? $row : [
                    'tenant_id' => $row['tenant_id'],
                    'display_name' => $row['display_name'] ?? $existing['display_name'],
                    'default_domain' => $row['default_domain'] ?? $existing['default_domain'],
                    'source' => PartnerSettings::SOURCE_BOTH,
                ];
            }
        }

        return array_values($byTenant);
    }

    /** Cheap connection check: acquire a token and read one customer record. */
    public function test(?string $source = null): array
    {
        $source ??= PartnerSettings::source();
        $this->token(true);

        $path = $source === PartnerSettings::SOURCE_CONTRACTS
            ? '/contracts?$top=1'
            : '/tenantRelationships/delegatedAdminCustomers?$top=1';
        $this->getJson(self::GRAPH.$path);

        return ['ok' => true, 'source' => $source];
    }

    /** @return list<array{tenant_id:string,display_name:?string,default_domain:?string,source:string}> */
    private function contracts(): array
    {
        $out = [];
        foreach ($this->pages(self::GRAPH.'/contracts?$select=customerId,defaultDomainName,displayName&$top=999') as $item) {
            $id = strtolower(trim((string) ($item['customerId'] ?? '')));
            if (! PartnerSettings::isGuid($id)) {
                continue;
            }
            $out[] = [
                'tenant_id' => $id,
                'display_name' => $this->text($item['displayName'] ?? null),
                'default_domain' => ($d = $this->text($item['defaultDomainName'] ?? null)) !== null ? strtolower($d) : null,
                'source' => PartnerSettings::SOURCE_CONTRACTS,
            ];
        }

        return $out;
    }

    /** @return list<array{tenant_id:string,display_name:?string,default_domain:?string,source:string}> */
    private function delegatedAdminCustomers(): array
    {
        $out = [];
        foreach ($this->pages(self::GRAPH.'/tenantRelationships/delegatedAdminCustomers?$top=300') as $item) {
            $id = strtolower(trim((string) ($item['id'] ?? '')));
            if (! PartnerSettings::isGuid($id)) {
                continue;
            }
            $out[] = [
                'tenant_id' => $id,
                'display_name' => $this->text($item['displayName'] ?? null),
                'default_domain' => null,
                'source' => PartnerSettings::SOURCE_DELEGATED,
            ];
        }

        return $out;
    }

    /** @return \Generator<int,array> every item of every page */
    private function pages(string $url): \Generator
    {
        for ($page = 0; $url !== null; $page++) {
            if ($page >= self::MAX_PAGES) {
                throw new PartnerGraphException('Microsoft returned more pages than expected; the sync was stopped.', 'too_many_pages');
            }

            $json = $this->getJson($url);
            foreach ((array) ($json['value'] ?? []) as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $next = $json['@odata.nextLink'] ?? null;
            $url = null;
            if ($next !== null && $next !== '') {
                if (! is_string($next) || ! self::isGraphUrl($next)) {
                    throw new PartnerGraphException('Microsoft returned a next-page link to an unexpected host; the sync was stopped.', 'unsafe_next_link');
                }
                $url = $next;
            }
        }
    }

    /** https, host exactly graph.microsoft.com, default port, no credentials in the URL. */
    public static function isGraphUrl(string $url): bool
    {
        $p = parse_url($url);
        if (! is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (strtolower((string) ($p['host'] ?? '')) !== self::GRAPH_HOST) {
            return false;
        }
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return false;
        }

        return true;
    }

    private function getJson(string $url, bool $retryAuth = true): array
    {
        if (! self::isGraphUrl($url)) {
            throw new PartnerGraphException('Refusing to call a non-Microsoft Graph URL.', 'unsafe_url');
        }

        $token = $this->token();

        try {
            $response = Http::withToken($token)->withoutRedirecting()->timeout(30)->acceptJson()->get($url);
        } catch (Throwable $e) {
            throw new PartnerGraphException('Microsoft Graph could not be reached ('.class_basename($e).').', 'unreachable');
        }

        if ($response->status() === 401 && $retryAuth) {
            $this->forgetToken();

            return $this->getJson($url, false);
        }

        if ($response->redirect()) {
            throw new PartnerGraphException('Microsoft Graph answered with a redirect, which is not followed.', 'redirect', $response->status());
        }

        if (! $response->successful()) {
            throw $this->graphError($response);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new PartnerGraphException('Microsoft Graph returned an unreadable response.', 'bad_response', $response->status());
        }

        return $json;
    }

    private function graphError(Response $response): PartnerGraphException
    {
        $status = $response->status();
        $code = $this->text($response->json('error.code'));
        $code = $code !== null ? preg_replace('/[^A-Za-z0-9_.-]/', '', $code) : null;

        $hint = match (true) {
            $status === 401 => 'The token was rejected.',
            $status === 403 => 'The app registration lacks the required application permission or admin consent (see docs/microsoft-partner-connection.md).',
            $status === 429 => 'Microsoft is throttling requests; try again later.',
            $status >= 500 => 'Microsoft Graph had a temporary problem; try again later.',
            default => 'The request was not accepted.',
        };

        return new PartnerGraphException(
            "Microsoft Graph returned HTTP {$status}".($code ? " ({$code})" : '').". {$hint}",
            $status === 403 ? 'forbidden' : ($status === 429 ? 'throttled' : 'graph_error'),
            $status,
        );
    }

    private function token(bool $fresh = false): string
    {
        $tenant = PartnerSettings::partnerTenantId();
        $app = PartnerSettings::clientId();
        $secret = PartnerSettings::clientSecret();

        if (! PartnerSettings::isGuid($tenant)) {
            throw new PartnerGraphException('The partner tenant ID is not a valid GUID.', 'invalid_tenant_id');
        }
        if (! PartnerSettings::isGuid($app)) {
            throw new PartnerGraphException('The application (client) ID is not a valid GUID.', 'invalid_client_id');
        }
        if ($secret === '') {
            throw new PartnerGraphException('No client secret is stored. Enter the secret in Settings > Microsoft Partner.', 'no_secret');
        }

        $key = $this->cacheKey($tenant, $app, $secret);
        if (! $fresh) {
            try {
                $cached = Cache::get($key);
                if (is_string($cached) && $cached !== '') {
                    return Crypt::decryptString($cached);
                }
            } catch (Throwable) {
                // unreadable cache entry: fetch a new token
            }
        }

        try {
            $response = Http::asForm()->withoutRedirecting()->timeout(15)->acceptJson()
                ->post('https://'.self::LOGIN_HOST.'/'.$tenant.'/oauth2/v2.0/token', [
                    'client_id' => $app,
                    'client_secret' => $secret,
                    'grant_type' => 'client_credentials',
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);
        } catch (Throwable $e) {
            throw new PartnerGraphException('Microsoft sign-in could not be reached ('.class_basename($e).').', 'token_unreachable');
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw $this->tokenError($response);
        }

        $ttl = max(60, min(3600, (int) $response->json('expires_in', 3600)) - 120);
        Cache::put($key, Crypt::encryptString($token), now()->addSeconds($ttl));

        return $token;
    }

    private function tokenError(Response $response): PartnerGraphException
    {
        $error = preg_replace('/[^A-Za-z0-9_]/', '', (string) $response->json('error', '')) ?: null;
        $description = (string) $response->json('error_description', '');
        $aadsts = preg_match('/AADSTS\d{3,7}/', $description, $m) ? $m[0] : null;

        $hint = match ($aadsts) {
            'AADSTS7000215' => 'The client secret is wrong (use the secret VALUE, not the secret ID).',
            'AADSTS7000222' => 'The client secret has expired.',
            'AADSTS700016' => 'The application (client) ID was not found in the partner tenant.',
            'AADSTS90002', 'AADSTS900023' => 'The partner tenant ID was not found.',
            default => match ($error) {
                'invalid_client' => 'The client ID or secret was rejected.',
                'unauthorized_client' => 'The app is not allowed to use client credentials.',
                default => 'Check the tenant ID, application ID and secret.',
            },
        };

        return new PartnerGraphException(
            'Microsoft refused to issue a token'.($aadsts ? " ({$aadsts})" : ($error ? " ({$error})" : '')).". {$hint}",
            'token_failed',
            $response->status(),
        );
    }

    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey(PartnerSettings::partnerTenantId(), PartnerSettings::clientId(), PartnerSettings::clientSecret()));
    }

    private function cacheKey(string $tenant, string $app, string $secret): string
    {
        // The secret takes part in the key so rotating it can never reuse a token issued for the old one.
        return 'ms-partner-token:'.hash('sha256', $tenant.'|'.$app.'|'.$secret);
    }

    private function text(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, 255);
    }
}
