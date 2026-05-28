<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HostingService;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = HostingService::query();

        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->client_id);
        }

        if ($request->filled('status')) {
            $q->where('service_status', $request->status);
        }

        return response()->json(
            $q->orderBy('domain_name')->paginate((int) $request->get('per_page', 100))
        );
    }

    public function show(HostingService $service): JsonResponse
    {
        return response()->json($service->load('client', 'domain'));
    }

    public function sync(SynergyWholesaleClient $synergy): JsonResponse
    {
        $page = 1;
        $limit = 100;
        $imported = 0;

        do {
            $res = $synergy->listHosting(null, $page, $limit);

            if (($res['status'] ?? null) !== 'OK') {
                return response()->json([
                    'message' => 'Synergy error: ' . ($res['errorMessage'] ?? 'Unknown error'),
                ], 502);
            }

            foreach ($res['serviceList'] ?? [] as $entry) {
                $entry = (array) $entry;
                $hoid  = $entry['hoid'] ?? null;
                if (!$hoid) {
                    continue;
                }

                HostingService::updateOrCreate(['hoid' => $hoid], [
                    'plan'               => $entry['packageName']     ?? null,
                    'username'           => $entry['username']        ?? null,
                    'server'             => $entry['server']          ?? null,
                    'domain_name'        => $entry['domain']          ?? null,
                    'ip_address'         => $entry['ipAddress']       ?? null,
                    'service_status'     => strtoupper($entry['serviceStatus'] ?? 'ACTIVE'),
                    'disk_limit_mb'      => isset($entry['diskLimit'])     ? (int) $entry['diskLimit']     : null,
                    'disk_usage_mb'      => isset($entry['diskUsage'])     ? (int) $entry['diskUsage']     : null,
                    'bandwidth_limit_mb' => isset($entry['bandwidthLimit']) ? (int) $entry['bandwidthLimit'] : null,
                    'bandwidth_used_mb'  => isset($entry['bandwidthUsed'])  ? (int) $entry['bandwidthUsed']  : null,
                    'next_renewal_due'   => $entry['nextRenewalDue'] ?? null,
                ]);

                $imported++;
            }

            $received = count($res['serviceList'] ?? []);
            $page++;
        } while ($received >= $limit && $page < 200);

        AuditLogger::logSystem('sync.completed', "API hosting sync completed ({$imported} records).", [
            'service' => 'api',
            'function' => 'hosting.sync',
        ], ['new_values' => ['imported' => $imported]]);

        return response()->json([
            'message' => "Sync complete. Processed {$imported} hosting services.",
            'count'   => $imported,
        ], 202);
    }
}
