<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncHaloAssetsJob;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HaloAsset;
use App\Models\HaloClient;
use App\Models\ItglueOrganization;
use App\Models\SyncRun;
use App\Services\AuditLogger;
use App\Services\Ip2whois\Ip2whoisClient;
use App\Services\Sync\SyncDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Admin sync endpoints. Read endpoints serve the local mirror tables
 * (halo_clients, halo_assets, itglue_organizations); write endpoints only
 * queue jobs and return 202 immediately. No Halo / IT Glue HTTP happens here.
 */
class SyncController extends Controller
{
    public function __construct(private SyncDispatcher $dispatcher)
    {
    }

    /**
     * Halo clients (from the local mirror) matched against DomainDash clients
     */
    public function getHaloClients()
    {
        $haloClients = HaloClient::query()->where('inactive', false)->orderBy('name')->get();

        if ($haloClients->isEmpty() && ! SyncRun::lastSyncedAt('halo', 'clients')) {
            return response()->json([
                'error' => 'No HaloPSA clients have been synced yet. Click "Refresh now" to fetch them in the background.',
                'sync' => $this->dispatcher->status()['halo'],
            ], 200);
        }

        $dashClients = Client::select('id', 'business_name', 'halopsa_reference')->get();
        $byRef = $dashClients->keyBy(fn ($c) => (string) $c->halopsa_reference);

        $matched = [];
        foreach ($haloClients as $haloClient) {
            $mapped = $byRef->get((string) $haloClient->halo_id);

            $matched[] = [
                'halo_id' => $haloClient->halo_id,
                'halo_name' => $haloClient->name ?: 'Unknown Client',
                'mapped_id' => $mapped?->id,
                'suggestions' => $this->findClientMatches($haloClient->name, $dashClients),
                'updated' => $haloClient->remote_updated_at?->format('Y-m-d H:i'),
            ];
        }

        return response()->json(['clients' => $matched, 'sync' => $this->dispatcher->status()['halo']]);
    }

    /**
     * Save Halo <-> DomainDash client mappings, then queue an asset refresh per client.
     */
    public function syncHaloClients(Request $request)
    {
        $clients = $request->input('clients', []);
        $results = [];
        $queued = 0;

        foreach ($clients as $clientData) {
            $dashClient = Client::find($clientData['dash_client_id'] ?? null);
            if (! $dashClient) {
                $results[] = [
                    'item' => ['id' => $clientData['dash_client_id'] ?? null, 'type' => 'client', 'name' => $clientData['dash_name'] ?? null],
                    'success' => false,
                    'error' => 'Client not found',
                ];
                continue;
            }

            $halo = HaloClient::find((int) ($clientData['halo_id'] ?? 0));
            if (! $halo) {
                $results[] = [
                    'item' => ['id' => $dashClient->id, 'type' => 'client', 'name' => $dashClient->business_name],
                    'success' => false,
                    'error' => 'Halo client not in the local mirror - refresh first',
                ];
                continue;
            }

            $dashClient->halopsa_reference = (string) $halo->halo_id;
            if ($halo->abn) {
                $dashClient->abn = $halo->abn;
            }
            $dashClient->save();

            $this->dispatcher->refreshHaloClient($dashClient, $request->user()?->id);
            $queued++;

            $results[] = [
                'item' => ['id' => $dashClient->id, 'type' => 'client', 'name' => $dashClient->business_name],
                'success' => true,
                'action' => 'linked',
            ];
        }

        AuditLogger::logSystem('sync.queued', 'Halo client mapping saved; asset refresh queued.', [
            'service' => 'halo',
            'function' => 'sync-halo-clients-manual',
        ], ['new_values' => ['requested_items' => count($clients), 'queued' => $queued]]);

        return response()->json([
            'success' => true,
            'queued' => true,
            'queued_count' => $queued,
            'synced_count' => $queued,
            'results' => $results,
        ], 202);
    }

    /**
     * DomainDash domains with a Halo-existence flag read from local tables.
     */
    public function getHaloDomains()
    {
        $mirrored = HaloAsset::query()
            ->whereNotNull('inventory_number')
            ->pluck('inventory_number')
            ->mapWithKeys(fn ($n) => [strtolower($n) => true]);

        $domains = Domain::with('client:id,business_name')->get();

        $list = $domains->map(fn ($domain) => [
            'id' => $domain->id,
            'name' => $domain->name,
            'client' => $domain->client?->business_name,
            'client_id' => $domain->client_id,
            'expiry' => $domain->expiry_date,
            'nameservers' => $domain->name_servers,
            'exists_in_halo' => ! empty($domain->halo_asset_id) || isset($mirrored[strtolower($domain->name)]),
            'synced_at' => $domain->halo_synced_at?->toIso8601String(),
            'sync_error' => $domain->halo_sync_error,
        ])->values();

        return response()->json(['domains' => $list, 'sync' => $this->dispatcher->status()['halo']]);
    }

