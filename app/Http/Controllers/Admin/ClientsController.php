<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use SoapClient;

class ClientsController extends Controller
{
    /**
     * Display a listing of clients
     */
    public function index()
    {
        $clients = Client::paginate(15);
        return view('admin.clients.index', compact('clients'));
    }

    /**
     * Show the form for creating a new client
     */
    public function create()
    {
        $client = new Client();
        return view('admin.clients.form', compact('client'));
    }

    /**
     * Store a newly created client
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'abn' => 'nullable|string|max:50',
            'halopsa_reference' => 'nullable|string|max:255',
            'itglue_org_id' => 'nullable|integer',
            'itglue_org_name' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ]);

        $validated['active'] = $request->has('active') ? 1 : 0;

        $client = Client::create($validated);

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client created successfully.');
    }

    /**
     * Show the form for editing the specified client
     */
    public function edit(Client $client)
    {
        // Get assigned users if you have that relationship
        $assignedUsers = $client->users ?? collect();
        
        return view('admin.clients.form', compact('client', 'assignedUsers'));
    }

    /**
     * Update the specified client
     */
    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'abn' => 'nullable|string|max:50',
            'halopsa_reference' => 'nullable|string|max:255',
            'itglue_org_id' => 'nullable|integer',
            'itglue_org_name' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ]);

        $validated['active'] = $request->has('active') ? 1 : 0;

        $client->update($validated);

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client updated successfully.');
    }

    // ============================================================================
    // HALOPSA INTEGRATION
    // ============================================================================

    /**
     * Fetch HaloPSA clients for import
     */
    public function haloClients()
    {
        try {
            $token = $this->getHaloAccessToken();
            
            if (!$token) {
                return response()->json([
                    'error' => 'Failed to authenticate with HaloPSA'
                ], 500);
            }

            $response = Http::withToken($token)
                ->get(config('services.halo.api_url') . '/Client', [
                    'count' => 100,
                    'order' => 'name',
                ]);

            if (!$response->successful()) {
                Log::error('Failed to fetch HaloPSA clients', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return response()->json([
                    'error' => 'Failed to fetch clients from HaloPSA'
                ], 500);
            }

            $clients = $response->json()['clients'] ?? [];

            // Filter out clients that are already imported (by halopsa_reference)
            $existingRefs = Client::whereNotNull('halopsa_reference')
                ->pluck('halopsa_reference')
                ->toArray();

            $availableClients = array_filter($clients, function($client) use ($existingRefs) {
                $clientId = (string) $client['id'];
                return !in_array($clientId, $existingRefs);
            });

            // Format for your frontend
            $formatted = array_map(function($client) {
                return [
                    'id' => $client['id'],
                    'name' => $client['name'] ?? '',
                    'reference' => $client['id'], // Using ID as reference
                ];
            }, array_values($availableClients));

            return response()->json($formatted);

        } catch (\Exception $e) {
            Log::error('Error fetching HaloPSA clients: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Error fetching HaloPSA clients: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import clients from HaloPSA with domain linking
     */
    public function importHaloClients(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'client_ids' => 'required|array',
                'client_ids.*' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $clientIds = $request->input('client_ids');
            $imported = 0;
            $domainsLinked = 0;

            foreach ($clientIds as $haloId) {
                // Fetch full client details
                $clientDetails = $this->fetchHaloClientDetails($haloId);
                
                if (!$clientDetails) {
                    Log::warning("Skipping HaloPSA client {$haloId} - failed to fetch details");
                    continue;
                }

                // Check if already exists
                $existing = Client::where('halopsa_reference', (string) $haloId)->first();
                if ($existing) {
                    Log::info("Skipping HaloPSA client {$haloId} - already imported");
                    continue;
                }

                // Create client
                $client = Client::create([
                    'business_name' => $clientDetails['name'],
                    'abn' => $clientDetails['abn'],
                    'halopsa_reference' => (string) $haloId,
                    'active' => 1,
                ]);

                $imported++;

                // Fetch and link domain assets
                $domainAssets = $this->fetchHaloDomainAssets($haloId);
                
                foreach ($domainAssets as $asset) {
                    if ($asset['matched_domain']) {
                        $domain = Domain::find($asset['matched_domain']['id']);
                        
                        if ($domain) {
                            $domain->update([
                                'client_id' => $client->id,
                                'halo_asset_id' => $asset['halo_asset_id']
                            ]);
                            $domainsLinked++;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'imported' => $imported,
                'domains_linked' => $domainsLinked
            ]);

        } catch (\Exception $e) {
            Log::error('Client import error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'An error occurred during import: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // ITGLUE INTEGRATION
    // ============================================================================

    /**
     * Search ITGlue organizations
     */
    public function itglueSearch(Request $request)
    {
        try {
            $query = $request->input('q', '');

            $response = Http::withHeaders([
                'x-api-key' => config('services.itglue.api_key'),
                'Content-Type' => 'application/vnd.api+json'
            ])->get(config('services.itglue.api_url') . '/organizations', [
                'page' => ['size' => 100]
            ]);

            if (!$response->successful()) {
                Log::error('Failed to fetch ITGlue organizations', [
                    'status' => $response->status()
                ]);
                
                return response()->json([
                    'error' => 'Failed to fetch ITGlue organizations'
                ], 500);
            }

            $organizations = $response->json()['data'] ?? [];

            return response()->json([
                'data' => $organizations
            ]);

        } catch (\Exception $e) {
            Log::error('Error searching ITGlue organizations: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Error searching ITGlue organizations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync client domains to ITGlue
     */
    public function syncDomainsToItglue(Client $client)
    {
        try {
            if (!$client->itglue_org_id) {
                return response()->json([
                    'error' => 'Client must be linked to an ITGlue organization first'
                ], 400);
            }

            $domains = $client->domains;
            
            if ($domains->isEmpty()) {
                return response()->json([
                    'error' => 'No domains found for this client'
                ], 400);
            }

            $syncResults = [];

            foreach ($domains as $domain) {
                $result = $this->syncSingleDomainToItglue($client, $domain);
                $syncResults[] = [
                    'domain' => $domain->name,
                    'success' => $result['success'],
                    'message' => $result['message'],
                    'itglue_id' => $result['itglue_id'] ?? null
                ];
            }

            $successCount = collect($syncResults)->where('success', true)->count();

            return response()->json([
                'success' => true,
                'message' => "Synced {$successCount} of " . count($syncResults) . " domains to ITGlue",
                'results' => $syncResults
            ]);

        } catch (\Exception $e) {
            Log::error('Error syncing domains to ITGlue: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Error syncing domains to ITGlue: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync DNS records to HaloPSA asset notes
     */
    public function syncDnsToHalo(Client $client)
    {
        try {
            if (!$client->halopsa_reference) {
                return response()->json([
                    'error' => 'Client must be linked to HaloPSA first'
                ], 400);
            }

            $domains = $client->domains()->whereNotNull('halo_asset_id')->get();
            
            if ($domains->isEmpty()) {
                return response()->json([
                    'error' => 'No domains with HaloPSA asset IDs found'
                ], 400);
            }

            $syncResults = [];

            foreach ($domains as $domain) {
                $result = $this->syncDnsToHaloAsset($domain);
                $syncResults[] = [
                    'domain' => $domain->name,
                    'halo_asset_id' => $domain->halo_asset_id,
                    'success' => $result['success'],
                    'message' => $result['message']
                ];
            }

            $successCount = collect($syncResults)->where('success', true)->count();

            return response()->json([
                'success' => true,
                'message' => "Synced DNS for {$successCount} of " . count($syncResults) . " domains to HaloPSA",
                'results' => $syncResults
            ]);

        } catch (\Exception $e) {
            Log::error('Error syncing DNS to HaloPSA: ' . $e->getMessage());
            
            return response()->json([
                'error' => 'Error syncing DNS to HaloPSA: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // PRIVATE HELPER METHODS
    // ============================================================================

    /**
     * Get HaloPSA access token
     */
    private function getHaloAccessToken()
    {
        try {
            $response = Http::asForm()->post(config('services.halo.auth_url') . '/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.halo.client_id'),
                'client_secret' => config('services.halo.client_secret'),
                'scope' => 'all'
            ]);

            if ($response->successful()) {
                return $response->json()['access_token'] ?? null;
            }

            Log::error('Failed to get HaloPSA token', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting Halo access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch full client details from HaloPSA
     */
    private function fetchHaloClientDetails($haloId)
    {
        try {
            $token = $this->getHaloAccessToken();
            
            if (!$token) {
                return null;
            }

            $response = Http::withToken($token)
                ->get(config('services.halo.api_url') . '/Client/' . $haloId);

            if (!$response->successful()) {
                Log::error('Failed to fetch client details', [
                    'halo_id' => $haloId,
                    'status' => $response->status()
                ]);
                return null;
            }

            $clientData = $response->json();

            return [
                'id' => $clientData['id'] ?? null,
                'name' => $clientData['name'] ?? '',
                'abn' => $clientData['customfields']['abn'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching Halo client details: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch domain assets from HaloPSA
     */
    private function fetchHaloDomainAssets($haloId)
    {
        try {
            $token = $this->getHaloAccessToken();
            
            if (!$token) {
                return [];
            }

            $response = Http::withToken($token)
                ->get(config('services.halo.api_url') . '/Asset', [
                    'client_id' => $haloId,
                    'assettype_id' => config('services.halo.domain_asset_type_id', 1),
                    'count' => 100
                ]);

            if (!$response->successful()) {
                Log::error('Failed to fetch domain assets', [
                    'halo_id' => $haloId,
                    'status' => $response->status()
                ]);
                return [];
            }

            $assets = $response->json()['assets'] ?? [];
            $domainAssets = [];

            foreach ($assets as $asset) {
                $domainName = $this->extractDomainName($asset);
                
                if ($domainName) {
                    $matchingDomain = Domain::where('name', $domainName)->first();
                    
                    $domainAssets[] = [
                        'halo_asset_id' => $asset['id'],
                        'domain_name' => $domainName,
                        'halo_asset_tag' => $asset['asset_tag'] ?? null,
                        'matched_domain' => $matchingDomain ? [
                            'id' => $matchingDomain->id,
                            'domain' => $matchingDomain->name,
                            'registrar' => $matchingDomain->registrar,
                            'current_client' => $matchingDomain->client ? $matchingDomain->client->business_name : null
                        ] : null,
                        'suggested_action' => $matchingDomain ? 'link' : 'skip'
                    ];
                }
            }

            return $domainAssets;

        } catch (\Exception $e) {
            Log::error('Error fetching Halo domain assets: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Extract domain name from HaloPSA asset
     */
    private function extractDomainName($asset)
    {
        $domainName = $asset['inventory_number'] 
            ?? $asset['asset_tag'] 
            ?? $asset['name'] 
            ?? null;

        if (!$domainName) {
            return null;
        }

        // Clean up domain name
        $domainName = strtolower(trim($domainName));
        $domainName = preg_replace('#^https?://#', '', $domainName);
        $domainName = preg_replace('#^www\.#', '', $domainName);
        $domainName = preg_replace('#/.*$#', '', $domainName);

        // Basic validation
        if (!preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/', $domainName)) {
            return null;
        }

        return $domainName;
    }

    /**
     * Sync single domain to ITGlue
     */
    private function syncSingleDomainToItglue(Client $client, Domain $domain)
    {
        try {
            // Fetch DNS records from Synergy
            $dnsRecords = $this->fetchDnsRecordsFromSynergy($domain->name);

            // Check if domain already exists in ITGlue
            $existingDomain = $this->findItglueDomain($client->itglue_org_id, $domain->name);

            $domainData = [
                'type' => 'domains',
                'attributes' => [
                    'organization-id' => $client->itglue_org_id,
                    'name' => $domain->name,
                    'registrar-name' => $domain->registrar,
                    'expires-on' => $domain->expiry_date,
                    'notes' => $this->formatDnsRecordsForNotes($dnsRecords),
                ]
            ];

            if ($existingDomain) {
                // Update existing
                $response = Http::withHeaders([
                    'x-api-key' => config('services.itglue.api_key'),
                    'Content-Type' => 'application/vnd.api+json'
                ])->patch(
                    config('services.itglue.api_url') . '/domains/' . $existingDomain['id'],
                    ['data' => $domainData]
                );

                if ($response->successful()) {
                    $domain->update(['itglue_id' => $existingDomain['id']]);
                    return ['success' => true, 'message' => 'Updated', 'itglue_id' => $existingDomain['id']];
                }
            } else {
                // Create new
                $response = Http::withHeaders([
                    'x-api-key' => config('services.itglue.api_key'),
                    'Content-Type' => 'application/vnd.api+json'
                ])->post(
                    config('services.itglue.api_url') . '/domains',
                    ['data' => $domainData]
                );

                if ($response->successful()) {
                    $itglueId = $response->json()['data']['id'];
                    $domain->update(['itglue_id' => $itglueId]);
                    return ['success' => true, 'message' => 'Created', 'itglue_id' => $itglueId];
                }
            }

            return ['success' => false, 'message' => 'Failed: ' . $response->body()];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Find ITGlue domain
     */
    private function findItglueDomain($organizationId, $domainName)
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.itglue.api_key'),
                'Content-Type' => 'application/vnd.api+json'
            ])->get(config('services.itglue.api_url') . '/domains', [
                'filter' => [
                    'organization-id' => $organizationId,
                    'name' => $domainName
                ]
            ]);

            if ($response->successful()) {
                $domains = $response->json()['data'] ?? [];
                return !empty($domains) ? $domains[0] : null;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sync DNS to HaloPSA asset
     */
    private function syncDnsToHaloAsset(Domain $domain)
    {
        try {
            $dnsRecords = $this->fetchDnsRecordsFromSynergy($domain->name);

            if (empty($dnsRecords)) {
                return ['success' => false, 'message' => 'No DNS records found'];
            }

            $dnsNotes = $this->formatDnsRecordsForNotes($dnsRecords);
            $token = $this->getHaloAccessToken();
            
            if (!$token) {
                return ['success' => false, 'message' => 'Auth failed'];
            }

            $response = Http::withToken($token)->post(
                config('services.halo.api_url') . '/Asset/' . $domain->halo_asset_id,
                ['id' => $domain->halo_asset_id, 'notes' => $dnsNotes]
            );

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Synced'];
            }

            return ['success' => false, 'message' => 'Failed: ' . $response->body()];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Fetch DNS records from Synergy
     */
    private function fetchDnsRecordsFromSynergy($domainName)
    {
        try {
            $client = new SoapClient(config('services.synergy.wsdl_url'), [
                'trace' => 1,
                'exceptions' => true
            ]);

            $params = [
                'resellerID' => config('services.synergy.reseller_id'),
                'apiKey' => config('services.synergy.api_key'),
                'domainName' => $domainName
            ];

            $response = $client->__soapCall('listDNSZone', [$params]);

            if ($response->status === 'OK') {
                return $response->records ?? [];
            }

            return [];

        } catch (\Exception $e) {
            Log::error('Error fetching DNS from Synergy: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Format DNS records for notes
     */
    private function formatDnsRecordsForNotes($dnsRecords)
    {
        if (empty($dnsRecords)) {
            return "No DNS records available.";
        }

        $notes = "=== DNS Records (Auto-synced from Domain Dash) ===\n";
        $notes .= "Last Updated: " . now()->format('Y-m-d H:i:s') . "\n\n";

        $recordsByType = [];
        foreach ($dnsRecords as $record) {
            $type = $record->type ?? 'UNKNOWN';
            if (!isset($recordsByType[$type])) {
                $recordsByType[$type] = [];
            }
            $recordsByType[$type][] = $record;
        }

        foreach ($recordsByType as $type => $records) {
            $notes .= "--- {$type} Records ---\n";
            foreach ($records as $record) {
                $hostname = $record->hostName ?? '';
                $content = $record->content ?? '';
                $ttl = $record->ttl ?? '';
                $prio = isset($record->prio) && $record->prio > 0 ? " (Priority: {$record->prio})" : '';
                
                $notes .= "  {$hostname} -> {$content} [TTL: {$ttl}]{$prio}\n";
            }
            $notes .= "\n";
        }

        return $notes;
    }
}