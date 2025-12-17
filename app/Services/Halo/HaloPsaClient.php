<?php

namespace App\Services\Halo;

use App\Models\Setting;
use App\Support\WhoisFormatter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // Resource server URL
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
            'timeout'  => 30,
        ]);
    }

    /**
     * Get (and cache) an OAuth2 access token
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
     * Core request helper
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
     * List Halo clients
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

        if (is_array($result) && array_is_list($result)) {
            return $result;
        }

        if (is_array($result) && count($result) === 1) {
            $only = reset($result);
            if (is_array($only) && array_is_list($only)) {
                return $only;
            }
        }

        return [];
    }

    /**
     * Get a single client by ID
     */
    public function getClient(int $clientId): array
    {
        return $this->request('GET', 'client/' . $clientId);
    }

    /**
     * List all assets for a given client
     */
    public function listAssetsForClient(int $clientId, ?int $assetTypeId = null): array
    {
        $query = [
            'client_id' => $clientId,
            'count' => 100
        ];

        if ($assetTypeId) {
            $query['assettype_id'] = $assetTypeId;
        }

        $result = $this->request('GET', 'asset', [
            'query' => $query
        ]);

        // Handle different response structures
        if (isset($result['assets']) && is_array($result['assets'])) {
            return $result['assets'];
        }

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
     * List only "Domain" assets for a client
     */
    public function listDomainAssetsForClient(int $clientId): array
    {
        $assets = $this->listAssetsForClient($clientId);

        Log::info('Filtering domain assets', [
            'client_id' => $clientId,
            'total_assets' => count($assets)
        ]);

        $domainAssets = array_values(array_filter($assets, function (array $asset) {
            // Try different possible field names for asset type
            $typeName = $asset['assettype_name']
                ?? $asset['assettypename']
                ?? $asset['AssetTypeName']
                ?? $asset['TypeName']
                ?? $asset['asset_type_name']
                ?? null;

            // Also check nested AssetType object
            if (!$typeName && isset($asset['AssetType'])) {
                $typeName = $asset['AssetType']['Name']
                    ?? $asset['AssetType']['name']
                    ?? null;
            }

            $isDomain = $typeName && strcasecmp($typeName, 'Domain') === 0;

            if ($isDomain) {
                Log::info('Found domain asset', [
                    'asset_id' => $asset['id'] ?? $asset['Id'] ?? 'unknown',
                    'type' => $typeName
                ]);
            }

            return $isDomain;
        }));

        Log::info('Domain assets filtered', [
            'client_id' => $clientId,
            'domain_assets_found' => count($domainAssets)
        ]);

        return $domainAssets;
    }

    /**
     * Get a single asset by ID
     */
    public function getAsset(int $assetId): array
    {
        return $this->request('GET', 'asset/' . $assetId);
    }

    /**
     * Create a domain asset
     */
    public function createDomainAsset(array $data): array
    {
        // Halo's Asset POST endpoint expects an array of assets, even when
        // creating a single item. Sending a plain object results in a 400 with
        // "Cannot deserialize the current JSON object". Wrap the payload in an
        // array to match the expected contract.
        $result = $this->request('POST', 'asset', [
            'json' => [$data],
        ]);

        // Some Halo environments return an array of created assets. Unwrap to a
        // single asset structure so callers can rely on consistent shape.
        if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        return $result;
    }

    /**
     * Update an asset
     */
    public function updateAsset(int $assetId, array $data): array
    {
        // Halo asset updates use POST on /asset with an array payload, the same
        // shape as create. Including the ID tells Halo to update instead of
        // create. Using PUT on /asset/{id} returns 405.
        $payload = array_merge(['id' => $assetId], $data);

        $result = $this->request('POST', 'asset', [
            'json' => [$payload],
        ]);

        // Unwrap array responses to a single asset for consistency
        if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        return $result;
    }

    /**
     * Find an asset for a client by inventory number / domain name
     */
    public function findAssetByInventory(int $clientId, int $assetTypeId, string $inventoryNumber): ?array
    {
        $result = $this->request('GET', 'asset', [
            'query' => [
                'client_id' => $clientId,
                'assettype_id' => $assetTypeId,
                'search' => $inventoryNumber,
                'count' => 100,
            ],
        ]);

        $assets = $result['assets']
            ?? $result['data']
            ?? $result['Results']
            ?? (array_is_list($result) ? $result : []);

        foreach ($assets as $asset) {
            $inventory = $asset['inventory_number']
                ?? $asset['InventoryNumber']
                ?? $asset['inventory_id']
                ?? $asset['inventoryid']
                ?? $asset['key_field']
                ?? $asset['KeyField']
                ?? null;

            if ($inventory && strcasecmp((string) $inventory, $inventoryNumber) === 0) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Try to determine the default site for a client
     */
    public function getDefaultSiteForClient(int $clientId): array
    {
        $client = $this->getClient($clientId);

        $siteId = $client['site_id']
            ?? $client['siteid']
            ?? $client['SiteId']
            ?? $client['site_id_default']
            ?? null;

        $siteName = $client['site_name']
            ?? $client['sitename']
            ?? $client['SiteName']
            ?? null;

        // If no direct site info is present, try the sites list
        if ((!$siteId || !$siteName) && isset($client['sites']) && is_array($client['sites'])) {
            foreach ($client['sites'] as $site) {
                $candidateName = $site['name'] ?? $site['Name'] ?? null;
                $candidateId = $site['id'] ?? $site['Id'] ?? null;

                if ($candidateName && strcasecmp($candidateName, 'Main') === 0) {
                    $siteId = $candidateId ?? $siteId;
                    $siteName = $candidateName;
                    break;
                }

                if (!$siteId && $candidateId) {
                    $siteId = $candidateId;
                    $siteName = $candidateName;
                }
            }
        }

        if (!$siteName && $siteId) {
            $siteName = 'Main';
        }

        return [
            'site_id' => $siteId,
            'site_name' => $siteName,
        ];
    }

    /**
     * Update domain asset with DNS records
     */
    public function syncDomainAssetDns(\App\Models\Domain $domain, int $assetId, ?array $dnsRecords = null): array
    {
        try {
            Log::info('Syncing DNS to HaloPSA asset', [
                'domain' => $domain->name,
                'asset_id' => $assetId
            ]);

            // Fetch the current asset to get existing notes
            $asset = $this->getAsset($assetId);
            $currentNotes = $asset['notes'] ?? $asset['Notes'] ?? '';

            // Format DNS records
            $dnsNotes = $this->formatDnsRecordsForNotes($dnsRecords ?? []);

            // Format WHOIS details if available
            $whoisNotes = WhoisFormatter::formatText(
                $domain->whois_data ?? [],
                $domain->name,
                $domain->whois_synced_at
            );

            // Combine with domain info
            $lines = [];
            $lines[] = '=== DomainDash Domain Info ===';
            $lines[] = 'Domain: ' . $domain->name;
            if ($domain->registrar) {
                $lines[] = 'Registrar: ' . $domain->registrar;
            }
            if ($domain->expiry_date) {
                $lines[] = 'Expiry: ' . $domain->expiry_date;
            }
            $lines[] = '';
            $lines[] = $dnsNotes;

            if ($whoisNotes) {
                $lines[] = '';
                $lines[] = $whoisNotes;
            }

            $newNotes = trim(rtrim((string) $currentNotes) . "\n\n" . implode("\n", $lines));

            // Update the asset
            $result = $this->updateAsset($assetId, [
                'notes' => $newNotes
            ]);

            return [
                'success' => true,
                'asset_id' => $assetId,
                'data' => $result
            ];
        } catch (\Exception $e) {
            Log::error('HaloPSA syncDomainAssetDns error: ' . $e->getMessage(), [
                'domain' => $domain->name,
                'asset_id' => $assetId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Format DNS records for notes
     */
    protected function formatDnsRecordsForNotes(array $dnsRecords): string
    {
        if (empty($dnsRecords)) {
            return "No DNS records available.";
        }

        $notes = "=== DNS Records (Auto-synced) ===\n";
        $notes .= "Last Updated: " . now()->format('Y-m-d H:i:s') . "\n\n";

        $recordsByType = [];
        foreach ($dnsRecords as $record) {
            $type = is_object($record) ? ($record->type ?? 'UNKNOWN') : ($record['type'] ?? 'UNKNOWN');
            if (!isset($recordsByType[$type])) {
                $recordsByType[$type] = [];
            }
            $recordsByType[$type][] = $record;
        }

        foreach ($recordsByType as $type => $records) {
            $notes .= "--- {$type} Records ---\n";
            foreach ($records as $record) {
                if (is_object($record)) {
                    $hostname = $record->hostName ?? $record->hostname ?? '';
                    $content = $record->content ?? '';
                    $ttl = $record->ttl ?? '';
                    $prio = isset($record->prio) && $record->prio > 0 ? " (Priority: {$record->prio})" : '';
                } else {
                    $hostname = $record['hostName'] ?? $record['hostname'] ?? '';
                    $content = $record['content'] ?? '';
                    $ttl = $record['ttl'] ?? '';
                    $prio = isset($record['prio']) && $record['prio'] > 0 ? " (Priority: {$record['prio']})" : '';
                }
                
                $notes .= "  {$hostname} -> {$content} [TTL: {$ttl}]{$prio}\n";
            }
            $notes .= "\n";
        }

        return $notes;
    }

    /**
     * Create a ticket
     */
    public function createTicket(array $data): array
    {
        return $this->request('POST', 'tickets', [
            'json' => $data,
        ]);
    }
}