    /**
     * Queue a push of the selected domains to Halo; returns immediately.
     */
    public function syncHaloDomains(Request $request)
    {
        $domainIds = array_map('intval', (array) $request->input('domain_ids', []));

        $run = $this->dispatcher->pushHaloDomains($domainIds, null, true, 'asset', $request->user()?->id);

        return response()->json([
            'success' => true,
            'queued' => true,
            'queued_count' => count($domainIds),
            'synced_count' => 0,
            'run' => $run->toStatusArray(),
        ], 202);
    }

    /**
     * Queue a refresh of the Halo mirror (clients + domain assets).
     */
    public function refreshHalo(Request $request)
    {
        $runs = $this->dispatcher->refreshHalo($request->user()?->id);

        return response()->json([
            'queued' => true,
            'runs' => array_map(fn (SyncRun $r) => $r->toStatusArray(), $runs),
        ], 202);
    }

    /**
     * Queue a refresh of the IT Glue organisation mirror.
     */
    public function refreshItGlue(Request $request)
    {
        $run = $this->dispatcher->refreshItGlue($request->user()?->id);

        return response()->json(['queued' => true, 'runs' => [$run->toStatusArray()]], 202);
    }

    /**
     * Queue a per-client refresh ("Refresh now" on the client row).
     */
    public function refreshClient(Request $request, Client $client, string $provider)
    {
        if ($provider === 'halo') {
            if (! $client->halopsa_reference) {
                return response()->json(['error' => 'Client is not linked to HaloPSA'], 422);
            }
            $run = $this->dispatcher->refreshHaloClient($client, $request->user()?->id);
        } elseif ($provider === 'itglue') {
            if (! $client->itglue_org_id) {
                return response()->json(['error' => 'Client is not linked to an IT Glue organisation'], 422);
            }
            $run = $this->dispatcher->pushItGlueDomains(null, $client->id, true, $request->user()?->id);
        } else {
            abort(404);
        }

        return response()->json(['queued' => true, 'run' => $run->toStatusArray()], 202);
    }

    public function syncStatus()
    {
        return response()->json($this->dispatcher->status());
    }

    public function showRun(SyncRun $run)
    {
        return response()->json($run->toStatusArray());
    }

    /**
     * IT Glue organisations (from the local mirror) for mapping
     */
    public function getItGlueClients()
    {
        $orgs = ItglueOrganization::query()->orderBy('name')->get(['itglue_id', 'name']);

        if ($orgs->isEmpty()) {
            return response()->json([
                'error' => 'No IT Glue organisations have been synced yet. Click "Refresh now" to fetch them in the background.',
                'sync' => $this->dispatcher->status()['itglue'],
            ]);
        }

        $organizations = $orgs->map(fn ($o) => ['id' => (string) $o->itglue_id, 'name' => $o->name])->values()->all();

        $clientList = Client::select('id', 'business_name', 'itglue_org_id')->get()->map(fn ($c) => [
            'dash_id' => $c->id,
            'dash_name' => $c->business_name,
            'mapped_id' => $c->itglue_org_id,
            'organizations' => $organizations,
        ])->values();

        return response()->json(['clients' => $clientList, 'sync' => $this->dispatcher->status()['itglue']]);
    }

    /**
     * Suggest IT Glue organization for a client (local mirror only)
     */
    public function suggestItGlueOrg($clientId)
    {
        $client = Client::findOrFail($clientId);
        $clientName = strtolower(trim($client->business_name));

        $bestMatch = null;
        $bestScore = 0;

        foreach (ItglueOrganization::query()->get(['itglue_id', 'name']) as $org) {
            $orgName = strtolower(trim($org->name));
            if ($orgName === '') {
                continue;
            }

            if ($clientName === $orgName) {
                $score = 1000;
            } elseif (str_contains($orgName, $clientName) || str_contains($clientName, $orgName)) {
                $score = 500 + similar_text($clientName, $orgName);
            } else {
                similar_text($clientName, $orgName, $percent);
                $score = $percent;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = (string) $org->itglue_id;
            }
        }

        if ($bestMatch === null) {
            return response()->json(['error' => 'No organizations found in IT Glue (has the mirror been synced?)'], 404);
        }

        return response()->json(['suggested_org_id' => $bestMatch]);
    }

