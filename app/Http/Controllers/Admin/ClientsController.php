<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Setting;
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
    public function index(Request $request)
    {
        $sortColumn = $request->get('sort', 'business_name');
        $sortDirection = $request->get('direction', 'asc');

        // Validate sort column to prevent SQL injection
        $allowedColumns = ['business_name', 'abn', 'halopsa_reference', 'itglue_org_id', 'active'];
        if (!in_array($sortColumn, $allowedColumns)) {
            $sortColumn = 'business_name';
        }

        // Validate direction
        $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        $clients = Client::orderBy($sortColumn, $sortDirection)->paginate(15);

        // Preserve sort parameters in pagination links
        $clients->appends(['sort' => $sortColumn, 'direction' => $sortDirection]);

        return view('admin.clients.index', compact('clients', 'sortColumn', 'sortDirection'));
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
            'itglue_org_id' => 'nullable|string|max:255',
            'itglue_org_name' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ]);

        $validated['active'] = $request->has('active') ? 1 : 0;

        $client = Client::create($validated);

        // Log the creation to audit log
        \App\Services\AuditLogger::logCreate(
            $client,
            "Created client: {$client->business_name}"
        );

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client created successfully.');
    }

    /**
     * Show the form for editing the specified client
     */
    public function edit(Client $client)
    {
        $assignedUsers = $client->users ?? collect();
        
        return view('admin.clients.form', compact('client', 'assignedUsers'));
    }

    /**
     * Update the specified client
     */
    public function update(Request $request, Client $client)
    {
        Log::info('Client update started', [
            'client_id' => $client->id,
            'request_data' => $request->all()
        ]);

        // Store original values for audit log
        $originalValues = $client->getAttributes();

        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'abn' => 'nullable|string|max:50',
            'halopsa_reference' => 'nullable|string|max:255',
            'itglue_org_id' => 'nullable|string|max:255',
            'itglue_org_name' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
        ]);

        $validated['active'] = $request->has('active') ? 1 : 0;

        Log::info('Validation passed', [
            'validated_data' => $validated
        ]);

        $client->update($validated);

        // Log the update to audit log
        \App\Services\AuditLogger::logUpdate(
            $client,
            $originalValues,
            "Updated client: {$client->business_name}"
        );

        Log::info('Client updated', [
            'client_id' => $client->id,
            'new_values' => $client->fresh()->toArray()
        ]);

        return redirect()->route('admin.clients.index')
            ->with('success', 'Client updated successfully.');
    }

    /**
     * Delete the specified client with email confirmation and audit logging
     */
    public function destroy(Request $request, Client $client)
    {
        $validated = $request->validate([
            'confirmed_email' => 'required|email',
        ]);

        $confirmedEmail = $validated['confirmed_email'];

        try {
            // Log the deletion to audit log before deleting
            \App\Services\AuditLogger::logDelete(
                $client,
                $confirmedEmail,
                "Deleted client: {$client->business_name} (ID: {$client->id})"
            );

            // Store client name for success message
            $clientName = $client->business_name;

            // Delete the client (this will also remove relationships due to foreign key constraints)
            $client->delete();

            Log::info('Client deleted', [
                'client_name' => $clientName,
                'confirmed_email' => $confirmedEmail,
                'user_id' => auth()->id(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Client '{$clientName}' has been permanently deleted."
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting client: ' . $e->getMessage(), [
                'client_id' => $client->id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete client. Please contact support.'
            ], 500);
        }
    }

    // ============================================================================
    // HALOPSA INTEGRATION
    // ============================================================================

    /**
     * Fetch HaloPSA clients for import or reconnection
     */
    public function haloClients(Request $request)
    {
        try {
            $token = $this->getHaloAccessToken();

            if (!$token) {
                return response()->json([
                    'error' => 'Failed to authenticate with HaloPSA'
                ], 500);
            }

            $haloSettings = Setting::get('halo', []);
            $baseUrl = rtrim($haloSettings['base_url'] ?? '', '/');

            if (empty($baseUrl)) {
                return response()->json([
                    'error' => 'HaloPSA base URL is not configured in settings'
                ], 500);
            }

            if (!str_ends_with($baseUrl, '/api')) {
                $baseUrl .= '/api';
            }

            $response = Http::withToken($token)
                ->timeout(30)
                ->get($baseUrl . '/Client', [
                    'count' => 100,
                    'order' => 'name',
                    'includeinactive' => false,
                ]);

            if (!$response->successful()) {
                Log::error('Failed to fetch HaloPSA clients', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => $baseUrl . '/Client'
                ]);

                return response()->json([
                    'error' => 'Failed to fetch clients from HaloPSA: HTTP ' . $response->status()
                ], 500);
            }

            $responseData = $response->json();

            $clients = $responseData['clients']
                ?? $responseData['data']
                ?? $responseData['Results']
                ?? [];

            if (empty($clients) && is_array($responseData) && isset($responseData[0])) {
                $clients = $responseData;
            }

            // Only filter out existing clients if not showing all
            $showAll = $request->query('show_all', false);

            if (!$showAll) {
                $existingRefs = Client::whereNotNull('halopsa_reference')
                    ->pluck('halopsa_reference')
                    ->toArray();

                $clients = array_filter($clients, function($client) use ($existingRefs) {
                    $clientId = (string) ($client['id'] ?? $client['Id'] ?? '');
                    return !empty($clientId) && !in_array($clientId, $existingRefs);
                });
            }

            $formatted = array_map(function($client) {
                $id = $client['id'] ?? $client['Id'] ?? null;
                $name = $client['name'] ?? $client['Name'] ?? 'Unknown';

                return [
                    'id' => $id,
                    'name' => $name,
                    'reference' => $id,
                    'full_data' => $client,
                ];
            }, array_values($clients));

            return response()->json($formatted);

        } catch (\Exception $e) {
            Log::error('Error fetching HaloPSA clients: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

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
            Log::info('Starting HaloPSA client import');
            
            $validator = Validator::make($request->all(), [
                'client_ids' => 'required|array',
                'client_ids.*' => 'required|integer',
            ]);

            if ($validator->fails()) {
                Log::error('Import validation failed', [
                    'errors' => $validator->errors()->toArray()
                ]);
                
                return response()->json([
                    'error' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $clientIds = $request->input('client_ids');
            $imported = 0;
            $domainsLinked = 0;
            $errors = [];

            Log::info('Client IDs to import', ['client_ids' => $clientIds]);

            $token = $this->getHaloAccessToken();
            
            if (!$token) {
                return response()->json([
                    'error' => 'Failed to authenticate with HaloPSA'
                ], 500);
            }

            $haloSettings = Setting::get('halo', []);
            $baseUrl = rtrim($haloSettings['base_url'] ?? '', '/');
            
            if (!str_ends_with($baseUrl, '/api')) {
                $baseUrl .= '/api';
            }

            $response = Http::withToken($token)
                ->timeout(30)
                ->get($baseUrl . '/Client', [
                    'count' => 100,
                    'order' => 'name',
                ]);

            if (!$response->successful()) {
                Log::error('Failed to fetch clients for import', [
                    'status' => $response->status()
                ]);
                return response()->json([
                    'error' => 'Failed to fetch client data from HaloPSA'
                ], 500);
            }

            $responseData = $response->json();
            $allClients = $responseData['clients'] 
                ?? $responseData['data'] 
                ?? $responseData['Results'] 
                ?? $responseData;

            $clientMap = [];
            foreach ($allClients as $client) {
                $id = $client['id'] ?? $client['Id'] ?? null;
                if ($id) {
                    $clientMap[(int)$id] = $client;
                }
            }

            foreach ($clientIds as $haloId) {
                try {
                    Log::info("Processing HaloPSA client {$haloId}");
                    
                    if (!isset($clientMap[(int)$haloId])) {
                        $errors[] = "Client {$haloId}: Not found in HaloPSA response";
                        continue;
                    }

                    $clientData = $clientMap[(int)$haloId];
                    
                    $name = $clientData['name'] 
                        ?? $clientData['Name'] 
                        ?? $clientData['client_name'] 
                        ?? 'Unknown Client';
                    
                    $abn = $clientData['customfields']['abn']
                        ?? $clientData['CustomFields']['abn']
                        ?? $clientData['customfields']['ABN']
                        ?? $clientData['CustomFields']['ABN']
                        ?? $clientData['abn']
                        ?? $clientData['ABN']
                        ?? null;

                    $existing = Client::where('halopsa_reference', (string) $haloId)->first();
                    if ($existing) {
                        $errors[] = "Client {$haloId} ({$name}): Already imported";
                        continue;
                    }

                    $client = Client::create([
                        'business_name' => $name,
                        'abn' => $abn,
                        'halopsa_reference' => (string) $haloId,
                        'active' => 1,
                    ]);

                    Log::info("Created client", [
                        'id' => $client->id,
                        'business_name' => $client->business_name
                    ]);

                    $imported++;

                    // Use HaloPSA service to fetch domain assets
                    $halo = new \App\Services\Halo\HaloPsaClient();
                    $domainAssets = $halo->listDomainAssetsForClient((int) $haloId);
                    
                    Log::info("Found domain assets", [
                        'halo_id' => $haloId,
                        'count' => count($domainAssets)
                    ]);
                    
                    foreach ($domainAssets as $asset) {
                        $assetId = $asset['id'] ?? $asset['Id'] ?? null;
                        $assetName = $asset['inventory_number'] 
                            ?? $asset['asset_tag'] 
                            ?? $asset['name'] 
                            ?? null;
                        
                        if ($assetName && $assetId) {
                            $domainName = $this->extractDomainName(['name' => $assetName]);
                            
                            if ($domainName) {
                                $domain = Domain::where('name', $domainName)->first();
                                
                                if ($domain) {
                                    $domain->update([
                                        'client_id' => $client->id,
                                        'halo_asset_id' => $assetId
                                    ]);
                                    $domainsLinked++;
                                    
                                    Log::info("Linked domain", [
                                        'domain' => $domain->name,
                                        'client_id' => $client->id,
                                        'halo_asset_id' => $assetId
                                    ]);
                                }
                            }
                        }
                    }
                    
                } catch (\Exception $e) {
                    $msg = "Error processing client: " . $e->getMessage();
                    Log::error($msg, [
                        'halo_id' => $haloId,
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors[] = "Client {$haloId}: {$msg}";
                }
            }

            $response = [
                'success' => true,
                'imported' => $imported,
                'domains_linked' => $domainsLinked
            ];

            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }

            Log::info('Import complete', $response);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Client import fatal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'error' => 'Import failed: ' . $e->getMessage()
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
            $itglue = new \App\Services\ItGlue\ItGlueClient();
            $data = $itglue->listOrganisations();

            return response()->json($data);

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

            Log::info('Starting ITGlue domain sync', [
                'client_id' => $client->id,
                'domain_count' => $domains->count()
            ]);

            $itglue = new \App\Services\ItGlue\ItGlueClient();
            $syncResults = [];

            foreach ($domains as $domain) {
                // Fetch DNS records from Synergy
                $dnsRecords = $this->fetchDnsRecordsFromSynergy($domain->name);
                
                // Sync to ITGlue using the service class
                $result = $itglue->syncDomain(
                    $domain,
                    (int) $client->itglue_org_id,
                    $dnsRecords
                );
                
                $syncResults[] = [
                    'domain' => $domain->name,
                    'success' => $result['success'],
                    'message' => $result['success'] 
                        ? ucfirst($result['action']) 
                        : ($result['error'] ?? 'Failed'),
                    'configuration_id' => $result['configuration_id'] ?? null
                ];
                
                // Update domain with ITGlue configuration ID
                if ($result['success'] && isset($result['configuration_id'])) {
                    $domain->update(['itglue_configuration_id' => $result['configuration_id']]);
                }
            }

            $successCount = collect($syncResults)->where('success', true)->count();

            return response()->json([
                'success' => true,
                'message' => "Synced {$successCount} of " . count($syncResults) . " domains to ITGlue",
                'results' => $syncResults
            ]);

        } catch (\Exception $e) {
            Log::error('Error syncing domains to ITGlue: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
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
            
            // If no domains have asset IDs, try to find and link them
            if ($domains->isEmpty()) {
                Log::info('No domains with asset IDs, attempting to find HaloPSA assets', [
                    'client_id' => $client->id,
                    'halopsa_reference' => $client->halopsa_reference
                ]);
                
                $halo = new \App\Services\Halo\HaloPsaClient();
                $domainAssets = $halo->listDomainAssetsForClient((int) $client->halopsa_reference);
                
                if (empty($domainAssets)) {
                    return response()->json([
                        'error' => 'No domain assets found in HaloPSA for this client'
                    ], 400);
                }
                
                // Try to match and link assets
                $linked = 0;
                foreach ($domainAssets as $asset) {
                    $assetId = $asset['id'] ?? $asset['Id'] ?? null;
                    $assetName = $asset['inventory_number'] 
                        ?? $asset['asset_tag'] 
                        ?? $asset['name'] 
                        ?? null;
                        
                    if ($assetName && $assetId) {
                        $domainName = $this->extractDomainName(['name' => $assetName]);
                        
                        if ($domainName) {
                            $domain = $client->domains()->where('name', $domainName)->first();
                            if ($domain) {
                                $domain->update(['halo_asset_id' => $assetId]);
                                $linked++;
                            }
                        }
                    }
                }
                
                if ($linked > 0) {
                    $domains = $client->domains()->whereNotNull('halo_asset_id')->get();
                } else {
                    return response()->json([
                        'error' => 'Found ' . count($domainAssets) . ' domain assets in HaloPSA but could not match them to DomainDash domains'
                    ], 400);
                }
            }

            Log::info('Starting HaloPSA DNS sync', [
                'client_id' => $client->id,
                'domain_count' => $domains->count()
            ]);

            $halo = new \App\Services\Halo\HaloPsaClient();
            $syncResults = [];

            foreach ($domains as $domain) {
                // Fetch DNS records from Synergy
                $dnsRecords = $this->fetchDnsRecordsFromSynergy($domain->name);
                
                // Sync to HaloPSA using the service class
                $result = $halo->syncDomainAssetDns(
                    $domain,
                    (int) $domain->halo_asset_id,
                    $dnsRecords
                );
                
                $syncResults[] = [
                    'domain' => $domain->name,
                    'halo_asset_id' => $domain->halo_asset_id,
                    'success' => $result['success'],
                    'message' => $result['success'] ? 'Synced' : ($result['error'] ?? 'Failed')
                ];
            }

            $successCount = collect($syncResults)->where('success', true)->count();

            return response()->json([
                'success' => true,
                'message' => "Synced DNS for {$successCount} of " . count($syncResults) . " domains to HaloPSA",
                'results' => $syncResults
            ]);

        } catch (\Exception $e) {
            Log::error('Error syncing DNS to HaloPSA: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
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
            $haloSettings = Setting::get('halo', []);
            
            $authUrl = rtrim($haloSettings['auth_server'] ?? '', '/');
            $clientId = $haloSettings['client_id'] ?? null;
            $clientSecret = $haloSettings['api_key'] ?? null;
            $tenant = $haloSettings['tenant'] ?? null;

            if (empty($authUrl) || empty($clientId) || empty($clientSecret)) {
                Log::error('HaloPSA credentials not configured');
                return null;
            }

            $tokenUrl = $authUrl . '/token';
            if (!empty($tenant)) {
                $tokenUrl .= '?tenant=' . urlencode($tenant);
            }

            $response = Http::asForm()->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'all'
            ]);

            if ($response->successful()) {
                return $response->json()['access_token'] ?? null;
            }

            Log::error('Failed to get HaloPSA token', [
                'status' => $response->status()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting Halo access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract domain name from asset data
     */
    private function extractDomainName($asset)
    {
        $domainName = $asset['inventory_number'] 
            ?? $asset['InventoryNumber']
            ?? $asset['asset_tag'] 
            ?? $asset['AssetTag']
            ?? $asset['name'] 
            ?? $asset['Name']
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
     * Fetch DNS records from Synergy Wholesale
     */
    private function fetchDnsRecordsFromSynergy($domainName)
    {
        try {
            $synergySettings = Setting::get('synergy', []);
            $wsdlPath = $synergySettings['wsdl_path'] ?? null;
            $resellerId = $synergySettings['reseller_id'] ?? null;
            $apiKey = $synergySettings['api_key'] ?? null;

            if (empty($wsdlPath) || empty($resellerId) || empty($apiKey)) {
                Log::warning('Synergy Wholesale not fully configured');
                return [];
            }

            $client = new SoapClient($wsdlPath, [
                'trace' => 1,
                'exceptions' => true
            ]);

            $params = [
                'resellerID' => $resellerId,
                'apiKey' => $apiKey,
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
}