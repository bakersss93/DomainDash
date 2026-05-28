<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SslCertificate;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = SslCertificate::query();

        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->client_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return response()->json(
            $q->orderBy('common_name')->paginate((int) $request->get('per_page', 100))
        );
    }

    public function show(SslCertificate $ssl): JsonResponse
    {
        return response()->json($ssl->load('client', 'domain'));
    }

    public function sync(SynergyWholesaleClient $synergy): JsonResponse
    {
        $response = $synergy->listAllSSLCerts();

        if (($response['status'] ?? null) !== 'OK') {
            return response()->json([
                'message' => 'Synergy error: ' . ($response['errorMessage'] ?? 'Unknown error'),
            ], 502);
        }

        $synced = 0;

        foreach ($response['certList'] ?? [] as $entry) {
            $entry  = (array) $entry;
            $certId = $entry['certId'] ?? $entry['id'] ?? null;
            if (!$certId) {
                continue;
            }

            SslCertificate::updateOrCreate(['cert_id' => (string) $certId], [
                'common_name'  => $entry['commonName']   ?? $entry['cn']          ?? null,
                'product_name' => $entry['productName']  ?? $entry['product']     ?? null,
                'start_date'   => $entry['startDate']    ?? $entry['issueDate']   ?? null,
                'expire_date'  => $entry['expireDate']   ?? $entry['expiryDate']  ?? null,
                'status'       => $entry['status']       ?? null,
            ]);

            $synced++;
        }

        AuditLogger::logSystem('sync.completed', "API SSL sync completed ({$synced} records).", [
            'service' => 'api',
            'function' => 'ssl.sync',
        ], ['new_values' => ['synced' => $synced]]);

        return response()->json([
            'message' => "Sync complete. Processed {$synced} SSL certificates.",
            'count'   => $synced,
        ], 202);
    }
}
