<?php

namespace App\Services\ItGlue;

use App\Models\Domain;
use App\Models\Setting;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ItGlueClient
{
    protected Client $http;

    protected string $baseUrl;
    protected ?string $apiKey;
    protected ?int $flexibleAssetTypeId;
    protected array $flexibleAssetTraits;

    public function __construct()
    {
        $cfg = Setting::get('itglue', []);

        // Base URL – default to official ITGlue API if not configured
        $base = trim($cfg['base_url'] ?? '');
        if ($base === '') {
            $base = 'https://api.itglue.com';
        }

        $this->baseUrl = rtrim($base, '/');
        $this->apiKey  = $cfg['api_key'] ?? null;
        $this->flexibleAssetTypeId = isset($cfg['flexible_asset_type_id'])
            ? (int) $cfg['flexible_asset_type_id']
            : null;
        $this->flexibleAssetTraits = $cfg['flexible_asset_traits'] ?? [
            'domain' => 'domain-name',
            'name_servers' => 'name-servers',
            'expiry' => 'expiry',
            'whois' => 'whois',
            'dns' => 'dns',
        ];

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30,
        ]);
    }

    protected function headers(): array
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('ITGlue API key is not configured in settings.');
        }

        return [
            'x-api-key'    => $this->apiKey,
            'Accept'       => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
        ];
    }

    /**
     * List organisations from ITGlue.
     */
    public function listOrganisations(int $pageSize = 100): array
    {
        try {
            $resp = $this->http->get('/organizations', [
                'headers' => $this->headers(),
                'query' => [
                    'page' => ['size' => $pageSize]
                ]
            ]);

            $data = json_decode((string) $resp->getBody(), true);

            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            Log::error('ITGlue listOrganisations error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ensure a flexible asset type ID has been configured
     */
    protected function getFlexibleAssetTypeId(): int
    {
        if (!$this->flexibleAssetTypeId) {
            throw new \RuntimeException('ITGlue flexible asset type ID is not configured in settings.');
        }

        return $this->flexibleAssetTypeId;
    }

    /**
     * Resolve a trait key from configuration
     */
    protected function traitKey(string $key): ?string
    {
        return $this->flexibleAssetTraits[$key] ?? null;
    }

    /**
     * Find an existing flexible asset for a domain by organisation and type
     */
    public function findDomainFlexibleAsset(int $organizationId, int $flexibleAssetTypeId, string $domainName): ?array
    {
        try {
            $resp = $this->http->get('/flexible_assets', [
                'headers' => $this->headers(),
                'query' => [
                    'filter' => [
                        'organization-id' => $organizationId,
                        'flexible-asset-type-id' => $flexibleAssetTypeId,
                        'name' => $domainName
                    ],
                    'page' => ['size' => 1]
                ]
            ]);

            $data = json_decode((string) $resp->getBody(), true);
            $assets = $data['data'] ?? [];

            return !empty($assets) ? $assets[0] : null;
        } catch (\Exception $e) {
            Log::error('ITGlue findFlexibleAsset error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a flexible asset for a domain in ITGlue
     */
    public function createFlexibleAsset(int $organizationId, int $flexibleAssetTypeId, string $domainName, array $traits = []): array
    {
        try {
            $payload = [
                'data' => [
                    'type' => 'flexible-assets',
                    'attributes' => [
                        'organization-id' => $organizationId,
                        'flexible-asset-type-id' => $flexibleAssetTypeId,
                        'name' => $domainName,
                        'traits' => $traits,
                    ]
                ]
            ];

            Log::info('Creating ITGlue flexible asset', [
                'organization_id' => $organizationId,
                'domain' => $domainName,
                'flexible_asset_type_id' => $flexibleAssetTypeId,
            ]);

            $resp = $this->http->post('/flexible_assets', [
                'headers' => $this->headers(),
                'json' => $payload
            ]);

            return json_decode((string) $resp->getBody(), true);
        } catch (\Exception $e) {
            Log::error('ITGlue createFlexibleAsset error: ' . $e->getMessage(), [
                'organization_id' => $organizationId,
                'domain' => $domainName,
                'flexible_asset_type_id' => $flexibleAssetTypeId,
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing flexible asset in ITGlue
     */
    public function updateFlexibleAsset(int $assetId, string $domainName, array $traits): array
    {
        try {
            $payload = [
                'data' => [
                    'type' => 'flexible-assets',
                    'attributes' => [
                        'name' => $domainName,
                        'traits' => $traits,
                    ]
                ]
            ];

            Log::info('Updating ITGlue flexible asset', [
                'asset_id' => $assetId,
                'domain' => $domainName,
            ]);

            $resp = $this->http->patch('/flexible_assets/' . $assetId, [
                'headers' => $this->headers(),
                'json' => $payload
            ]);

            return json_decode((string) $resp->getBody(), true);
        } catch (\Exception $e) {
            Log::error('ITGlue updateFlexibleAsset error: ' . $e->getMessage(), [
                'asset_id' => $assetId
            ]);
            throw $e;
        }
    }

    /**
     * Sync a domain to ITGlue with DNS records
     */
    public function syncDomain(Domain $domain, int $organizationId, ?array $dnsRecords = null): array
    {
        try {
            $flexibleAssetTypeId = $this->getFlexibleAssetTypeId();

            // Find existing flexible asset
            $existing = $this->findDomainFlexibleAsset($organizationId, $flexibleAssetTypeId, $domain->name);

            // Prepare traits
            $traits = $this->buildDomainTraits($domain, $dnsRecords ?? []);

            if ($existing) {
                // Update existing
                $assetId = (int) $existing['id'];
                $result = $this->updateFlexibleAsset($assetId, $domain->name, $traits);

                return [
                    'success' => true,
                    'action' => 'updated',
                    'flexible_asset_id' => $assetId,
                    'data' => $result
                ];
            }

            // Create new
            $result = $this->createFlexibleAsset($organizationId, $flexibleAssetTypeId, $domain->name, $traits);
            $assetId = (int) ($result['data']['id'] ?? null);
            
            return [
                'success' => true,
                'action' => 'created',
                'flexible_asset_id' => $assetId,
                'data' => $result
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Build flexible asset traits for a domain
     */
    protected function buildDomainTraits(Domain $domain, array $dnsRecords): array
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
            $traits[$whoisKey] = 'WHOIS data not available from DomainDash at this time.';
        }

        if ($dnsKey = $this->traitKey('dns')) {
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