    /**
     * Save IT Glue client mappings
     */
    public function syncItGlueClients(Request $request)
    {
        $mappings = $request->input('mappings', []);
        AuditLogger::logSystem('sync.started', 'Manual IT Glue client mapping sync started.', [
            'service' => 'itglue',
            'function' => 'sync-itglue-clients-manual',
        ], [
            'new_values' => [
                'requested_items' => count($mappings),
            ],
        ]);

        try {
            $mappedCount = 0;
            $results = [];

            foreach ($mappings as $mapping) {
                $client = Client::find($mapping['dash_client_id']);
                if ($client) {
                    // Use existing itglue_org_id column
                    $client->itglue_org_id = $mapping['itglue_org_id'];
                    $client->save();
                    $mappedCount++;
                    $results[] = [
                        'item' => [
                            'id' => $client->id,
                            'type' => 'client',
                            'name' => $client->business_name,
                        ],
                        'success' => true,
                        'action' => 'mapped',
                    ];
                    continue;
                }

                $results[] = [
                    'item' => [
                        'id' => $mapping['dash_client_id'] ?? null,
                        'type' => 'client',
                        'name' => null,
                    ],
                    'success' => false,
                    'error' => 'Client not found',
                ];
            }

            $resultSummary = $this->formatSyncAuditResults($results);
            AuditLogger::logSystem('sync.completed', 'Manual IT Glue client mapping sync completed.', [
                'service' => 'itglue',
                'function' => 'sync-itglue-clients-manual',
            ], [
                'new_values' => [
                    'requested_items' => count($mappings),
                    'mapped_count' => $mappedCount,
                    'failed_count' => count($resultSummary['failed']),
                    'synced_items' => $resultSummary['synced'],
                    'failed_items' => $resultSummary['failed'],
                ],
            ]);

            return response()->json([
                'success' => true,
                'mapped_count' => $mappedCount,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            AuditLogger::logSystem('sync.failed', 'Manual IT Glue client mapping sync failed.', [
                'service' => 'itglue',
                'function' => 'sync-itglue-clients-manual',
            ], [
                'new_values' => [
                    'requested_items' => count($mappings),
                    'error' => $e->getMessage(),
                ],
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Configuration items for IT Glue sync (DB only)
     */
    public function getItGlueConfigurations()
    {
        $items = Domain::with('client:id,business_name,itglue_org_id')
            ->whereHas('client', fn ($q) => $q->whereNotNull('itglue_org_id'))
            ->get()
            ->map(fn ($domain) => [
                'id' => $domain->id,
                'type' => 'domain',
                'name' => $domain->name,
                'client' => $domain->client->business_name,
                'exists_in_itglue' => ! empty($domain->itglue_id),
                'synced_at' => $domain->itglue_synced_at?->toIso8601String(),
                'sync_error' => $domain->itglue_sync_error,
            ])->values();

        return response()->json(['items' => $items, 'sync' => $this->dispatcher->status()['itglue']]);
    }

    /**
     * List domains for IP2WHOIS syncing
     */
    public function getIp2whoisDomains()
    {
        try {
            $domains = Domain::with('client')->get();

            $items = $domains->map(function ($domain) {
                return [
                    'id' => $domain->id,
                    'name' => $domain->name,
                    'client' => optional($domain->client)->business_name,
                    'synced_at' => $domain->whois_synced_at,
                    'has_data' => !empty($domain->whois_data),
                ];
            });

            return response()->json(['items' => $items]);
        } catch (\Exception $e) {
            Log::error('Error loading IP2WHOIS domains: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync WHOIS data from IP2WHOIS for selected domains
     */
    public function syncIp2whoisDomains(Request $request)
    {
        $items = $request->input('items', []);

        $synced = 0;
        $results = [];

        try {
            $client = new Ip2whoisClient();

            foreach ($items as $item) {
                $domain = Domain::find($item['id'] ?? null);

                if (!$domain) {
                    $results[] = ['id' => $item['id'] ?? null, 'success' => false, 'error' => 'Domain not found'];
                    continue;
                }

                $lookup = $client->lookup($domain->name);

                if ($lookup['success']) {
                    $domain->whois_data = $lookup['data'];
                    $domain->whois_synced_at = now();
                    $domain->save();

                    $synced++;
                    $results[] = ['id' => $domain->id, 'success' => true];
                } else {
                    // Treat "no data found" as a soft warning so bulk sync can proceed
                    if (!empty($lookup['not_found'])) {
                        $results[] = [
                            'id' => $domain->id,
                            'success' => false,
                            'warning' => 'No WHOIS data found for this domain',
                        ];
                        continue;
                    }

                    $results[] = [
                        'id' => $domain->id,
                        'success' => false,
                        'error' => $lookup['error'] ?? 'Lookup failed'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'synced_count' => $synced,
                'results' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('IP2WHOIS sync error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Queue a push of the selected configuration items (domains) to IT Glue.
     */
    public function syncItGlueConfigurations(Request $request)
    {
        $ids = collect($request->input('items', []))
            ->filter(fn ($i) => ($i['type'] ?? '') === 'domain')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $run = $this->dispatcher->pushItGlueDomains($ids, null, true, $request->user()?->id);

        return response()->json([
            'success' => true,
            'queued' => true,
            'queued_count' => count($ids),
            'synced_count' => 0,
            'run' => $run->toStatusArray(),
        ], 202);
    }

    // Helper Methods

    private function formatSyncAuditResults(array $results): array
    {
        $synced = [];
        $failed = [];

        foreach ($results as $result) {
            $row = [
                'id' => $result['item']['id'] ?? null,
                'type' => $result['item']['type'] ?? null,
                'name' => $result['item']['name'] ?? null,
            ];

            if (($result['success'] ?? false) === true) {
                $row['action'] = $result['action'] ?? 'updated';
                $synced[] = $row;
                continue;
            }

            $row['error'] = $result['error'] ?? null;
            $failed[] = $row;
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
        ];
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
}
