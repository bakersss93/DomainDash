<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Client;
use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    /**
     * Get Halo clients and match with DomainDash clients
     */
    public function getHaloClients()
    {
        try {
            $haloConfig = Setting::get('halo', []);

            if (empty($haloConfig['base_url']) || empty($haloConfig['client_id']) || empty($haloConfig['api_key'])) {
                return response()->json(['error' => 'HaloPSA is not configured. Please configure it in Settings.'], 400);
            }

            // Get access token
            $token = $this->getHaloAccessToken($haloConfig);
            if (!$token) {
                return response()->json(['error' => 'Failed to authenticate with HaloPSA'], 401);
            }

            // Fetch clients from Halo
            $response = Http::withToken($token)
                ->get($haloConfig['base_url'] . '/client');

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch clients from HaloPSA: ' . $response->body()], 500);
            }

            $haloClients = $response->json('clients') ?? [];

            // Get all DomainDash clients - use existing column names
            $dashClients = Client::select('id', 'business_name', 'halopsa_reference')->get();

            // Match clients
            $matchedClients = [];
            foreach ($haloClients as $haloClient) {
                // Check if already mapped using existing halopsa_reference column
                $mappedClient = $dashClients->firstWhere('halopsa_reference', $haloClient['id']);

                // Get all clients as suggestions, sorted by best match
                $suggestions = $this->findClientMatches($haloClient['name'], $dashClients);

                $matchedClients[] = [
                    'halo_id' => $haloClient['id'],
                    'halo_name' => $haloClient['name'] ?? 'Unknown Client',
                    'mapped_id' => $mappedClient ? $mappedClient->id : null,
                    'suggestions' => $suggestions,
                    'updated' => isset($haloClient['datemodified']) ? date('Y-m-d H:i', strtotime($haloClient['datemodified'])) : null,
                ];
            }

            return response()->json(['clients' => $matchedClients]);
        } catch (\Exception $e) {
            Log::error('Halo client fetch error: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Sync selected Halo clients with DomainDash
     */
    public function syncHaloClients(Request $request)
    {
        try {
            $clients = $request->input('clients', []);
            $haloConfig = Setting::get('halo', []);
            $token = $this->getHaloAccessToken($haloConfig);

            $syncedCount = 0;

            foreach ($clients as $clientData) {
                $dashClient = Client::find($clientData['dash_client_id']);
                if (!$dashClient) {
                    continue;
                }

                // Get full client data from Halo
                $response = Http::withToken($token)
                    ->get($haloConfig['base_url'] . '/client/' . $clientData['halo_id']);

                if (!$response->successful()) {
                    continue;
                }

                $haloClient = $response->json();

                // Update DomainDash client with Halo reference using existing column
                $dashClient->halopsa_reference = $haloClient['id'];

                // Sync ABN if available (assuming it's stored in a custom field)
                if (isset($haloClient['customfields'])) {
                    foreach ($haloClient['customfields'] as $field) {
                        if (strtolower($field['name']) === 'abn' && !empty($field['value'])) {
                            $dashClient->abn = $field['value'];
                        }
                    }
                }

                $dashClient->save();

                // Sync domain assignments
                $this->syncClientDomainAssignments($dashClient, $haloClient, $token, $haloConfig);

                $syncedCount++;
            }

            return response()->json([
                'success' => true,
                'synced_count' => $syncedCount
            ]);
        } catch (\Exception $e) {
            Log::error('Halo client sync error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get domains from DomainDash for Halo sync
     */
    public function getHaloDomains()
    {
        try {
            $haloConfig = Setting::get('halo', []);

            if (empty($haloConfig['base_url'])) {
                return response()->json(['error' => 'HaloPSA is not configured'], 400);
            }

            $token = $this->getHaloAccessToken($haloConfig);
            if (!$token) {
                return response()->json(['error' => 'Failed to authenticate with HaloPSA'], 401);
            }

            $domains = Domain::with('client')->get();

            $domainList = [];
            foreach ($domains as $domain) {
                // Check if domain exists in Halo
                $existsInHalo = $this->checkDomainExistsInHalo($domain->name, $token, $haloConfig);

                $domainList[] = [
                    'id' => $domain->id,
                    'name' => $domain->name,
                    'client' => $domain->client ? $domain->client->business_name : null,
                    'client_id' => $domain->client_id,
                    'expiry' => $domain->expiry_date,
                    'nameservers' => $domain->nameservers,
                    'exists_in_halo' => $existsInHalo,
                ];
            }

            return response()->json(['domains' => $domainList]);
        } catch (\Exception $e) {
            Log::error('Halo domain fetch error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync selected domains to Halo
     */
    public function syncHaloDomains(Request $request)
    {
        try {
            $domainIds = $request->input('domain_ids', []);
            $halo = new \App\Services\Halo\HaloPsaClient();

            $syncedCount = 0;
            $errors = [];

            foreach ($domainIds as $domainId) {
                $domain = Domain::with('client')->find($domainId);
                if (!$domain || !$domain->client) {
                    $errors[] = "Domain {$domainId}: No client assigned";
                    continue;
                }

                if (!$domain->client->halopsa_reference) {
                    $errors[] = "Domain {$domain->name}: Client not linked to HaloPSA";
                    continue;
                }

                try {
                    // Create domain asset in Halo using the service
                    $assetData = [
                        'client_id' => (int) $domain->client->halopsa_reference,
                        'inventory_number' => $domain->name,  // This is the key field for domain name
                        'asset_tag' => $domain->name,
                        'name' => $domain->name,
                        'agent_id' => (int) $domain->client->halopsa_reference,
                    ];

                    // Add notes with domain information
                    $notes = "Domain: {$domain->name}\n";
                    if ($domain->expiry_date) {
                        $notes .= "Expiry: {$domain->expiry_date}\n";
                    }
                    if ($domain->registrar) {
                        $notes .= "Registrar: {$domain->registrar}\n";
                    }
                    if ($domain->nameservers) {
                        $nameservers = is_array($domain->nameservers) ? implode(', ', $domain->nameservers) : $domain->nameservers;
                        $notes .= "Name Servers: {$nameservers}\n";
                    }
                    $assetData['notes'] = $notes;

                    $result = $halo->createDomainAsset($assetData);

                    if (isset($result['id']) || isset($result['Id'])) {
                        $assetId = $result['id'] ?? $result['Id'];

                        // Update domain with Halo asset ID
                        $domain->halo_asset_id = $assetId;
                        $domain->save();

                        $syncedCount++;

                        Log::info('Created domain asset in Halo', [
                            'domain' => $domain->name,
                            'asset_id' => $assetId
                        ]);
                    } else {
                        $errors[] = "Domain {$domain->name}: Failed to create asset - no ID returned";
                    }
                } catch (\Exception $e) {
                    $errors[] = "Domain {$domain->name}: {$e->getMessage()}";
                    Log::error('Error creating domain asset', [
                        'domain' => $domain->name,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $response = [
                'success' => true,
                'synced_count' => $syncedCount
            ];

            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Halo domain sync error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get IT Glue clients for mapping
     */
    public function getItGlueClients()
    {
        try {
            $itglueConfig = Setting::get('itglue', []);

            if (empty($itglueConfig['base_url']) || empty($itglueConfig['api_key'])) {
                return response()->json(['error' => 'IT Glue is not configured'], 400);
            }

            // Fetch organizations from IT Glue
            $response = Http::withHeaders([
                'x-api-key' => $itglueConfig['api_key'],
                'Content-Type' => 'application/vnd.api+json'
            ])->get($itglueConfig['base_url'] . '/organizations');

            if (!$response->successful()) {
                return response()->json(['error' => 'Failed to fetch organizations from IT Glue'], 500);
            }

            $itglueOrgs = $response->json('data') ?? [];

            // Get clients with existing column names
            $dashClients = Client::select('id', 'business_name', 'itglue_org_id')->get();
            $clientList = [];

            foreach ($dashClients as $dashClient) {
                $organizations = [];
                foreach ($itglueOrgs as $org) {
                    $organizations[] = [
                        'id' => $org['id'],
                        'name' => $org['attributes']['name']
                    ];
                }

                $clientList[] = [
                    'dash_id' => $dashClient->id,
                    'dash_name' => $dashClient->business_name,
                    'mapped_id' => $dashClient->itglue_org_id,
                    'organizations' => $organizations,
                ];
            }

            return response()->json(['clients' => $clientList]);
        } catch (\Exception $e) {
            Log::error('IT Glue client fetch error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Suggest IT Glue organization for a client
     */
    public function suggestItGlueOrg($clientId)
    {
        try {
            $client = Client::findOrFail($clientId);
            $itglueConfig = Setting::get('itglue', []);

            $response = Http::withHeaders([
                'x-api-key' => $itglueConfig['api_key'],
                'Content-Type' => 'application/vnd.api+json'
            ])->get($itglueConfig['base_url'] . '/organizations');

            $itglueOrgs = $response->json('data') ?? [];

            // Find best match
            $bestMatch = null;
            $bestScore = 0;

            foreach ($itglueOrgs as $org) {
                $score = similar_text(strtolower($client->business_name), strtolower($org['attributes']['name']));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $org['id'];
                }
            }

            return response()->json(['suggested_org_id' => $bestMatch]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Save IT Glue client mappings
     */
    public function syncItGlueClients(Request $request)
    {
        try {
            $mappings = $request->input('mappings', []);
            $mappedCount = 0;

            foreach ($mappings as $mapping) {
                $client = Client::find($mapping['dash_client_id']);
                if ($client) {
                    // Use existing itglue_org_id column
                    $client->itglue_org_id = $mapping['itglue_org_id'];
                    $client->save();
                    $mappedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'mapped_count' => $mappedCount
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get configuration items for IT Glue sync
     */
    public function getItGlueConfigurations()
    {
        try {
            $items = [];

            // Get domains
            $domains = Domain::with('client')->get();
            foreach ($domains as $domain) {
                if ($domain->client && $domain->client->itglue_org_id) {
                    $items[] = [
                        'id' => $domain->id,
                        'type' => 'domain',
                        'name' => $domain->name,
                        'client' => $domain->client->business_name,
                        'exists_in_itglue' => false, // TODO: Check if exists
                    ];
                }
            }

            // TODO: Get SSLs and Web Hosting services

            return response()->json(['items' => $items]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync configuration items to IT Glue
     */
    public function syncItGlueConfigurations(Request $request)
    {
        try {
            $items = $request->input('items', []);
            $itglueConfig = Setting::get('itglue', []);
            $syncedCount = 0;

            foreach ($items as $item) {
                if ($item['type'] === 'domain') {
                    $domain = Domain::with('client')->find($item['id']);
                    if ($domain && $domain->client && $domain->client->itglue_org_id) {
                        $this->syncDomainToItGlue($domain, $itglueConfig);
                        $syncedCount++;
                    }
                }
                // TODO: Handle SSL and Web Hosting types
            }

            return response()->json([
                'success' => true,
                'synced_count' => $syncedCount
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Helper Methods

    private function getHaloAccessToken($config)
    {
        try {
            $authServer = $config['auth_server'] ?? str_replace('/api', '/auth', $config['base_url']);

            $response = Http::asForm()->post($authServer . '/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['api_key'],
                'scope' => 'all',
            ]);

            if ($response->successful()) {
                return $response->json('access_token');
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Halo auth error: ' . $e->getMessage());
            return null;
        }
    }

    private function findClientMatches($haloClientName, $dashClients)
    {
        $matches = [];

        foreach ($dashClients as $client) {
            // Skip if client doesn't have a business_name
            if (empty($client->business_name)) {
                continue;
            }

            $score = similar_text(strtolower($haloClientName ?? ''), strtolower($client->business_name));

            $matches[] = [
                'id' => $client->id,
                'name' => $client->business_name ?? 'Unnamed Client',
                'score' => $score
            ];
        }

        // Sort by score descending
        usort($matches, function ($a, $b) {
            return $b['score'] - $a['score'];
        });

        return $matches;
    }

    private function syncClientDomainAssignments($dashClient, $haloClient, $token, $haloConfig)
    {
        // Fetch domain assets from Halo for this client
        $response = Http::withToken($token)
            ->get($haloConfig['base_url'] . '/asset', [
                'client_id' => $haloClient['id'],
                'assettype_id' => 1 // Domain type
            ]);

        if (!$response->successful()) {
            return;
        }

        $haloAssets = $response->json('assets') ?? [];

        foreach ($haloAssets as $asset) {
            // Try to find matching domain in DomainDash
            $domainName = null;
            foreach ($asset['fields'] ?? [] as $field) {
                if ($field['name'] === 'Domain Name') {
                    $domainName = $field['value'];
                    break;
                }
            }

            if ($domainName) {
                $domain = Domain::where('name', $domainName)->first();
                if ($domain && !$domain->client_id) {
                    $domain->client_id = $dashClient->id;
                    $domain->save();
                }
            }
        }
    }

    private function checkDomainExistsInHalo($domainName, $token, $haloConfig)
    {
        // Use HaloPsaClient service to properly check for domain assets
        try {
            $halo = new \App\Services\Halo\HaloPsaClient();

            // Get all domain assets from all clients
            // Since we need to search across all clients, we'll use a direct API call
            $response = Http::withToken($token)
                ->get($haloConfig['base_url'] . '/Asset', [
                    'count' => 1000,
                    'search' => $domainName,
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $assets = $responseData['assets']
                    ?? $responseData['data']
                    ?? $responseData['Results']
                    ?? [];

                // Filter for domain type assets
                foreach ($assets as $asset) {
                    $typeName = $asset['assettype_name']
                        ?? $asset['assettypename']
                        ?? $asset['AssetTypeName']
                        ?? null;

                    // Check if it's a domain asset
                    if ($typeName && strcasecmp($typeName, 'Domain') === 0) {
                        // Check if the asset matches this domain
                        $assetName = $asset['inventory_number']
                            ?? $asset['asset_tag']
                            ?? $asset['name']
                            ?? null;

                        if ($assetName && strcasecmp($assetName, $domainName) === 0) {
                            return true;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Check domain exists error: ' . $e->getMessage());
        }

        return false;
    }

    private function syncDomainToItGlue($domain, $itglueConfig)
    {
        try {
            $configData = [
                'data' => [
                    'type' => 'configurations',
                    'attributes' => [
                        'organization-id' => $domain->client->itglue_org_id,
                        'configuration-type-id' => 'domain', // May need to be numeric ID
                        'name' => $domain->name,
                        'contact-name' => null,
                        'notes' => "Domain: {$domain->name}\nExpiry: {$domain->expiry_date}",
                    ]
                ]
            ];

            Http::withHeaders([
                'x-api-key' => $itglueConfig['api_key'],
                'Content-Type' => 'application/vnd.api+json'
            ])->post($itglueConfig['base_url'] . '/configurations', $configData);

        } catch (\Exception $e) {
            Log::error('Sync domain to IT Glue error: ' . $e->getMessage());
        }
    }
}
