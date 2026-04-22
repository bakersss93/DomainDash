<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SslCertificate;
use App\Models\Client;
use App\Models\Domain;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;

class SslController extends Controller
{


    public function sync(SynergyWholesaleClient $synergy)
    {
        $response = $synergy->listAllSSLCerts();

        if (strtoupper((string) ($response['status'] ?? '')) !== 'OK') {
            $message = $response['errorMessage'] ?? 'Unknown error';
            AuditLogger::logSystem('sync.failed', 'SSL sync from Synergy failed.', [
                'service' => 'synergy',
                'function' => 'ssl-sync',
            ], [
                'new_values' => ['error' => $message],
            ]);

            return back()->with('status', 'Synergy SSL sync failed: ' . $message);
        }

        $certs = $response['certs'] ?? [];
        $synced = 0;

        foreach ($certs as $cert) {
            $entry = (array) $cert;
            $commonName = $entry['commonName'] ?? null;
            $domain = $commonName ? Domain::where('name', strtolower($commonName))->first() : null;

            SslCertificate::updateOrCreate(
                ['cert_id' => $entry['certID'] ?? null, 'common_name' => $commonName],
                [
                    'client_id' => $domain?->client_id,
                    'domain_id' => $domain?->id,
                    'product_name' => $entry['productID'] ?? null,
                    'start_date' => !empty($entry['startDate']) ? date('Y-m-d', strtotime($entry['startDate'])) : null,
                    'expire_date' => !empty($entry['expireDate']) ? date('Y-m-d', strtotime($entry['expireDate'])) : null,
                    'status' => $entry['status'] ?? null,
                ]
            );

            $synced++;
        }

        AuditLogger::logSystem('sync.completed', 'SSL sync from Synergy completed.', [
            'service' => 'synergy',
            'function' => 'ssl-sync',
        ], [
            'new_values' => ['synced_count' => $synced],
        ]);

        return back()->with('status', "SSL certificates synced from Synergy ({$synced} records processed).");
    }

    public function index(Request $request)
    {
        $q = SslCertificate::query()->with('domain','client');
        if ($client = $request->get('client_id')) $q->where('client_id',$client);
        $ssls = $q->paginate(25);
        $clients = Client::orderBy('business_name')->get();
        return view('admin.ssls.index', compact('ssls','clients'));
    }
}
