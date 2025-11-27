<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\User;
use App\Models\Domain;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Support\Facades\Log;

class ClientsController extends Controller
{
    public function index()
    {
        $clients = Client::orderBy('business_name')->paginate(25);
        return view('admin.clients.index', compact('clients'));
    }

    public function create()
    {
        return view('admin.clients.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'business_name'     => 'required|string|max:255',
            'abn'               => 'nullable|string|max:255',
            'halopsa_reference' => 'nullable|string|max:255',
            'itglue_org_name'   => 'nullable|string|max:255',
            'itglue_org_id'     => 'nullable|string|max:255',
            'active'            => 'nullable',
        ]);

        $data['active'] = $request->boolean('active');

        Client::create($data);

        return redirect()
            ->route('admin.clients.index')
            ->with('status', 'Client created successfully.');
    }

    public function edit(Client $client)
    {
        $assignedUsers = $client->users()
            ->select('users.id', 'users.name', 'users.email')
            ->get();

        return view('admin.clients.form', compact('client', 'assignedUsers'));
    }

    public function update(Request $request, Client $client)
    {
        $data = $request->validate([
            'business_name'     => 'required|string|max:255',
            'abn'               => 'nullable|string|max:255',
            'halopsa_reference' => 'nullable|string|max:255',
            'itglue_org_name'   => 'nullable|string|max:255',
            'itglue_org_id'     => 'nullable|string|max:255',
        ]);

        $data['active'] = $request->boolean('active');

        $client->update($data);

        return redirect()
            ->route('admin.clients.edit', $client)
            ->with('status', 'Client updated');
    }

    /**
     * ITGlue organisation search endpoint.
     *
     * GET /admin/clients/itglue-search?q=term
     * -> [ { id, name }, ... ]
     */
    public function itglueSearch(ItGlueClient $itglue, Request $request)
    {
        $term = trim((string) $request->get('q', ''));

        try {
            $raw = $itglue->listOrganisations($term);
            $items = $raw['data'] ?? $raw;

            $orgs = collect($items)
                ->filter(fn ($org) => is_array($org))
                ->map(function (array $org) {
                    $id   = $org['id'] ?? $org['Id'] ?? null;
                    $name = $org['attributes']['name'] ?? $org['Name'] ?? null;

                    return [
                        'id'   => $id,
                        'name' => $name,
                    ];
                })
                ->filter(fn ($org) => !empty($org['id']) && !empty($org['name']))
                ->values()
                ->all();

            return response()->json($orgs);

        } catch (\Throwable $e) {
            Log::error('ITGlue organisation search failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => true,
                'message' => 'Failed to load organisations from ITGlue. Please check API settings.',
            ], 500);
        }
    }

    /**
     * Return a lightweight list of Halo clients for the import modal.
     *
     * GET /admin/clients/halo/clients
     * -> [ { id, name, reference }, ... ]
     */
    public function haloClients(HaloPsaClient $halo, Request $request)
    {
        $page     = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('pageSize', 100);

        try {
            $raw = $halo->listClients($page, $pageSize);
        } catch (\Throwable $e) {
            Log::error('Halo listClients failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => true,
                'message' => 'Failed to load clients from HaloPSA.',
            ], 500);
        }

        // $raw may already be the list of clients,
        // or it may be wrapped in 'clients' / 'data' / 'Results'
        $items = $raw;

        if (is_array($raw)) {
            if (isset($raw['clients']) && is_array($raw['clients'])) {
                $items = $raw['clients'];
            } elseif (isset($raw['data']) && is_array($raw['data'])) {
                $items = $raw['data'];
            } elseif (isset($raw['Results']) && is_array($raw['Results'])) {
                $items = $raw['Results'];
            }
        }

        $itemsArray = is_array($items) ? $items : [];

        $clients = collect($itemsArray)
            ->filter(fn ($c) => is_array($c))
            ->map(function (array $c) {
                $id = $c['Id']
                    ?? $c['id']
                    ?? $c['ClientID']
                    ?? $c['ClientId']
                    ?? $c['clientID']
                    ?? $c['clientId']
                    ?? null;

                $name = $c['Name']
                    ?? $c['name']
                    ?? $c['ClientName']
                    ?? $c['clientName']
                    ?? $c['CompanyName']
                    ?? null;

                $reference = $c['Reference']
                    ?? $c['reference']
                    ?? $c['Code']
                    ?? $c['ClientCode']
                    ?? $c['clientCode']
                    ?? $c['ShortName']
                    ?? $name;

                return [
                    'id'        => $id,
                    'name'      => $name ?: '',
                    'reference' => $reference ?: '',
                ];
            })
            ->filter(fn ($c) => !empty($c['id']) && !empty($c['name']))
            ->values();

        Log::info('Halo clients endpoint', [
            'page'        => $page,
            'page_size'   => $pageSize,
            'raw_keys'    => is_array($raw) ? array_keys($raw) : null,
            'items_count' => is_array($itemsArray) ? count($itemsArray) : null,
            'out_count'   => $clients->count(),
        ]);

        return response()->json($clients);
    }

    /**
     * Import selected Halo clients and link any matching DomainDash domains
     * based on Halo assets of type "Domain".
     *
     * POST /admin/clients/halo/import
     * body: { client_ids: [ ... ] }
     */
    public function importHaloClients(Request $request, HaloPsaClient $halo)
    {
        $ids = (array) $request->input('client_ids', []);

        $imported      = 0;
        $domainsLinked = 0;

        foreach ($ids as $haloId) {
            if (!$haloId) {
                continue;
            }

            $haloId = (int) $haloId;

            // Fetch details for this single Halo client
            $clientData = $halo->getClient($haloId);
            if (!$clientData || !is_array($clientData)) {
                continue;
            }

            $name = $clientData['Name']
                ?? $clientData['name']
                ?? $clientData['ClientName']
                ?? $clientData['clientName']
                ?? ('Halo client ' . $haloId);

            $ref = $clientData['Reference']
                ?? $clientData['reference']
                ?? $clientData['Code']
                ?? $clientData['ClientCode']
                ?? $clientData['clientCode']
                ?? $name;

            // ABN isn't guaranteed in Halo; if you later add a custom field
            // you can map it here.
            $abn = $clientData['ABN'] ?? $clientData['Abn'] ?? null;

            $client = Client::updateOrCreate(
                ['halopsa_reference' => $ref],
                [
                    'business_name' => $name,
                    'abn'           => $abn,
                    'active'        => true,
                ]
            );

            $imported++;

            // Fetch this client's assets and link any "Domain" assets
            $assets = $halo->listAssetsForClient($haloId);

            foreach ($assets as $asset) {
                if (!is_array($asset) || !$this->isDomainAsset($asset)) {
                    continue;
                }

                $domainNames = $this->extractDomainNamesFromAsset($asset);

                foreach ($domainNames as $domainName) {
                    if (!$domainName) {
                        continue;
                    }

                    $domain = Domain::where('name', $domainName)->first();
                    if ($domain) {
                        if ($domain->client_id !== $client->id) {
                            $domain->client_id = $client->id;
                            $domain->save();
                        }
                        $domainsLinked++;

                        // Optional: here is where you COULD call a Halo sync helper
                        // to update the Halo asset's notes with expiry / NS / DNS.
                        // Example (you can wire this up later):
                        //
                        // $halo->updateDomainAssetNotesFromDomain($asset, $domain);
                    }
                }
            }
        }

        return response()->json([
            'imported'       => $imported,
            'domains_linked' => $domainsLinked,
        ]);
    }

    /**
     * Heuristic: determine if a Halo asset represents a "Domain" asset.
     */
    protected function isDomainAsset(array $asset): bool
    {
        $typeName = $asset['AssetType']['Name']
            ?? $asset['AssetTypeName']
            ?? $asset['TypeName']
            ?? null;

        if ($typeName && strcasecmp($typeName, 'Domain') === 0) {
            return true;
        }

        if (!empty($asset['Type']) && strcasecmp($asset['Type'], 'Domain') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Extract candidate domain names from a Halo asset.
     */
    protected function extractDomainNamesFromAsset(array $asset): array
    {
        $candidates = [];

        foreach (['Tag', 'AssetTag', 'Name', 'Domain', 'DomainName'] as $key) {
            if (!empty($asset[$key])) {
                $candidates[] = strtolower(trim($asset[$key]));
            }
        }

        if (!empty($asset['CustomFields']) && is_array($asset['CustomFields'])) {
            foreach ($asset['CustomFields'] as $cf) {
                $label = $cf['Name'] ?? '';
                $value = $cf['Value'] ?? '';
                if ($value && stripos($label, 'domain') !== false) {
                    $candidates[] = strtolower(trim($value));
                }
            }
        }

        return array_unique(array_filter($candidates));
    }
}
