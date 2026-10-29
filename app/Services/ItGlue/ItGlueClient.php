<?php

namespace App\Services\ItGlue;

use App\Models\Domain;
use App\Models\Setting;
use App\Services\Integrations\IntegrationApiException;
use App\Services\Integrations\IntegrationConfigurationException;
use App\Services\Integrations\IntegrationHttp;
use App\Support\WhoisFormatter;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ItGlueClient
{
    public const PROVIDER = 'itglue';

    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?int $flexibleAssetTypeId;
    protected array $flexibleAssetTraits;

    public function __construct()
    {
        $cfg = Setting::get('itglue', []);

        $this->baseUrl = self::normalizeBaseUrl($cfg['base_url'] ?? null);
        $this->apiKey  = $cfg['api_key'] ?? null;
        $this->flexibleAssetTypeId = isset($cfg['flexible_asset_type_id'])
            ? (int) $cfg['flexible_asset_type_id']
            : null;
        $this->flexibleAssetTraits = is_array($cfg['flexible_asset_traits'] ?? null)
            ? $cfg['flexible_asset_traits']
            : self::DEFAULT_TRAITS;
    }

    /**
     * Only ever send the API key to an official https regional host.
     * Blank falls back to the US endpoint.
     */
    public static function normalizeBaseUrl(?string $configured): string
    {
        $base = trim((string) $configured);
        $default = (string) config('integrations.itglue.default_base_url', 'https://api.itglue.com');

        if ($base === '') {
            return $default;
        }

        $parts = parse_url($base);
        $allowed = (array) config('integrations.itglue.allowed_hosts', []);

        $valid = is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), $allowed, true)
            && ! isset($parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])
            && trim($parts['path'] ?? '', '/') === '';

        if (! $valid) {
            throw new IntegrationConfigurationException(
                'IT Glue base URL must be https:// on one of: ' . implode(', ', $allowed) . '.'
            );
        }

        return 'https://' . strtolower($parts['host']);
    }

    public static function isConfigured(): bool
    {
        $cfg = Setting::get('itglue', []);

        return ! empty($cfg['api_key']);
    }

    protected function headers(): array
    {
        if (! $this->apiKey) {
            throw new IntegrationConfigurationException('ITGlue API key is not configured in settings.');
        }

        return [
            'x-api-key' => $this->apiKey,
            'Accept'    => 'application/vnd.api+json',
        ];
    }

    /**
     * Core request helper: shared timeouts, GET-only retry with backoff and
     * Retry-After, typed exception on any failure.
     */
    protected function request(string $method, string $uri, array $query = [], ?array $json = null): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($uri, '/');

        $response = IntegrationHttp::send(
            self::PROVIDER,
            $method,
            $url,
            function () use ($method, $url, $query, $json) {
                $pending = IntegrationHttp::pending('', $this->headers());

                if ($json !== null) {
                    $pending = $pending->withBody(json_encode($json), 'application/vnd.api+json');
                }

                return $pending->send($method, $url, ['query' => $query]);
            },
            $method === 'GET'
        );

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Walk a JSON:API list endpoint using page[size]/page[number]. Stops when
     * the response says there is no next page (meta.next-page / links.next /
     * meta.total-pages) or a short page arrives. Always builds the next URL
     * itself, never follows an absolute link (key never leaves the vetted host).
     *
     * @return Generator<int, array> one array of resource objects per page
     */
    public function paginate(string $uri, array $query = [], ?int $pageSize = null): Generator
    {
        $pageSize ??= (int) config('integrations.itglue.page_size', 1000);
        $maxPages = (int) config('integrations.itglue.max_pages', 200);
        $page = 1;

        do {
            $result = $this->request('GET', $uri, array_merge($query, [
                'page' => ['size' => $pageSize, 'number' => $page],
            ]));

            $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
            $meta = $result['meta'] ?? [];
            $links = $result['links'] ?? [];

            if ($rows !== []) {
                yield $rows;
            }

            if (array_key_exists('next-page', $meta)) {
                $more = ! empty($meta['next-page']);
            } elseif (isset($meta['total-pages'])) {
                $more = $page < (int) $meta['total-pages'];
            } elseif (array_key_exists('next', $links)) {
                $more = ! empty($links['next']);
            } else {
                $more = count($rows) >= $pageSize;
            }

            $page++;
        } while ($more && $rows !== [] && $page <= $maxPages);
    }

    /**
     * @return Generator<int, array>
     */
    public function organizationPages(): Generator
    {
        return $this->paginate('/organizations');
    }

    /**
     * List every organisation (all pages) in JSON:API envelope form.
     */
    public function listOrganisations(): array
    {
        $all = [];
        foreach ($this->organizationPages() as $rows) {
            array_push($all, ...$rows);
        }

        return ['data' => $all];
    }

    /** Trait keys DomainDash writes, with the defaults used when nothing is configured. */
    public const DEFAULT_TRAITS = [
        'domain' => 'domain-name',
        'name_servers' => 'name-servers',
        'expiry' => 'expiry',
        'whois' => 'whois',
        'dns' => 'dns',
    ];

    /** Field kinds a plain string (or Y-m-d date) can be written to. */
    public const FILLABLE_KINDS = ['text', 'textbox', 'date'];

    private function referenceCacheKey(string $what): string
    {
        return 'itglue_' . $what . '_' . sha1($this->baseUrl . '|' . sha1((string) $this->apiKey));
    }

    /**
     * Enabled flexible asset types (id, name), cached 10 minutes.
     *
     * @return array<int,array{id:string,name:string,icon:?string}>
     */
    public function listFlexibleAssetTypes(bool $refresh = false): array
    {
        $key = $this->referenceCacheKey('fat');
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 600, function () {
            $types = [];
            foreach ($this->paginate('/flexible_asset_types', ['filter' => ['enabled' => 'true'], 'sort' => 'name']) as $rows) {
                foreach ($rows as $row) {
                    $id = (string) ($row['id'] ?? '');
                    $name = trim((string) ($row['attributes']['name'] ?? ''));
                    if ($id !== '' && $name !== '') {
                        $types[$id] = ['id' => $id, 'name' => $name, 'icon' => $row['attributes']['icon'] ?? null];
                    }
                }
            }
            $types = array_values($types);
            usort($types, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

            return $types;
        });
    }

    /**
     * Fields of one flexible asset type (GET /flexible_asset_types/{id}?include=flexible_asset_fields).
     *
     * @return array<int,array{name:string,name_key:string,kind:string,required:bool,fillable:bool}>
     */
    public function flexibleAssetTypeFields(int $typeId, bool $refresh = false): array
    {
        $key = $this->referenceCacheKey('fat_fields_' . $typeId);
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 600, function () use ($typeId) {
            $result = $this->request('GET', '/flexible_asset_types/' . $typeId, ['include' => 'flexible_asset_fields']);

            return self::normalizeTypeFields($result);
        });
    }

    /** @return array<int,array{name:string,name_key:string,kind:string,required:bool,fillable:bool,order:int}> */
    public static function normalizeTypeFields(array $result): array
    {
        $fields = [];
        foreach ((array) ($result['included'] ?? []) as $inc) {
            if (! is_array($inc) || ($inc['type'] ?? '') !== 'flexible-asset-fields') {
                continue;
            }
            $a = (array) ($inc['attributes'] ?? []);
            $nameKey = trim((string) ($a['name-key'] ?? ''));
            if ($nameKey === '') {
                continue;
            }
            $kind = (string) ($a['kind'] ?? '');
            $fields[] = [
                'name' => trim((string) ($a['name'] ?? $nameKey)),
                'name_key' => $nameKey,
                'kind' => $kind,
                'required' => (bool) ($a['required'] ?? false),
                'fillable' => in_array(strtolower($kind), self::FILLABLE_KINDS, true),
                'order' => (int) ($a['order'] ?? 0),
            ];
        }
        usort($fields, fn ($x, $y) => $x['order'] <=> $y['order']);

        return $fields;
    }

    /**
     * Ensure a flexible asset type ID has been configured
     */
    protected function getFlexibleAssetTypeId(): int
    {
        if (! $this->flexibleAssetTypeId) {
            throw new IntegrationConfigurationException('ITGlue flexible asset type ID is not configured in settings.');
        }

        return $this->flexibleAssetTypeId;
    }

    public function configuredFlexibleAssetTypeId(): int
    {
        return $this->getFlexibleAssetTypeId();
    }

    /** The configured domain-point => trait name-key map (part of the sync hash). */
    public function traitMapping(): array
    {
        return $this->flexibleAssetTraits;
    }

    /**
     * Resolve a trait key from configuration
     */
    protected function traitKey(string $key): ?string
    {
        return $this->flexibleAssetTraits[$key] ?? null;
    }

    /**
     * All flexible assets of a type (optionally for one organisation), keyed
     * by lower-cased name. One paged call per ~1000 assets.
     *
     * @return array<string,array> name => JSON:API resource
     */
    public function flexibleAssetsByName(int $flexibleAssetTypeId, ?int $organizationId = null): array
    {
        $filter = ['flexible-asset-type-id' => $flexibleAssetTypeId];
        if ($organizationId) {
            $filter['organization-id'] = $organizationId;
        }

        $map = [];
        foreach ($this->paginate('/flexible_assets', ['filter' => $filter]) as $rows) {
            foreach ($rows as $row) {
                $name = strtolower(trim((string) ($row['attributes']['name'] ?? '')));
                if ($name !== '') {
                    $map[$name] = $row;
                }
            }
        }

        return $map;
    }

    /**
     * Find an existing flexible asset for a domain by organisation and type.
     * Returns null ONLY when the API answered successfully with no match;
     * any failure throws IntegrationApiException.
     */
    public function findDomainFlexibleAsset(int $organizationId, int $flexibleAssetTypeId, string $domainName): ?array
    {
        $result = $this->request('GET', '/flexible_assets', [
            'filter' => [
                'organization-id' => $organizationId,
                'flexible-asset-type-id' => $flexibleAssetTypeId,
                'name' => $domainName,
            ],
            'page' => ['size' => 1],
        ]);

        $assets = $result['data'] ?? [];

        return ! empty($assets) ? $assets[0] : null;
    }

    public function getFlexibleAsset(int $assetId): array
    {
        $result = $this->request('GET', '/flexible_assets/' . $assetId);

        return $result['data'] ?? [];
    }

    /**
     * Create a flexible asset for a domain in ITGlue (single attempt, no retry).
     */
    public function createFlexibleAsset(int $organizationId, int $flexibleAssetTypeId, string $domainName, array $traits = []): array
    {
        $payload = [
            'data' => [
                'type' => 'flexible-assets',
                'attributes' => [
                    'organization-id' => $organizationId,
                    'flexible-asset-type-id' => $flexibleAssetTypeId,
                    'name' => $domainName,
                    'traits' => $traits,
                ],
            ],
        ];

        Log::info('Creating ITGlue flexible asset', [
            'organization_id' => $organizationId,
            'domain' => $domainName,
            'flexible_asset_type_id' => $flexibleAssetTypeId,
        ]);

        return $this->request('POST', '/flexible_assets', [], $payload);
    }

    /**
     * Update an existing flexible asset in ITGlue.
     *
     * IT Glue deletes every trait a PATCH does not mention, so the existing
     * traits are loaded (or supplied via $existingTraits) and the new values
     * are merged over them: the PATCH always carries the full trait set.
     */
    public function updateFlexibleAsset(int $assetId, string $domainName, array $traits, ?array $existingTraits = null): array
    {
        if ($existingTraits === null) {
            $existing = $this->getFlexibleAsset($assetId);
            $existingTraits = $existing['attributes']['traits'] ?? [];
        }

        // Drop unset (null) values: nothing to preserve there.
        $existingTraits = array_filter(
            is_array($existingTraits) ? $existingTraits : [],
            fn ($v) => $v !== null
        );

        $merged = array_merge($existingTraits, $traits);

        $payload = [
            'data' => [
                'type' => 'flexible-assets',
                'attributes' => [
                    'name' => $domainName,
                    'traits' => $merged,
                ],
            ],
        ];

        Log::info('Updating ITGlue flexible asset', [
            'asset_id' => $assetId,
            'domain' => $domainName,
            'trait_count' => count($merged),
        ]);

        return $this->request('PATCH', '/flexible_assets/' . $assetId, [], $payload);
    }

    /**
     * Sync a domain to ITGlue. $dnsRecords === null means "DNS unknown":
     * the dns trait is left untouched rather than overwritten with "none".
     *
     * Failures throw IntegrationApiException (callers decide retry / record);
     * a failed lookup can never fall through to a duplicate create.
     *
     * @param array|bool|null $knownAsset JSON:API flexible asset already located by the caller;
     *                                    false = caller knows it does not exist (skips the find call).
     */
    public function syncDomain(Domain $domain, int $organizationId, ?array $dnsRecords = null, array|bool|null $knownAsset = null): array
    {
        $flexibleAssetTypeId = $this->getFlexibleAssetTypeId();

        $existing = $knownAsset === false
            ? null
            : ($knownAsset ?: $this->findDomainFlexibleAsset($organizationId, $flexibleAssetTypeId, $domain->name));

        $traits = $this->buildDomainTraits($domain, $dnsRecords);

        if ($existing) {
            $assetId = (int) $existing['id'];
            $result = $this->updateFlexibleAsset(
                $assetId,
                $domain->name,
                $traits,
                $existing['attributes']['traits'] ?? null
            );

            return [
                'success' => true,
                'action' => 'updated',
                'flexible_asset_id' => $assetId,
                'data' => $result,
            ];
        }

        $result = $this->createFlexibleAsset($organizationId, $flexibleAssetTypeId, $domain->name, $traits);
        $assetId = (int) ($result['data']['id'] ?? 0);

        return [
            'success' => true,
            'action' => 'created',
            'flexible_asset_id' => $assetId,
            'data' => $result,
        ];
    }

    /**
     * Build flexible asset traits for a domain
     */
    public function buildDomainTraits(Domain $domain, ?array $dnsRecords): array
    {
        $traits = [];

        if ($domainKey = $this->traitKey('domain')) {
            $traits[$domainKey] = $domain->name;
        }

        if ($nameServersKey = $this->traitKey('name_servers')) {
            $nameServers = $this->formatNameServers($domain->name_servers);
            if ($nameServers !== null) {
                $traits[$nameServersKey] = $nameServers;
            }
        }

        if ($expiryKey = $this->traitKey('expiry')) {
            $expiry = $domain->expiry_date ? $domain->expiry_date->toDateString() : null;
            if ($expiry !== null) {
                $traits[$expiryKey] = $expiry;
            }
        }

        if ($whoisKey = $this->traitKey('whois')) {
            $traits[$whoisKey] = WhoisFormatter::formatText(
                $domain->whois_data ?? [],
                $domain->name,
                $domain->whois_synced_at
            );
        }

        if (($dnsKey = $this->traitKey('dns')) && $dnsRecords !== null) {
            $traits[$dnsKey] = $this->formatDnsRecordsAsHtml($dnsRecords, $domain);
        }

        return $traits;
    }

    /**
     * Format name servers for flexible asset traits
     */
    protected function formatNameServers($nameServers): ?string
    {
        if (empty($nameServers)) {
            return null;
        }

        if (is_array($nameServers)) {
            $filtered = array_filter($nameServers, fn ($ns) => $ns !== null && $ns !== '');
            return empty($filtered) ? null : implode("\n", $filtered);
        }

        return trim((string) $nameServers) ?: null;
    }

    /**
     * Format DNS records as HTML for flexible asset rich text fields
     */
    protected function formatDnsRecordsAsHtml(array $dnsRecords, Domain $domain): string
    {
        $html = '<div style="font-family:Arial, sans-serif;">';
        $html .= '<p><strong>DNS Records (Auto-synced from DomainDash)</strong><br />';
        $html .= 'Last Updated: ' . now()->format('Y-m-d H:i:s') . '</p>';

        $dnsModeLabel = $this->formatDnsModeLabel($domain->dns_config);
        $nameservers  = $this->formatNameServers($domain->name_servers) ?? 'Not set';

        if ($dnsModeLabel) {
            $html .= '<p style="margin:8px 0 12px;"><strong>DNS Mode:</strong> ' . htmlspecialchars($dnsModeLabel) . '<br />';
            $html .= '<strong>Nameservers:</strong><br /><span style="white-space:pre-line;">' . htmlspecialchars($nameservers) . '</span></p>';
        }

        if (empty($dnsRecords)) {
            $html .= '<div>No DNS records available.</div></div>';
            return $html;
        }

        $recordsByType = [];
        foreach ($dnsRecords as $record) {
            $type = is_object($record) ? ($record->type ?? 'UNKNOWN') : ($record['type'] ?? 'UNKNOWN');
            $recordsByType[$type][] = $record;
        }

        foreach ($recordsByType as $type => $records) {
            $html .= '<h4 style="margin:12px 0 6px;">' . htmlspecialchars($type) . ' Records</h4>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;font-size:13px;">';
            $html .= '<thead><tr style="background:#f8fafc;color:#111827;text-align:left;">';
            $html .= '<th style="padding:6px 8px;border:1px solid #e2e8f0;">Host</th>';
            $html .= '<th style="padding:6px 8px;border:1px solid #e2e8f0;">Value</th>';
            $html .= '<th style="padding:6px 8px;border:1px solid #e2e8f0;">TTL</th>';
            $html .= '<th style="padding:6px 8px;border:1px solid #e2e8f0;">Priority</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($records as $record) {
                if (is_object($record)) {
                    $hostname = $record->hostName ?? $record->hostname ?? '';
                    $content = $record->content ?? '';
                    $ttl = $record->ttl ?? '';
                    $prio = isset($record->prio) && $record->prio > 0 ? ' (Priority: ' . $record->prio . ')' : '';
                } else {
                    $hostname = $record['hostName'] ?? $record['hostname'] ?? '';
                    $content = $record['content'] ?? '';
                    $ttl = $record['ttl'] ?? '';
                    $prio = isset($record['prio']) && $record['prio'] > 0 ? ' (Priority: ' . $record['prio'] . ')' : '';
                }

                $html .= '<tr>'; 
                $html .= '<td style="padding:6px 8px;border:1px solid #e2e8f0;">' . htmlspecialchars((string) $hostname) . '</td>';
                $html .= '<td style="padding:6px 8px;border:1px solid #e2e8f0;"><code>' . htmlspecialchars((string) $content) . '</code></td>';
                $html .= '<td style="padding:6px 8px;border:1px solid #e2e8f0;color:#64748b;">' . htmlspecialchars((string) $ttl) . '</td>';
                $html .= '<td style="padding:6px 8px;border:1px solid #e2e8f0;color:#64748b;">' . htmlspecialchars($prio ?: '—') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a human-friendly DNS mode label
     */
    protected function formatDnsModeLabel($dnsConfig): ?string
    {
        if ($dnsConfig === null) {
            return null;
        }

        $map = [
            1 => 'Custom nameservers',
            2 => 'URL & Email forwarding',
            3 => 'Parked',
            4 => 'DNS hosting',
        ];

        return $map[(int) $dnsConfig] ?? (string) $dnsConfig;
    }
}
