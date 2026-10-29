<?php

namespace App\Services\Halo;

use App\Models\Setting;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\Integrations\IntegrationHttp;
use App\Support\WhoisFormatter;
use Generator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HaloPsaClient
{
    public const PROVIDER = 'halo';

    /** Delimiters of the block DomainDash owns inside an asset's notes. */
    public const NOTES_BEGIN = '[[DOMAINDASH-BEGIN]]';
    public const NOTES_END = '[[DOMAINDASH-END]]';

    /**
     * Query parameter for "changed since" on asset lists. Halo tenants that do
     * not know it ignore it (full list is returned), so a daily full
     * reconcile remains the safety net. Unverified against a live tenant.
     */
    public const ASSET_SINCE_PARAM = 'lastupdatefromdate';

    protected string $baseUrl;
    protected string $authServer;
    protected ?string $tenant;
    protected ?string $clientId;
    protected ?string $clientSecret;

    /** @var array<int,array> */
    protected array $siteCache = [];

    public function __construct()
    {
        $halo = Setting::get('halo', []);

        // Resource server URL (without the /api suffix, which is re-added per request)
        $baseUrl = rtrim((string) ($halo['base_url'] ?? ''), '/');
        $baseUrl = preg_replace('#/api$#i', '', $baseUrl);

        if ($baseUrl === '') {
            throw new IntegrationConfigurationException('HaloPSA base URL is not configured.');
        }

        $authServer = isset($halo['auth_server']) && $halo['auth_server'] !== ''
            ? rtrim((string) $halo['auth_server'], '/')
            : $baseUrl . '/auth';

        // Credentials are sent to these hosts: require TLS.
        foreach (['base URL' => $baseUrl, 'authorisation server URL' => $authServer] as $label => $url) {
            if (! preg_match('#^https://[^/\s]+#i', $url)) {
                throw new IntegrationConfigurationException("HaloPSA {$label} must use https://.");
            }
        }

        $this->baseUrl      = $baseUrl;
        $this->authServer   = $authServer;
        $this->tenant       = isset($halo['tenant']) && $halo['tenant'] !== '' ? (string) $halo['tenant'] : null;
        $this->clientId     = $halo['client_id'] ?? null;
        $this->clientSecret = $halo['api_key'] ?? null;
    }

    /**
     * Is Halo configured well enough to attempt calls? (No exceptions.)
     */
    public static function isConfigured(): bool
    {
        $halo = Setting::get('halo', []);

        return ! empty($halo['base_url']) && ! empty($halo['client_id']) && ! empty($halo['api_key']);
    }

    // ------------------------------------------------------------------
    // Auth: the one and only token path
    // ------------------------------------------------------------------

    /**
     * Cache key is scoped to tenant/base_url/auth server/client credentials so
     * changing any of them in Settings never re-uses another identity's token.
     */
    public function tokenCacheKey(): string
    {
        return 'halo_api_access_token_' . sha1(implode('|', [
            $this->baseUrl,
            $this->authServer,
            (string) $this->tenant,
            (string) $this->clientId,
            sha1((string) $this->clientSecret),
        ]));
    }

    public function forgetAccessToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    protected function getAccessToken(): string
    {
        $key = $this->tokenCacheKey();

        if ($token = Cache::get($key)) {
            return $token;
        }

        if (! $this->clientId || ! $this->clientSecret) {
            throw new IntegrationConfigurationException(
                'HaloPSA API is not fully configured. Please set Authorisation server, Client ID and API key.'
            );
        }

        // One worker fetches the token; concurrent callers wait and re-use it.
        try {
            return Cache::lock($key . ':lock', (int) config('integrations.halo.token_lock_seconds', 10))
                ->block(5, function () use ($key) {
                    return Cache::get($key) ?: $this->fetchAccessToken($key);
                });
        } catch (LockTimeoutException) {
            return Cache::get($key) ?: $this->fetchAccessToken($key);
        }
    }

    protected function fetchAccessToken(string $cacheKey): string
    {
        $tokenUrl = $this->authServer . '/token';
        if ($this->tenant) {
            $tokenUrl .= '?tenant=' . urlencode($this->tenant);
        }

        $response = IntegrationHttp::send(
            self::PROVIDER,
            'POST',
            $tokenUrl,
            fn () => IntegrationHttp::pending()->asForm()->post($tokenUrl, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'all',
            ]),
            idempotent: true // token issuance has no side effects worth protecting
        );

        $data = $response->json();
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! $token) {
            throw new IntegrationApiException(
                'HaloPSA auth failed: no access_token in response.',
                self::PROVIDER,
                $response->status()
            );
        }

        $ttl = isset($data['expires_in']) ? max(60, (int) $data['expires_in'] - 60) : 600;
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    // ------------------------------------------------------------------
    // Core request + pagination
    // ------------------------------------------------------------------

    /**
     * Core request helper. Throws IntegrationApiException on any non-2xx.
     * Only GETs are retried (429/5xx/connection) - POSTs are sent once.
     *
     * Supported $options: query (array), json (array), body (string) with
     * content_type (string).
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        $method = strtoupper($method);
        $uri = ltrim($uri, '/');
        if (stripos($uri, 'api/') !== 0) {
            $uri = 'api/' . $uri;
        }
        $url = $this->baseUrl . '/' . $uri;
        $idempotent = $method === 'GET';

        $reauthenticated = false;

        while (true) {
            try {
                $response = IntegrationHttp::send(
                    self::PROVIDER,
                    $method,
                    $url,
                    fn () => $this->dispatch($method, $url, $options),
                    $idempotent
                );
            } catch (IntegrationApiException $e) {
                // An expired/revoked token is rejected before processing: safe to refresh once and resend.
                if ($e->status === 401 && ! $reauthenticated) {
                    $reauthenticated = true;
                    $this->forgetAccessToken();
                    continue;
                }

                throw $e;
            }

            $json = $response->json();

            return is_array($json) ? $json : [];
        }
    }

    private function dispatch(string $method, string $url, array $options)
    {
        $pending = IntegrationHttp::pending()
            ->withToken($this->getAccessToken())
            ->acceptJson();

        $query = $options['query'] ?? [];

        if (isset($options['body'])) {
            $pending = $pending->withBody((string) $options['body'], $options['content_type'] ?? 'application/json');

            return $pending->send($method, $url, ['query' => $query]);
        }

        if (isset($options['json'])) {
            $pending = $pending->withBody(json_encode($options['json'], JSON_UNESCAPED_SLASHES), 'application/json');

            return $pending->send($method, $url, ['query' => $query]);
        }

        return $pending->send($method, $url, ['query' => $query]);
    }

    /**
     * Pull the record list out of Halo's wrapper object.
     */
    protected function extractList(array $result, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return array_values(array_filter($result[$key], 'is_array'));
            }
        }

        foreach (['data', 'Results'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                return array_values(array_filter($result[$key], 'is_array'));
            }
        }

        if (array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }

        return [];
    }

    /**
     * Walk a Halo list endpoint page by page (pageinate=true, page_size,
     * page_no). Yields one array of rows per page; stops on a short page or
     * once record_count rows have been seen.
     *
     * @return Generator<int, array>
     */
    public function paginate(string $uri, array $query, array $listKeys, ?int $pageSize = null): Generator
    {
        $pageSize ??= (int) config('integrations.halo.page_size', 100);
        $maxPages = (int) config('integrations.halo.max_pages', 500);
        $page = 1;
        $seen = 0;
        $previousFirstId = null;

        do {
            $result = $this->request('GET', $uri, [
                'query' => array_merge($query, [
                    'pageinate' => 'true',
                    'page_size' => $pageSize,
                    'page_no'   => $page,
                ]),
            ]);

            $rows = $this->extractList($result, $listKeys);
            $total = isset($result['record_count']) ? (int) $result['record_count'] : null;

            $firstId = $rows[0]['id'] ?? $rows[0]['Id'] ?? null;
            if ($page > 1 && $firstId !== null && $firstId === $previousFirstId) {
                // Server ignored page_no and repeated itself: avoid looping forever.
                return;
            }
            $previousFirstId = $firstId;

            if ($rows !== []) {
                yield $rows;
            }

            $seen += count($rows);
            $more = count($rows) >= $pageSize && ($total === null || $seen < $total);
            $page++;
        } while ($more && $page <= $maxPages);
    }

    /**
     * @return array<int,array> every row of every page
     */
    protected function paginateAll(string $uri, array $query, array $listKeys, ?int $pageSize = null): array
    {
        $all = [];
        foreach ($this->paginate($uri, $query, $listKeys, $pageSize) as $rows) {
            array_push($all, ...$rows);
        }

        return $all;
    }

    // ------------------------------------------------------------------
    // Clients
    // ------------------------------------------------------------------

    /**
     * @return Generator<int, array>
     */
    public function clientPages(bool $includeInactive = false): Generator
    {
        return $this->paginate('client', [
            'order' => 'name',
            'includeinactive' => $includeInactive ? 'true' : 'false',
        ], ['clients']);
    }

    /**
     * List every Halo client (all pages).
     */
    public function listClients(bool $includeInactive = false): array
    {
        $all = [];
        foreach ($this->clientPages($includeInactive) as $rows) {
            array_push($all, ...$rows);
        }

        return $all;
    }

    /**
     * Get a single client by ID
     */
    public function getClient(int $clientId): array
    {
        return $this->request('GET', 'client/' . $clientId);
    }

    // ------------------------------------------------------------------
    // Assets
    // ------------------------------------------------------------------

    /**
     * Resolve (and cache for 24h) the id of the "Domain" asset type.
     */
    public function getDomainAssetTypeId(): ?int
    {
        $key = 'halo_domain_asset_type_' . sha1($this->baseUrl . '|' . (string) $this->tenant);

        if ($cached = Cache::get($key)) {
            return (int) $cached;
        }

        $types = $this->paginateAll('assettype', [], ['assettypes']);

        foreach ($types as $type) {
            $name = $type['name'] ?? $type['Name'] ?? null;
            if ($name && strcasecmp(trim($name), 'Domain') === 0) {
                $id = (int) ($type['id'] ?? $type['Id'] ?? 0);
                if ($id > 0) {
                    Cache::put($key, $id, (int) config('integrations.halo.domain_asset_type_ttl', 86400));

                    return $id;
                }
            }
        }

        Log::warning('Could not find Domain asset type in HaloPSA');

        return null;
    }

    /**
     * Walk assets page by page, filtered server-side.
     *
     * @return Generator<int, array>
     */
    public function assetPages(?int $clientId = null, ?int $assetTypeId = null, ?string $modifiedSince = null): Generator
    {
        $query = [];
        if ($clientId) {
            $query['client_id'] = $clientId;
        }
        if ($assetTypeId) {
            $query['assettype_id'] = $assetTypeId;
        }
        if ($modifiedSince) {
            $query[self::ASSET_SINCE_PARAM] = $modifiedSince;
        }

        return $this->paginate('asset', $query, ['assets']);
    }

    /**
     * List all assets for a given client (every page).
     */
    public function listAssetsForClient(int $clientId, ?int $assetTypeId = null): array
    {
        $all = [];
        foreach ($this->assetPages($clientId, $assetTypeId) as $rows) {
            array_push($all, ...$rows);
        }

        return $all;
    }

    /**
     * List only "Domain" assets for a client (server-side asset type filter).
     */
    public function listDomainAssetsForClient(int $clientId): array
    {
        $typeId = $this->getDomainAssetTypeId();

        if (! $typeId) {
            return [];
        }

        return $this->listAssetsForClient($clientId, $typeId);
    }

    /**
     * Flatten a Halo asset row to the fields DomainDash stores locally.
     */
    public static function normalizeAsset(array $asset): array
    {
        $typeName = $asset['assettype_name']
            ?? $asset['assettypename']
            ?? $asset['AssetTypeName']
            ?? $asset['asset_type_name']
            ?? ($asset['AssetType']['Name'] ?? $asset['AssetType']['name'] ?? null);

        $updated = $asset['datemodified'] ?? $asset['lastupdate'] ?? $asset['last_updated'] ?? null;
        $ts = $updated ? strtotime((string) $updated) : false;

        return [
            'halo_id'          => (int) ($asset['id'] ?? $asset['Id'] ?? 0),
            'halo_client_id'   => (int) ($asset['client_id'] ?? $asset['clientid'] ?? $asset['ClientId'] ?? 0),
            'assettype_id'     => (int) ($asset['assettype_id'] ?? $asset['AssetTypeId'] ?? 0),
            'assettype_name'   => $typeName,
            'name'             => $asset['name'] ?? $asset['Name'] ?? $asset['key_field'] ?? null,
            'inventory_number' => $asset['inventory_number']
                ?? $asset['InventoryNumber']
                ?? $asset['inventory_id']
                ?? $asset['key_field']
                ?? $asset['KeyField']
                ?? null,
            'remote_updated_at' => $ts ? gmdate('Y-m-d H:i:s', $ts) : null,
        ];
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
        // array to match the expected contract. Single attempt: no blind retry.
        $result = $this->request('POST', 'asset', [
            'json' => [$data],
        ]);

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

        if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        return $result;
    }

    /**
     * Find an asset for a client by inventory number / domain name.
     * Throws on API failure (never "null because the call failed").
     */
    public function findAssetByInventory(int $clientId, int $assetTypeId, string $inventoryNumber): ?array
    {
        foreach ($this->paginate('asset', [
            'client_id' => $clientId,
            'assettype_id' => $assetTypeId,
            'search' => $inventoryNumber,
        ], ['assets']) as $assets) {
            foreach ($assets as $asset) {
                $inventory = self::normalizeAsset($asset)['inventory_number'];

                if ($inventory && strcasecmp((string) $inventory, $inventoryNumber) === 0) {
                    return $asset;
                }
            }
        }

        return null;
    }

    /**
     * Try to determine the default site for a client
     */
    public function getDefaultSiteForClient(int $clientId): array
    {
        if (isset($this->siteCache[$clientId])) {
            return $this->siteCache[$clientId];
        }

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

        return $this->siteCache[$clientId] = [
            'site_id' => $siteId,
            'site_name' => $siteName,
        ];
    }

    /**
     * Replace (or insert) the DomainDash-managed block inside asset notes.
     * Re-running with the same content yields identical notes (idempotent);
     * text outside the markers is never touched.
     */
    public static function replaceManagedBlock(string $notes, string $block): string
    {
        $managed = self::NOTES_BEGIN . "\n" . trim($block) . "\n" . self::NOTES_END;

        $pattern = '/' . preg_quote(self::NOTES_BEGIN, '/') . '.*?' . preg_quote(self::NOTES_END, '/') . '/s';

        if (preg_match($pattern, $notes)) {
            return preg_replace_callback($pattern, fn () => $managed, $notes, 1);
        }

        $notes = rtrim($notes);

        return $notes === '' ? $managed : $notes . "\n\n" . $managed;
    }

    /**
     * Update domain asset notes with DNS/WHOIS info. Exceptions propagate.
     */
    public function syncDomainAssetDns(\App\Models\Domain $domain, int $assetId, ?array $dnsRecords = null): array
    {
        $asset = $this->getAsset($assetId);
        $currentNotes = (string) ($asset['notes'] ?? $asset['Notes'] ?? '');

        $dnsNotes = $this->formatDnsRecordsForNotes($dnsRecords ?? []);

        $whoisNotes = WhoisFormatter::formatText(
            $domain->whois_data ?? [],
            $domain->name,
            $domain->whois_synced_at
        );

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

        $newNotes = self::replaceManagedBlock($currentNotes, implode("\n", $lines));

        $result = $this->updateAsset($assetId, ['notes' => $newNotes]);

        return [
            'success' => true,
            'asset_id' => $assetId,
            'data' => $result,
        ];
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

    // ------------------------------------------------------------------
    // Tickets
    // ------------------------------------------------------------------

    private function ticketCacheVersion(): int
    {
        return (int) Cache::get('halo_tickets_version', 1);
    }

    /** Drop cached ticket lists/details after any ticket write. */
    private function bumpTicketCache(): void
    {
        Cache::add('halo_tickets_version', 1, 86400 * 30);
        Cache::increment('halo_tickets_version');
    }

    private function ticketCacheKey(string $kind, array $parts): string
    {
        return 'halo_' . $kind . '_' . sha1($this->baseUrl . '|' . $this->ticketCacheVersion() . '|' . json_encode($parts));
    }

    /**
     * Create a ticket. The POST is attempted once per payload shape and only
     * falls through to the next shape when Halo *rejects* the shape (4xx); a
     * 5xx/timeout is rethrown because the ticket may already exist.
     */
    public function createTicket(array $data): array
    {
        $payloads = [
            $data,
            [$data],
        ];

        $lastError = null;
        foreach ($payloads as $payload) {
            try {
                $result = $this->request('POST', 'Tickets', [
                    'json' => $payload,
                ]);
                $this->bumpTicketCache();

                return $this->extractTicketPayload($result);
            } catch (IntegrationApiException $exception) {
                if (! $exception->isRequestRejected()) {
                    throw $exception;
                }
                $lastError = $exception->getMessage();
                Log::warning('Halo rejected ticket create payload shape.', [
                    'wrapped' => array_is_list($payload),
                    'error' => $lastError,
                ]);
            }
        }

        $suffix = $lastError ? ' Last error: ' . $lastError : '';
        throw new \RuntimeException('Unable to create ticket with available payload formats.' . $suffix);
    }

    private function extractTicketPayload(array $result): array
    {
        if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        if (isset($result['ticket']) && is_array($result['ticket'])) {
            return $result['ticket'];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        return $result;
    }

    /**
     * One page of tickets plus Halo's total (record_count).
     *
     * @param  array $clientIds  Halo client ids; empty = no client filter
     * @return array{tickets: array, record_count: int|null}
     */
    public function listTicketsPage(array $clientIds = [], array $ticketTypeIds = [], int $page = 1, int $pageSize = 20): array
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        sort($clientIds);

        $query = [
            'pageinate' => 'true',
            'page_size' => $pageSize,
            'page_no'   => $page,
        ];

        if (count($clientIds) === 1) {
            $query['client_id'] = $clientIds[0];
        }

        if (! empty($ticketTypeIds)) {
            $query['tickettype_id'] = implode(',', array_map('intval', $ticketTypeIds));
        }

        $key = $this->ticketCacheKey('tickets', [$query, count($clientIds) > 1 ? $clientIds : []]);

        return Cache::remember($key, (int) config('integrations.halo.ticket_list_ttl', 60), function () use ($query, $clientIds) {
            $result = $this->request('GET', 'tickets', ['query' => $query]);
            $tickets = $this->extractList($result, ['tickets']);

            // Several clients requested: one un-filtered call, narrowed locally
            // (never loop one call per client).
            if (count($clientIds) > 1) {
                $tickets = array_values(array_filter(
                    $tickets,
                    fn (array $t) => in_array((int) ($t['client_id'] ?? $t['clientid'] ?? 0), $clientIds, true)
                ));
            }

            return [
                'tickets' => $tickets,
                'record_count' => isset($result['record_count']) ? (int) $result['record_count'] : null,
            ];
        });
    }

    /**
     * List support tickets for a Halo client, optionally constrained to ticket type IDs.
     */
    public function listTicketsForClient(int $clientId, array $ticketTypeIds = [], int $page = 1, int $pageSize = 20): array
    {
        return $this->listTicketsPage([$clientId], $ticketTypeIds, $page, $pageSize)['tickets'];
    }

    /**
     * Fetch a single ticket by ID.
     */
    public function getTicket(int $ticketId): array
    {
        $key = $this->ticketCacheKey('ticket', [$ticketId]);

        return Cache::remember($key, (int) config('integrations.halo.ticket_ttl', 15), function () use ($ticketId) {
            $result = $this->request('GET', 'tickets/' . $ticketId);

            if (isset($result['ticket']) && is_array($result['ticket'])) {
                return $result['ticket'];
            }

            if (isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }

            return $result;
        });
    }

    /**
     * Fetch ticket communications/actions from Halo.
     */
    public function getTicketActions(int $ticketId): array
    {
        $endpoints = [
            'tickets/' . $ticketId . '/actions',
            'actions',
            'action',
        ];

        $lastRejection = null;

        foreach ($endpoints as $endpoint) {
            $query = [
                'ticket_id' => $ticketId,
                'count' => 200,
                'excludeprivate' => 'true',
                'excludesys' => 'true',
                'includehtmlnote' => 'true',
            ];

            try {
                $result = $this->request('GET', $endpoint, [
                    'query' => $query,
                ]);

                $actions = $result['actions']
                    ?? $result['ticket_actions']
                    ?? $result['data']
                    ?? $result['Results']
                    ?? (array_is_list($result) ? $result : []);

                if (is_array($actions)) {
                    return array_values(array_filter($actions, 'is_array'));
                }
            } catch (IntegrationApiException $exception) {
                // Only "endpoint does not exist / rejects this" moves on to
                // the next candidate; transient failures surface to the caller.
                if (! $exception->isRequestRejected()) {
                    throw $exception;
                }
                $lastRejection = $exception;
                Log::warning('Halo ticket action endpoint rejected.', [
                    'ticket_id' => $ticketId,
                    'endpoint' => $endpoint,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($lastRejection) {
            throw $lastRejection;
        }

        return [];
    }

    /**
     * Add an action/note to a ticket.
     */
    public function createTicketAction(
        int $ticketId,
        string $message,
        string $outcome = 'Client-Responded',
        ?string $who = null,
        bool $sendEmail = false,
        array $extraFields = []
    ): array {
        $author = trim((string) $who);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $effectiveAuthor = $author !== '' ? $author : 'Client';
        $payloads = [
            [array_merge([
                'outcome' => $outcome,
                'ticket_id' => $ticketId,
                'datetime' => $timestamp,
                'last_updated' => $timestamp,
                'note' => $message,
                'Who' => $effectiveAuthor,
            ], $extraFields)],
            [array_merge([
                'ticket_id' => $ticketId,
                'note' => $message,
                'outcome' => $outcome,
                'who' => $effectiveAuthor,
                'hiddenfromuser' => false,
                'sendemail' => $sendEmail,
            ], $extraFields)],
        ];

        $lastError = null;
        foreach ($payloads as $requestPayload) {
            try {
                $result = $this->request('POST', 'Actions', [
                    'content_type' => 'application/json-patch+json',
                    'body' => json_encode($requestPayload, JSON_UNESCAPED_SLASHES),
                ]);
                $this->bumpTicketCache();

                $action = $this->extractActionPayload($result);
                if (! empty($action)) {
                    return $action;
                }

                return $result;
            } catch (IntegrationApiException $exception) {
                if (! $exception->isRequestRejected()) {
                    throw $exception;
                }
                $lastError = $exception->getMessage();
                Log::warning('Halo rejected ticket action payload shape.', [
                    'ticket_id' => $ticketId,
                    'wrapped' => true,
                    'error' => $lastError,
                ]);
            }
        }

        $suffix = $lastError ? ' Last error: ' . $lastError : '';
        throw new \RuntimeException('Unable to submit reply to Halo ticket via /Actions endpoint.' . $suffix);
    }

    private function extractActionPayload(array $result): array
    {
        if (array_is_list($result) && isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        if (isset($result['action']) && is_array($result['action'])) {
            return $result['action'];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }

        if (isset($result['id']) || isset($result['Id'])) {
            return $result;
        }

        return [];
    }

    public function updateTicketStatus(int $ticketId, int $statusId): array
    {
        $payloads = [
            ['id' => $ticketId, 'status_id' => $statusId],
            ['id' => $ticketId, 'ticketstatus_id' => $statusId],
            ['TicketId' => $ticketId, 'StatusId' => $statusId],
            ['TicketId' => $ticketId, 'TicketStatusId' => $statusId],
        ];

        $endpoints = [
            'tickets/' . $ticketId,
            'tickets/' . $ticketId . '/status',
            'tickets',
        ];

        $lastError = null;
        foreach ($endpoints as $endpoint) {
            foreach ($payloads as $payload) {
                foreach ([false, true] as $wrapInArray) {
                    $jsonPayload = $wrapInArray ? [$payload] : $payload;

                    try {
                        $result = $this->request('POST', $endpoint, [
                            'json' => $jsonPayload,
                        ]);
                        $this->bumpTicketCache();

                        return $result;
                    } catch (IntegrationApiException $exception) {
                        // Server/transport failure: the status change may have
                        // been applied, do not hammer other shapes.
                        if (! $exception->isRequestRejected()) {
                            throw $exception;
                        }
                        $lastError = $exception->getMessage();
                    }
                }
            }
        }

        $suffix = $lastError ? ' Last error: ' . $lastError : '';
        throw new \RuntimeException('Unable to close ticket with available status payload formats.' . $suffix);
    }

    /**
     * Fetch HaloPSA ticket types (cached 1h: reference data).
     */
    public function listTicketTypes(): array
    {
        $key = 'halo_tickettypes_' . sha1($this->baseUrl . '|' . (string) $this->tenant);

        return Cache::remember($key, (int) config('integrations.halo.reference_data_ttl', 3600), function () {
            $result = $this->request('GET', 'tickettype', [
                'query' => ['count' => 200],
            ]);

            return array_values(array_filter($this->extractList($result, ['tickettypes']), 'is_array'));
        });
    }

    /**
     * Fetch HaloPSA ticket statuses (cached 1h: reference data).
     */
    public function listTicketStatuses(): array
    {
        $key = 'halo_ticketstatuses_' . sha1($this->baseUrl . '|' . (string) $this->tenant);

        return Cache::remember($key, (int) config('integrations.halo.reference_data_ttl', 3600), function () {
            $result = $this->request('GET', 'status', [
                'query' => ['count' => 200],
            ]);

            return array_values(array_filter($this->extractList($result, ['statuses', 'status']), 'is_array'));
        });
    }
}
