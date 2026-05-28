<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternetService;
use App\Services\AuditLogger;
use App\Services\Vocus\VocusWsmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = InternetService::query();

        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->client_id);
        }

        if ($request->filled('status')) {
            $q->where('service_status', $request->status);
        }

        if ($request->filled('service_type')) {
            $q->where('service_type', $request->service_type);
        }

        return response()->json(
            $q->orderBy('customer_name')->paginate((int) $request->get('per_page', 100))
        );
    }

    public function show(InternetService $service): JsonResponse
    {
        return response()->json($service->load('client'));
    }

    public function sync(): JsonResponse
    {
        $vocus    = app(VocusWsmClient::class);
        $imported = 0;

        $existing = InternetService::whereNotNull('vocus_service_id')->get();

        foreach ($existing as $service) {
            try {
                $response = $vocus->getService($service->vocus_service_id);
                $p        = $response['params'] ?? [];

                if (empty($p)) {
                    continue;
                }

                $service->service_status       = $p['ServiceStatus']     ?? $service->service_status;
                $service->plan_id              = $p['PlanID']            ?? $service->plan_id;
                $service->service_type         = $p['ServiceType']       ?? $service->service_type;
                $service->service_scope        = $p['ServiceScope']      ?? $service->service_scope;
                $service->customer_name        = $p['CustomerName']      ?? $service->customer_name;
                $service->phone                = $p['Phone']             ?? $service->phone;
                $service->nbn_instance_id      = $p['NBNInstanceID']     ?? $service->nbn_instance_id;
                $service->avc_id               = $p['AVCID']             ?? $service->avc_id;
                $service->cvc_id               = $p['CVCID']             ?? $service->cvc_id;
                $service->realm                = $p['Username']          ?? $service->realm;
                $service->billing_provider_id  = $p['BillingProviderID'] ?? $service->billing_provider_id;
                $service->synced_at            = now();
                $service->save();
                $imported++;
            } catch (\Throwable) {
                // continue on individual failures
            }
        }

        AuditLogger::logSystem('sync.completed', "API internet service sync completed ({$imported} records).", [
            'service' => 'api',
            'function' => 'internet.sync',
        ], ['new_values' => ['synced' => $imported]]);

        return response()->json([
            'message' => "Sync complete. Refreshed {$imported} internet services.",
            'count'   => $imported,
        ], 202);
    }
}
