<?php

namespace App\Services\ItGlue;

use App\Models\Setting;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ItGlueClient
{
    protected Client $http;

    protected string $baseUrl;
    protected ?string $apiKey;

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
     * Find a domain configuration by organization ID and domain name
     */
    public function findDomainConfiguration(int $organizationId, string $domainName): ?array
    {
        try {
            $resp = $this->http->get('/configurations', [
                'headers' => $this->headers(),
                'query' => [
                    'filter' => [
                        'organization-id' => $organizationId,
                        'configuration-type-name' => 'Domain',
                        'name' => $domainName
                    ]
                ]
            ]);

            $data = json_decode((string) $resp->getBody(), true);
            $configs = $data['data'] ?? [];

            return !empty($configs) ? $configs[0] : null;
        } catch (\Exception $e) {
            Log::error('ITGlue findDomainConfiguration error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a domain configuration in ITGlue
     */
    public function createDomainConfiguration(int $organizationId, string $domainName, array $attributes = []): array
    {
        try {
            // Get the Domain configuration type ID
            $configTypeId = $this->getDomainConfigurationTypeId();

            $payload = [
                'data' => [
                    'type' => 'configurations',
                    'attributes' => array_merge([
                        'organization-id' => $organizationId,
                        'configuration-type-id' => $configTypeId,
                        'name' => $domainName,
                    ], $attributes)
                ]
            ];

            Log::info('Creating ITGlue domain configuration', [
                'organization_id' => $organizationId,
                'domain' => $domainName
            ]);

            $resp = $this->http->post('/configurations', [
                'headers' => $this->headers(),
                'json' => $payload
            ]);

            return json_decode((string) $resp->getBody(), true);
        } catch (\Exception $e) {
            Log::error('ITGlue createDomainConfiguration error: ' . $e->getMessage(), [
                'organization_id' => $organizationId,
                'domain' => $domainName
            ]);
            throw $e;
        }
    }

    /**
     * Update a domain configuration in ITGlue
     */
    public function updateDomainConfiguration(int $configurationId, array $attributes): array
    {
        try {
            $payload = [
                'data' => [
                    'type' => 'configurations',
                    'attributes' => $attributes
                ]
            ];

            Log::info('Updating ITGlue domain configuration', [
                'configuration_id' => $configurationId
            ]);

            $resp = $this->http->patch('/configurations/' . $configurationId, [
                'headers' => $this->headers(),
                'json' => $payload
            ]);

            return json_decode((string) $resp->getBody(), true);
        } catch (\Exception $e) {
            Log::error('ITGlue updateDomainConfiguration error: ' . $e->getMessage(), [
                'configuration_id' => $configurationId
            ]);
            throw $e;
        }
    }

    /**
     * Get or cache the Domain configuration type ID
     */
    protected function getDomainConfigurationTypeId(): int
    {
        // Check cache first
        $cacheKey = 'itglue_domain_config_type_id';
        if ($cached = \Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $resp = $this->http->get('/configuration_types', [
                'headers' => $this->headers(),
                'query' => [
                    'filter' => ['name' => 'Domain']
                ]
            ]);

            $data = json_decode((string) $resp->getBody(), true);
            $types = $data['data'] ?? [];

            if (empty($types)) {
                // Default to ID 1 if not found
                Log::warning('Domain configuration type not found in ITGlue, using default ID 1');
                return 1;
            }

            $typeId = (int) $types[0]['id'];
            \Cache::put($cacheKey, $typeId, 3600); // Cache for 1 hour

            return $typeId;
        } catch (\Exception $e) {
            Log::error('ITGlue getDomainConfigurationTypeId error: ' . $e->getMessage());
            return 1; // Fallback to ID 1
        }
    }

    /**
     * Sync a domain to ITGlue with DNS records
     */
    public function syncDomain(\App\Models\Domain $domain, int $organizationId, ?array $dnsRecords = null): array
    {
        try {
            // Find existing configuration
            $existing = $this->findDomainConfiguration($organizationId, $domain->name);

            // Prepare attributes
            $attributes = [
                'notes' => $this->formatDnsRecordsForNotes($dnsRecords ?? [])
            ];

            // Add optional fields if available
            if ($domain->registrar) {
                $attributes['notes'] = "Registrar: {$domain->registrar}\n\n" . $attributes['notes'];
            }
            
            if ($domain->expiry_date) {
                $attributes['notes'] = "Expiry Date: {$domain->expiry_date}\n" . $attributes['notes'];
            }

            if ($existing) {
                // Update existing
                $configId = (int) $existing['id'];
                $result = $this->updateDomainConfiguration($configId, $attributes);
                
                return [
                    'success' => true,
                    'action' => 'updated',
                    'configuration_id' => $configId,
                    'data' => $result
                ];
            } else {
                // Create new
                $result = $this->createDomainConfiguration($organizationId, $domain->name, $attributes);
                $configId = (int) ($result['data']['id'] ?? null);
                
                return [
                    'success' => true,
                    'action' => 'created',
                    'configuration_id' => $configId,
                    'data' => $result
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Format DNS records for ITGlue notes
     */
    protected function formatDnsRecordsForNotes(array $dnsRecords): string
    {
        if (empty($dnsRecords)) {
            return "No DNS records available.";
        }

        $notes = "=== DNS Records (Auto-synced from Domain Dash) ===\n";
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
}