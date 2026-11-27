<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HaloAsset;
use App\Models\HaloClient;
use App\Models\ItglueOrganization;
use App\Models\SyncRun;
use App\Services\Sync\HaloDomainPusher;
use App\Services\Sync\SyncDispatcher;
use App\Support\DomainNameExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ClientsController extends Controller
{
    /**
     * Display a listing of clients
     */
    public function index(Request $request)
    {
        $sortColumn = $request->get('sort', 'business_name');
        $sortDirection = $request->get('direction', 'asc');
        $search = $request->get('search', '');

        // Validate sort column to prevent SQL injection
        $allowedColumns = ['business_name', 'abn', 'halopsa_reference', 'itglue_org_id', 'active'];
        if (!in_array($sortColumn, $allowedColumns)) {
            $sortColumn = 'business_name';
        }

        // Validate direction
        $sortDirection = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        // Build query with search filter
        $query = Client::query()->withCount([
            'domains',
            'users',
            'domains as halo_linked_domains_count' => fn ($q) => $q->whereNotNull('halo_asset_id'),
        ]);

        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('business_name', 'like', '%' . $search . '%')
                  ->orWhere('abn', 'like', '%' . $search . '%')
                  ->orWhere('halopsa_reference', 'like', '%' . $search . '%')
                  ->orWhere('itglue_org_id', 'like', '%' . $search . '%')
                  ->orWhere('itglue_org_name', 'like', '%' . $search . '%');
            });
        }

        $clients = $query->orderBy($sortColumn, $sortDirection)->paginate(15);

        // Preserve sort and search parameters in pagination links
        $clients->appends([
            'sort' => $sortColumn,
            'direction' => $sortDirection,
            'search' => $search
        ]);

        $syncStatus = app(SyncDispatcher::class)->status();

        return view('admin.clients.index', compact('clients', 'sortColumn', 'sortDirection', 'search', 'syncStatus'));
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
     * HaloPSA clients for import / reconnection, served from the local mirror
     * (halo_clients, filled by SyncHaloClientsJob). No Halo call is made.
     */
    public function haloClients(Request $request)
    {
        $query = HaloClient::query()->where('inactive', false)->orderBy('name');

        if (! $request->query('show_all', false)) {
            $existing = Client::whereNotNull('halopsa_reference')->pluck('halopsa_reference')->all();
            if ($existing !== []) {
                $query->whereNotIn('halo_id', array_map('intval', $existing));
            }
        }

        $rows = $query->get(['halo_id', 'name']);

        if ($rows->isEmpty() && ! SyncRun::lastSyncedAt('halo', 'clients')) {
            app(SyncDispatcher::class)->refreshHalo($request->user()?->id);

            return response()->json([
                'error' => 'No HaloPSA clients synced yet. A background refresh has been queued - reopen this dialog in a minute.',
            ], 409);
        }

        return response()->json($rows->map(fn ($c) => [
            'id' => $c->halo_id,
            'name' => $c->name,
            'reference' => $c->halo_id,
        ])->values());
    }

    /**
     * Import clients from the local Halo mirror; domain linking is queued.
     */
    public function importHaloClients(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_ids' => 'required|array',
            'client_ids.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $imported = 0;
        $errors = [];
        $dispatcher = app(SyncDispatcher::class);

        foreach ($request->input('client_ids') as $haloId) {
            $halo = HaloClient::find((int) $haloId);
            if (! $halo) {
                $errors[] = "Client {$haloId}: Not found in the synced HaloPSA client list";
                continue;
            }

            if (Client::where('halopsa_reference', (string) $haloId)->exists()) {
                $errors[] = "Client {$haloId} ({$halo->name}): Already imported";
                continue;
            }

            $client = Client::create([
                'business_name' => $halo->name,
                'abn' => $halo->abn,
                'halopsa_reference' => (string) $haloId,
                'active' => 1,
            ]);
            $imported++;

            // Domain asset linking runs in the background.
            $dispatcher->refreshHaloClient($client, $request->user()?->id);
        }

        $response = ['success' => true, 'imported' => $imported, 'domains_linked' => 0, 'domain_linking_queued' => $imported];
        if ($errors) {
            $response['warnings'] = $errors;
        }

        return response()->json($response);
    }

    // ============================================================================
    // ITGLUE INTEGRATION
    // ============================================================================

    /**
     * Search IT Glue organizations (local mirror, JSON:API-shaped for the existing UI)
     */
    public function itglueSearch(Request $request)
    {
        $term = trim((string) $request->query('q', $request->query('search', '')));

        $orgs = ItglueOrganization::query()
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%' . $term . '%'))
            ->orderBy('name')
            ->limit(1000)
            ->get(['itglue_id', 'name']);

        return response()->json([
            'data' => $orgs->map(fn ($o) => [
                'id' => (string) $o->itglue_id,
                'type' => 'organizations',
                'attributes' => ['name' => $o->name],
            ])->values(),
            'meta' => ['source' => 'local', 'synced_at' => SyncRun::lastSyncedAt('itglue', 'organizations')?->toIso8601String()],
        ]);
    }

    /**
     * Queue a push of this client's domains to IT Glue.
     */
    public function syncDomainsToItglue(Client $client, Request $request)
    {
        if (! $client->itglue_org_id) {
            return response()->json(['error' => 'Client must be linked to an ITGlue organization first'], 400);
        }

        if ($client->domains()->doesntExist()) {
            return response()->json(['error' => 'No domains found for this client'], 400);
        }

        $run = app(SyncDispatcher::class)->pushItGlueDomains(null, $client->id, true, $request->user()?->id);

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Sync to IT Glue queued - it will complete in the background.',
            'run' => $run->toStatusArray(),
        ], 202);
    }

    /**
     * Queue a DNS notes update on this client's linked HaloPSA assets.
     */
    public function syncDnsToHalo(Client $client, Request $request)
    {
        if (! $client->halopsa_reference) {
            return response()->json(['error' => 'Client must be linked to HaloPSA first'], 400);
        }

        $hasAssets = $client->domains()->whereNotNull('halo_asset_id')->exists()
            || HaloAsset::where('halo_client_id', (int) $client->halopsa_reference)->exists();

        if (! $hasAssets) {
            app(SyncDispatcher::class)->refreshHaloClient($client, $request->user()?->id);

            return response()->json([
                'error' => 'No HaloPSA domain assets known for this client yet. An asset refresh was queued - try again shortly.',
            ], 409);
        }

        $run = app(SyncDispatcher::class)->pushHaloDomains(null, $client->id, true, HaloDomainPusher::MODE_DNS, $request->user()?->id);

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'DNS sync to HaloPSA queued - it will complete in the background.',
            'run' => $run->toStatusArray(),
        ], 202);
    }

    /**
     * Link domain assets (from the local Halo mirror) to this client's domains.
     */
    public function linkDomainsFromHalo(Client $client, Request $request)
    {
        if (! $client->halopsa_reference) {
            return response()->json(['error' => 'Client must be linked to HaloPSA first'], 400);
        }

        $assets = HaloAsset::where('halo_client_id', (int) $client->halopsa_reference)->get();

        if ($assets->isEmpty()) {
            app(SyncDispatcher::class)->refreshHaloClient($client, $request->user()?->id);

            return response()->json([
                'error' => 'No domain assets mirrored for this client yet. A refresh was queued - try again shortly.',
            ], 409);
        }

        $linked = $alreadyLinked = $notFound = 0;

        foreach ($assets as $asset) {
            $domainName = DomainNameExtractor::clean($asset->inventory_number ?: $asset->name);
            if (! $domainName) {
                continue;
            }

            $domain = $client->domains()->where('name', $domainName)->first();
            if (! $domain) {
                $notFound++;
            } elseif ($domain->halo_asset_id) {
                $alreadyLinked++;
            } else {
                $domain->update(['halo_asset_id' => $asset->halo_id]);
                $linked++;
            }
        }

        $message = "Found {$linked} new domain(s) to link";
        if ($alreadyLinked > 0) {
            $message .= ", {$alreadyLinked} already linked";
        }
        if ($notFound > 0) {
            $message .= ", {$notFound} not in DomainDash";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'linked' => $linked,
            'already_linked' => $alreadyLinked,
            'not_found' => $notFound,
            'synced_at' => $client->halo_synced_at?->toIso8601String(),
        ]);
    }
}
