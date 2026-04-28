<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\InternetServiceDiagnostic;
use App\Services\AuditLogger;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InternetController extends Controller
{
    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function index(Request $request)
    {
        $q = InternetService::query()->with('client');

        if ($clientId = $request->get('client_id')) {
            $q->where('client_id', $clientId);
        }

        $statusFilter = $request->has('status') ? $request->get('status') : 'ACTIVE';
        if ($statusFilter !== '') {
            $q->where('service_status', $statusFilter);
        }

        if ($typeFilter = $request->get('service_type')) {
            $q->where('service_type', $typeFilter);
        }

        $services = $q->orderBy('address_long')->paginate(25)->withQueryString();
        $clients  = Client::orderBy('business_name')->get();

        return view('admin.services.internet.index', [
            'services'     => $services,
            'clients'      => $clients,
            'clientId'     => $request->get('client_id'),
            'statusFilter' => $statusFilter,
            'typeFilter'   => $request->get('service_type'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Detail
    // -------------------------------------------------------------------------

    public function show(InternetService $service)
    {
        $liveData    = null;
        $liveError   = null;
        $clientMatch = null;

        // Fetch live data from Vocus on page load
        if ($service->vocus_service_id) {
            try {
                $vocus    = app(VocusWsmClient::class);
                $response = $vocus->getService($service->vocus_service_id);
                $liveData = $response['params'] ?? [];
            } catch (\Throwable $e) {
                $liveError = $e->getMessage();
            }
        }

        // Suggest a client match if not yet assigned
        if (!$service->client_id && $service->customer_name) {
            $clientMatch = Client::where('business_name', 'like', '%' . $service->customer_name . '%')
                ->orWhere('primary_contact_name', 'like', '%' . $service->customer_name . '%')
                ->first();
        }

        $clients     = Client::orderBy('business_name')->get();
        $diagnostics = $service->diagnostics()->take(10)->get();

        return view('admin.services.internet.show', [
            'service'     => $service,
            'liveData'    => $liveData,
            'liveError'   => $liveError,
            'clientMatch' => $clientMatch,
            'clients'     => $clients,
            'diagnostics' => $diagnostics,
        ]);
    }

    // -------------------------------------------------------------------------
    // Sync from Vocus
    // -------------------------------------------------------------------------

    public function sync(Request $request)
    {
        try {
            $vocus    = app(VocusWsmClient::class);
            $imported = 0;

            // Vocus doesn't expose a "list all services" operation — we query
            // our own known service IDs and refresh them, or import new ones
            // discovered via TCAS or provided as a seed list.
            // For existing records, refresh their live status.
            $existing = InternetService::whereNotNull('vocus_service_id')->get();

            foreach ($existing as $service) {
                try {
                    $response = $vocus->getService($service->vocus_service_id);
                    $p        = $response['params'] ?? [];

                    if (empty($p)) {
                        continue;
                    }

                    $service->service_status = $p['ServiceStatus'] ?? $service->service_status;
                    $service->plan_id        = $p['PlanID']        ?? $service->plan_id;
                    $service->service_type   = $p['ServiceType']   ?? $service->service_type;
                    $service->service_scope  = $p['ServiceScope']  ?? $service->service_scope;
                    $service->customer_name  = $p['CustomerName']  ?? $service->customer_name;
                    $service->phone          = $p['Phone']         ?? $service->phone;
                    $service->nbn_instance_id = $p['NBNInstanceID'] ?? $service->nbn_instance_id;
                    $service->avc_id         = $p['AVCID']         ?? $service->avc_id;
                    $service->cvc_id         = $p['CVCID']         ?? $service->cvc_id;
                    $service->realm          = $p['Username']      ?? $service->realm;
                    $service->billing_provider_id = $p['BillingProviderID'] ?? $service->billing_provider_id;
                    $service->synced_at      = now();
                    $service->save();
                    $imported++;
                } catch (\Throwable) {
                    // Skip individual failures and continue
                }
            }

            AuditLogger::logSystem('sync.completed', "Internet service sync completed ({$imported} records refreshed).", [
                'service'  => 'vocus',
                'function' => 'internet-sync',
            ], ['new_values' => ['refreshed' => $imported]]);

            return redirect()->route('admin.services.internet')
                ->with('status', "Internet services synced ({$imported} records refreshed).");
        } catch (\Throwable $e) {
            return redirect()->route('admin.services.internet')
                ->with('status', 'Sync failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Address qualification
    // -------------------------------------------------------------------------

    public function qualify()
    {
        return view('admin.services.internet.qualify');
    }

    /**
     * AJAX: search Vocus DIR for matching addresses.
     * Accepts either a simple address string (parsed) or structured fields.
     */
    public function qualifyLookup(Request $request)
    {
        $request->validate([
            'address'         => 'nullable|string|max:255',
            'street_number'   => 'nullable|string|max:10',
            'street_name'     => 'nullable|string|max:50',
            'street_type'     => 'nullable|string|max:10',
            'suburb'          => 'nullable|string|max:50',
            'state'           => 'nullable|string|max:10',
            'postcode'        => 'nullable|string|max:6',
            'unit_number'     => 'nullable|string|max:10',
        ]);

        try {
            $vocus = app(VocusWsmClient::class);

            // Build DIR params from either simple or structured input
            $params = [];

            if ($request->filled('street_name')) {
                if ($request->filled('unit_number')) {
                    $params['Main.Unit1stNumber'] = $request->unit_number;
                }
                if ($request->filled('street_number')) {
                    $params['Main.Street1stNumber'] = $request->street_number;
                }
                $params['Main.StreetName']  = strtoupper($request->street_name);
                $params['Main.StreetType']  = strtoupper($request->street_type ?? '');
                $params['Main.Suburb']      = strtoupper($request->suburb ?? '');
                $params['Main.State']       = strtoupper($request->state ?? '');
                $params['Main.PostCode']    = $request->postcode ?? '';
            } else {
                // Parse simple address string into components
                $params = $this->parseSimpleAddress($request->address ?? '');
            }

            $result = $vocus->lookupAddress($params);

            return response()->json([
                'addresses'      => $result['addresses'],
                'transaction_id' => $result['transaction_id'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'addresses' => []], 422);
        }
    }

    /**
     * AJAX: run service qualification against a Vocus DirectoryID.
     */
    public function qualifyCheck(Request $request)
    {
        $request->validate([
            'directory_id' => 'required|string|max:30',
            'address_long' => 'nullable|string|max:255',
        ]);

        try {
            $vocus  = app(VocusWsmClient::class);
            $result = $vocus->qualifyAddress($request->directory_id);

            return response()->json([
                'result'         => $result['params']['Result'] ?? null,
                'service_type'   => $result['params']['ServiceType'] ?? null,
                'service_class'  => $result['params']['ServiceClass'] ?? null,
                'zone'           => $result['params']['Zone'] ?? null,
                'copper_pairs'   => $result['params']['CopperPairRecord'] ?? [],
                'nbn_ports'      => $result['params']['NBNPortRecord'] ?? [],
                'params'         => $result['params'],
                'transaction_id' => $result['transaction_id'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // New order
    // -------------------------------------------------------------------------

    public function order(Request $request)
    {
        return view('admin.services.internet.order', [
            'directory_id'  => $request->get('directory_id', ''),
            'address_long'  => $request->get('address_long', ''),
            'service_type'  => $request->get('service_type', ''),
            'copper_pair_id' => $request->get('copper_pair_id', ''),
        ]);
    }

    public function orderStore(Request $request)
    {
        $validated = $request->validate([
            'vocus_service_id'  => 'required|string|max:30|unique:internet_services,vocus_service_id',
            'plan_id'           => 'required|string|max:30',
            'scope'             => 'required|in:RESELLER-CONNECT,NETWORK-CONNECT',
            'customer_name'     => 'required|string|max:100',
            'phone'             => 'required|string|max:20',
            'directory_id'      => 'required|string|max:30',
            'location_reference'=> 'required|string|max:10',
            'service_type'      => 'required|string|max:20',
            'realm'             => 'nullable|string|max:64',
            'service_level'     => 'nullable|string|max:20',
            'copper_pair_id'    => 'nullable|string|max:30',
            'password'          => 'nullable|string|max:50',
            'client_id'         => 'nullable|exists:clients,id',
            'notes'             => 'nullable|string|max:500',
        ]);

        try {
            $vocus  = app(VocusWsmClient::class);

            $params = [
                'ServiceID'        => $validated['vocus_service_id'],
                'CustomerName'     => $validated['customer_name'],
                'Phone'            => $validated['phone'],
                'DirectoryID'      => $validated['directory_id'],
                'LocationReference'=> $validated['location_reference'],
                'ServiceType'      => $validated['service_type'],
                'ServiceLevel'     => $validated['service_level'] ?? 'STANDARD',
            ];

            if (!empty($validated['realm'])) {
                $params['Realm'] = $validated['realm'];
            }
            if (!empty($validated['copper_pair_id'])) {
                $params['CopperPairID'] = $validated['copper_pair_id'];
            }
            if (!empty($validated['password'])) {
                $params['Password'] = $validated['password'];
            }

            $response = $vocus->orderService($validated['plan_id'], $validated['scope'], $params);

            $service = InternetService::create([
                'vocus_service_id'     => $validated['vocus_service_id'],
                'client_id'            => $validated['client_id'] ?? null,
                'plan_id'              => $validated['plan_id'],
                'service_scope'        => $validated['scope'],
                'service_status'       => 'INACTIVE',
                'service_type'         => $validated['service_type'],
                'order_type'           => 'NEW',
                'customer_name'        => $validated['customer_name'],
                'phone'                => $validated['phone'],
                'directory_id'         => $validated['directory_id'],
                'location_reference'   => $validated['location_reference'],
                'realm'                => $validated['realm'] ?? null,
                'service_level'        => $validated['service_level'] ?? 'STANDARD',
                'copper_pair_id'       => $validated['copper_pair_id'] ?? null,
                'last_transaction_id'  => $response['transaction_id'],
                'last_transaction_state' => 'QUEUED',
                'notes'                => $validated['notes'] ?? null,
            ]);

            AuditLogger::logSystem('internet.order', "NBN service order submitted: {$validated['vocus_service_id']}.", [
                'service'  => 'vocus',
                'function' => 'internet-order',
            ], ['new_values' => ['vocus_service_id' => $validated['vocus_service_id'], 'transaction_id' => $response['transaction_id']]]);

            return redirect()->route('admin.services.internet.show', $service)
                ->with('status', "Order submitted. Transaction ID: {$response['transaction_id']}. Status will update as Vocus processes it.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', 'Order failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Transfer (churn)
    // -------------------------------------------------------------------------

    public function transfer(Request $request)
    {
        return view('admin.services.internet.transfer', [
            'directory_id'  => $request->get('directory_id', ''),
            'address_long'  => $request->get('address_long', ''),
            'service_type'  => $request->get('service_type', ''),
            'copper_pair_id' => $request->get('copper_pair_id', ''),
        ]);
    }

    public function transferStore(Request $request)
    {
        $validated = $request->validate([
            'vocus_service_id'  => 'required|string|max:30|unique:internet_services,vocus_service_id',
            'plan_id'           => 'required|string|max:30',
            'scope'             => 'required|in:RESELLER-CONNECT,NETWORK-CONNECT',
            'customer_name'     => 'required|string|max:100',
            'phone'             => 'required|string|max:20',
            'directory_id'      => 'required|string|max:30',
            'location_reference'=> 'required|string|max:10',
            'service_type'      => 'required|string|max:20',
            'copper_pair_id'    => 'required|string|max:30',
            'ca_date'           => 'required|date_format:Y-m-d',
            'realm'             => 'nullable|string|max:64',
            'service_level'     => 'nullable|string|max:20',
            'avc_id'            => 'nullable|string|max:30',
            'cvc_id'            => 'nullable|string|max:30',
            'client_id'         => 'nullable|exists:clients,id',
            'notes'             => 'nullable|string|max:500',
        ]);

        try {
            $vocus = app(VocusWsmClient::class);

            // CADate must be YYYYMMDD format for Vocus
            $caDate = str_replace('-', '', $validated['ca_date']);

            $params = [
                'ServiceID'        => $validated['vocus_service_id'],
                'OrderType'        => 'CHURN',
                'CustomerName'     => $validated['customer_name'],
                'Phone'            => $validated['phone'],
                'DirectoryID'      => $validated['directory_id'],
                'LocationReference'=> $validated['location_reference'],
                'ServiceType'      => $validated['service_type'],
                'ServiceLevel'     => $validated['service_level'] ?? 'STANDARD',
                'CopperPairID'     => $validated['copper_pair_id'],
                'CADate'           => $caDate,
            ];

            if (!empty($validated['realm'])) {
                $params['Realm'] = $validated['realm'];
            }
            if (!empty($validated['avc_id'])) {
                $params['AVCID'] = $validated['avc_id'];
            }
            if (!empty($validated['cvc_id'])) {
                $params['CVCID'] = $validated['cvc_id'];
            }

            $response = $vocus->orderService($validated['plan_id'], $validated['scope'], $params);

            $service = InternetService::create([
                'vocus_service_id'     => $validated['vocus_service_id'],
                'client_id'            => $validated['client_id'] ?? null,
                'plan_id'              => $validated['plan_id'],
                'service_scope'        => $validated['scope'],
                'service_status'       => 'INACTIVE',
                'service_type'         => $validated['service_type'],
                'order_type'           => 'CHURN',
                'customer_name'        => $validated['customer_name'],
                'phone'                => $validated['phone'],
                'directory_id'         => $validated['directory_id'],
                'location_reference'   => $validated['location_reference'],
                'realm'                => $validated['realm'] ?? null,
                'service_level'        => $validated['service_level'] ?? 'STANDARD',
                'copper_pair_id'       => $validated['copper_pair_id'],
                'avc_id'               => $validated['avc_id'] ?? null,
                'cvc_id'               => $validated['cvc_id'] ?? null,
                'last_transaction_id'  => $response['transaction_id'],
                'last_transaction_state' => 'QUEUED',
                'notes'                => $validated['notes'] ?? null,
            ]);

            AuditLogger::logSystem('internet.transfer', "NBN service transfer submitted: {$validated['vocus_service_id']}.", [
                'service'  => 'vocus',
                'function' => 'internet-transfer',
            ], ['new_values' => ['vocus_service_id' => $validated['vocus_service_id'], 'transaction_id' => $response['transaction_id']]]);

            return redirect()->route('admin.services.internet.show', $service)
                ->with('status', "Transfer submitted. Transaction ID: {$response['transaction_id']}.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', 'Transfer failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Poll transaction status (AJAX)
    // -------------------------------------------------------------------------

    public function pollStatus(Request $request, InternetService $service)
    {
        if (!$service->last_transaction_id) {
            return response()->json(['error' => 'No transaction ID on record.'], 422);
        }

        try {
            $vocus  = app(VocusWsmClient::class);
            $result = $vocus->pollTransaction($service->last_transaction_id);
            $state  = $result['params']['TransactionState'] ?? null;

            if ($state) {
                $service->last_transaction_state = strtoupper($state);
                if ($state === 'SUCCESS') {
                    $service->service_status = 'ACTIVE';
                } elseif ($state === 'FAILED') {
                    // Keep status as-is; let admin investigate
                }
                $service->save();
            }

            return response()->json([
                'transaction_state' => $service->last_transaction_state,
                'params'            => $result['params'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Assign client
    // -------------------------------------------------------------------------

    public function assignClient(Request $request, InternetService $service)
    {
        $request->validate(['client_id' => 'nullable|exists:clients,id']);

        $service->update(['client_id' => $request->client_id ?: null]);

        AuditLogger::logSystem('internet.assign_client', "Client assigned to internet service {$service->vocus_service_id}.", [
            'service'  => 'vocus',
            'function' => 'internet-assign-client',
        ], ['new_values' => ['client_id' => $request->client_id]]);

        return back()->with('status', 'Client assignment updated.');
    }

    // -------------------------------------------------------------------------
    // Suspend / Resume / Cancel
    // -------------------------------------------------------------------------

    public function setStatus(Request $request, InternetService $service)
    {
        $request->validate(['status' => 'required|in:ACTIVE,SUSPEND,INACTIVE']);

        try {
            $vocus    = app(VocusWsmClient::class);
            $response = $vocus->setServiceStatus($service->vocus_service_id, $request->status);

            $service->update([
                'last_transaction_id'    => $response['transaction_id'],
                'last_transaction_state' => 'QUEUED',
            ]);

            AuditLogger::logSystem('internet.set_status', "Service {$service->vocus_service_id} status change to {$request->status} submitted.", [
                'service'  => 'vocus',
                'function' => 'internet-set-status',
            ], ['new_values' => ['status' => $request->status, 'transaction_id' => $response['transaction_id']]]);

            $label = match ($request->status) {
                'ACTIVE'   => 'Resume',
                'SUSPEND'  => 'Suspend',
                'INACTIVE' => 'Cancellation',
            };

            return back()->with('status', "{$label} submitted. Transaction ID: {$response['transaction_id']}.");
        } catch (\Throwable $e) {
            return back()->with('status', 'Request failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Diagnostics
    // -------------------------------------------------------------------------

    public function diagnostic(Request $request, InternetService $service)
    {
        $request->validate(['type' => 'required|in:AUTH-LOG,DISCONNECT']);

        try {
            $vocus  = app(VocusWsmClient::class);
            $planId = $service->plan_id ?? 'STANDARD';

            $response = match ($request->type) {
                'AUTH-LOG'   => $vocus->getAuthLog($service->vocus_service_id, $planId),
                'DISCONNECT' => $vocus->disconnectSession($service->vocus_service_id, $planId),
            };

            $result = $request->type === 'AUTH-LOG'
                ? ['records' => $response['records'] ?? []]
                : ['transaction_id' => $response['transaction_id'] ?? null];

            InternetServiceDiagnostic::create([
                'internet_service_id' => $service->id,
                'diagnostic_type'     => $request->type,
                'transaction_id'      => $response['transaction_id'] ?? null,
                'transaction_state'   => $response['params']['TransactionState'] ?? 'SUCCESS',
                'result'              => $result,
                'run_by_user_id'      => Auth::id(),
                'created_at'          => now(),
            ]);

            AuditLogger::logSystem('internet.diagnostic', "Diagnostic {$request->type} run on {$service->vocus_service_id}.", [
                'service'  => 'vocus',
                'function' => 'internet-diagnostic',
            ], []);

            return back()->with('status', "Diagnostic '{$request->type}' completed.");
        } catch (\Throwable $e) {
            return back()->with('status', 'Diagnostic failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function parseSimpleAddress(string $address): array
    {
        // Best-effort parse of a free-text Australian address into DIR params.
        // e.g. "2/99 Example St, Upper Melbourne VIC 4009"
        $params = [];

        // Extract postcode (4 digits at end)
        if (preg_match('/\b(\d{4})\b\s*$/', $address, $m)) {
            $params['Main.PostCode'] = $m[1];
            $address = trim(substr($address, 0, strrpos($address, $m[1])));
        }

        // Extract state
        if (preg_match('/\b(NSW|VIC|QLD|SA|WA|TAS|NT|ACT)\b/i', $address, $m)) {
            $params['Main.State'] = strtoupper($m[1]);
            $address = str_ireplace($m[1], '', $address);
        }

        // Split on comma: "street part, suburb"
        $parts = array_map('trim', explode(',', $address, 2));

        if (count($parts) === 2) {
            $params['Main.Suburb'] = strtoupper($parts[1]);
            $streetPart = $parts[0];
        } else {
            $streetPart = $parts[0];
        }

        // Unit number: "2/99" or "Unit 2, 99"
        if (preg_match('/^(\d+)\s*\/\s*(\d+)\s+(.+)$/', $streetPart, $m)) {
            $params['Main.Unit1stNumber']    = (int) $m[1];
            $params['Main.Street1stNumber']  = (int) $m[2];
            $streetPart = trim($m[3]);
        } elseif (preg_match('/^(\d+)\s+(.+)$/', $streetPart, $m)) {
            $params['Main.Street1stNumber'] = (int) $m[1];
            $streetPart = trim($m[2]);
        }

        // Street type abbreviations at end
        $streetTypes = ['ST', 'AVE', 'AV', 'RD', 'DR', 'CL', 'CT', 'PL', 'TCE', 'WAY', 'HWY', 'BLVD', 'LN', 'GR', 'CCT', 'CRES', 'PROM'];
        foreach ($streetTypes as $type) {
            if (preg_match('/\b' . preg_quote($type, '/') . '\b\s*$/i', $streetPart, $m)) {
                $params['Main.StreetType'] = strtoupper($type);
                $streetPart = trim(preg_replace('/\b' . preg_quote($type, '/') . '\b\s*$/i', '', $streetPart));
                break;
            }
        }

        $params['Main.StreetName'] = strtoupper(trim($streetPart));

        return array_filter($params, fn ($v) => $v !== '' && $v !== null);
    }
}
