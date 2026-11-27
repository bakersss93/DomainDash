<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternetService;
use App\Services\Sync\SyncDispatcher;
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
            $q->orderBy('customer_name')->paginate(min(100, max(1, (int) $request->get('per_page', 100))))
        );
    }

    public function show(InternetService $service): JsonResponse
    {
        return response()->json($service->load('client'));
    }

    /**
     * Queue a refresh of every known service from Vocus (background job, shared
     * rate limiter). Poll GET /admin/sync/runs/{run} equivalents are not exposed
     * to API keys; the run id is returned for reference.
     */
    public function sync(SyncDispatcher $dispatcher): JsonResponse
    {
        if (($problem = VocusWsmClient::configurationProblem()) !== null) {
            return response()->json(['message' => $problem], 422);
        }

        $run = $dispatcher->refreshVocus(null, false, null);

        return response()->json([
            'message' => 'Sync queued. Services are refreshed from Vocus in the background.',
            'run_id'  => $run->id,
            'status'  => $run->status,
        ], 202);
    }
}
