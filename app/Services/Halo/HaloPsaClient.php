<?php

namespace App\Services\Halo;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Cache;

class HaloPsaClient
{
    protected Client $http;

    protected string $baseUrl;
    protected ?string $authServer;
    protected ?string $tenant;
    protected ?string $clientId;
    protected ?string $clientSecret;

    public function __construct()
    {
        $halo = Setting::get('halo', []);

        // Resource server URL, e.g. https://yourtenant.halopsa.com or .../api
        $baseUrl = rtrim($halo['base_url'] ?? '', '/');
        $baseUrl = preg_replace('#/api$#i', '', $baseUrl);

        if ($baseUrl === '') {
            throw new \RuntimeException('HaloPSA base URL is not configured.');
        }

        $this->baseUrl      = $baseUrl;
        $this->authServer   = isset($halo['auth_server']) && $halo['auth_server'] !== ''
            ? rtrim($halo['auth_server'], '/')
            : null;
        $this->tenant       = $halo['tenant'] ?? null;
        $this->clientId     = $halo['client_id'] ?? null;
        $this->clientSecret = $halo['api_key'] ?? null;

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 15,
        ]);
    }

    /**
     * Get (and cache) an OAuth2 access token using the Client ID + Secret flow.
     */
    protected function getAccessToken(): string
    {
        $cacheKey = 'halo_api_access_token';

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        if (!$this->authServer || !$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException(
                'HaloPSA API is not fully configured. Please set Authorisation server, Client ID and API key.'
            );
        }

        $tokenUrl = rtrim($this->authServer, '/') . '/token';
        if (!empty($this->tenant)) {
            $tokenUrl .= '?tenant=' . urlencode($this->tenant);
        }

        $client   = new Client(['timeout' => 15]);
        $response = $client->post($tokenUrl, [
            'form_params' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'all',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true) ?: [];

        if (empty($data['access_token'])) {
            throw new \RuntimeException('HaloPSA auth failed – no access_token in response.');
        }

        $token = $data['access_token'];
        $ttl   = isset($data['expires_in'])
            ? max(60, (int) $data['expires_in'] - 60)
            : 600;

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Core request helper – always sends auth headers and ensures we hit /api/... .
     * Returns decoded JSON array.
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        $uri = ltrim($uri, '/');
        if (stripos($uri, 'api/') !== 0) {
            $uri = 'api/' . $uri;
        }

        $options['headers'] = array_merge(
            $options['headers'] ?? [],
            $this->headers()
        );

        $response = $this->http->request($method, $uri, $options);

        $json = json_decode((string) $response->getBody(), true);

        return is_array($json) ? $json : [];
    }

    /**
     * List Halo clients (companies).
     *
     * Docs in your tenant show this as "client" – the actual endpoint typically being /api/client.
     * We normalise a bunch of possible wrappers and return a plain list of client arrays.
     */
    public function listClients(int $page = 1, int $pageSize = 100): array
    {
        $result = $this->request('GET', 'client', [
            'query' => [
                'page'     => $page,
                'pageSize' => $pageSize,
            ],
        ]);

        // Try common wrappers
        if (isset($result['clients']) && is_array($result['clients'])) {
            return $result['clients'];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        if (isset($result['Results']) && is_array($result['Results'])) {
            return $result['Results'];
        }

        // If it's already a numeric list
        if (is_array($result) && array_is_list($result)) {
            return $result;
        }

        // Fallback: if there's a single top-level key whose value is a list
        if (is_array($result) && count($result) === 1) {
            $only = reset($result);
            if (is_array($only) && array_is_list($only)) {
                return $only;
            }
        }

        return [];
    }

    /**
     * Get a single client by ID.
     */
    public function getClient(int $clientId): array
    {
        return $this->request('GET', 'client/' . $clientId);
    }

    /**
     * List all assets for a given client so we can find "Domain" assets.
     */
    public function listAssetsForClient(int $clientId): array
    {
        $result = $this->request('GET', 'assets', [
            'query' => [
                'clientId'            => $clientId,
                'includeCustomFields' => 'true',
            ],
        ]);

        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        if (isset($result['Results']) && is_array($result['Results'])) {
            return $result['Results'];
        }

        if (is_array($result) && array_is_list($result)) {
            return $result;
        }

        return [];
    }

    /**
     * List only "Domain" assets for a client (optional helper).
     */
    public function listDomainAssetsForClient(int $clientId): array
    {
        $assets = $this->listAssetsForClient($clientId);

        return array_values(array_filter($assets, function (array $asset) {
            $typeName = $asset['AssetType']['Name']
                ?? $asset['AssetTypeName']
                ?? $asset['TypeName']
                ?? null;

            return $typeName && strcasecmp($typeName, 'Domain') === 0;
        }));
    }

    public function createDomainAsset(array $data): array
    {
        return $this->request('POST', 'assets', [
            'json' => $data,
        ]);
    }

    public function createTicket(array $data): array
    {
        return $this->request('POST', 'tickets', [
            'json' => $data,
        ]);
    }

    /**
     * Generic asset update helper (PUT /api/assets/{id}).
     */
    public function updateAsset(int $assetId, array $data): array
    {
        return $this->request('PUT', 'assets/' . $assetId, [
            'json' => $data,
        ]);
    }

    /**
     * Helper to append DomainDash info into a Halo "Domain" asset's Notes field.
     *
     * You can call this from a sync job or from the client import flow.
     *
     * Example usage:
     *   $halo->updateDomainAssetNotesFromDomain($asset, $domain);
     */
    public function updateDomainAssetNotesFromDomain(array $asset, \App\Models\Domain $domain): array
    {
        $assetId = $asset['Id'] ?? $asset['id'] ?? null;
        if (!$assetId) {
            return $asset;
        }

        // Existing notes (best-effort)
        $currentNotes = $asset['Notes'] ?? $asset['notes'] ?? '';

        $lines = [];
        $lines[] = '--- DomainDash sync ---';
        $lines[] = 'Domain: ' . $domain->name;
        if (!empty($domain->expires_at)) {
            $lines[] = 'Expiry: ' . $domain->expires_at;
        }
        if (!empty($domain->nameservers)) {
            $lines[] = 'Name servers: ' . (is_array($domain->nameservers)
                ? implode(', ', $domain->nameservers)
                : $domain->nameservers);
        }
        if (!empty($domain->dns_records)) {
            $lines[] = 'DNS records:';
            $lines[] = is_string($domain->dns_records)
                ? $domain->dns_records
                : json_encode($domain->dns_records);
        }

        $newNotes = trim(
            rtrim((string) $currentNotes) . "\n\n" . implode("\n", $lines)
        );

        return $this->updateAsset($assetId, [
            'Notes' => $newNotes,
        ]);
    }
}
