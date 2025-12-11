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

            // Get all DomainDash clients - ensure we select the fields we need
            $dashClients = Client::select('id', 'name', 'halo_psa_client_id')->get();

            // Match clients
            $matchedClients = [];
            foreach ($haloClients as $haloClient) {
                // Check if already mapped
                $mappedClient = $dashClients->firstWhere('halo_psa_client_id', $haloClient['id']);

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

                // Update DomainDash client with Halo data
                $dashClient->halo_psa_client_id = $haloClient['id'];

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
                    'client' => $domain->client ? $domain->client->name : null,
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
            $haloConfig = Setting::get('halo', []);
            $token = $this->getHaloAccessToken($haloConfig);

            $syncedCount = 0;

            foreach ($domainIds as $domainId) {
                $domain = Domain::with('client')->find($domainId);
                if (!$domain || !$domain->client) {
                    continue;
                }

                // Create or update domain asset in Halo
                $assetData = [
                    'client_id' => $domain->client->halo_psa_client_id,
                    'assettype_id' => 1, // Domain asset type (may need adjustment)
                    'name' => $domain->name,
                    'fields' => [
                        [
                            'name' => 'Asset Tag',
                            'value' => $domain->name
                        ],
                        [
                            'name' => 'Domain Name',
                            'value' => $domain->name
                        ],
                        [
                            'name' => 'Domain Expiry',
                            'value' => $domain->expiry_date
                        ],
                        [
                            'name' => 'Name Servers',
                            'value' => is_array($domain->nameservers) ? implode(', ', $domain->nameservers) : $domain->nameservers
                        ],
                    ]
                ];

                $response = Http::withToken($token)
                    ->post($haloConfig['base_url'] . '/asset', $assetData);

                if ($response->successful()) {
                    $syncedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'synced_count' => $syncedCount
            ]);
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

            $dashClients = Client::all();
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
                    'dash_name' => $dashClient->name,
                    'mapped_id' => $dashClient->itglue_organization_id,
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
                $score = similar_text(strtolower($client->name), strtolower($org['attributes']['name']));
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
                    $client->itglue_organization_id = $mapping['itglue_org_id'];
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
                if ($domain->client && $domain->client->itglue_organization_id) {
                    $items[] = [
                        'id' => $domain->id,
                        'type' => 'domain',
                        'name' => $domain->name,
                        'client' => $domain->client->name,
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
                    if ($domain && $domain->client && $domain->client->itglue_organization_id) {
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
            // Skip if client doesn't have a name
            if (empty($client->name)) {
                continue;
            }

            $score = similar_text(strtolower($haloClientName ?? ''), strtolower($client->name));

            $matches[] = [
                'id' => $client->id,
                'name' => $client->name ?? 'Unnamed Client',
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
        // Search for domain asset in Halo
        try {
            $response = Http::withToken($token)
                ->get($haloConfig['base_url'] . '/asset', [
                    'search' => $domainName,
                    'assettype_id' => 1
                ]);

            if ($response->successful()) {
                $assets = $response->json('assets') ?? [];
                return count($assets) > 0;
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
                        'organization-id' => $domain->client->itglue_organization_id,
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
