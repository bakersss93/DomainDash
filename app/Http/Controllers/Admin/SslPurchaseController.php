<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Models\Client;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SslPurchaseController extends Controller
{
    protected SynergyWholesaleClient $synergy;

    public function __construct(SynergyWholesaleClient $synergy)
    {
        $this->synergy = $synergy;
    }

    /**
     * Show the SSL purchase page.
     */
    public function index()
    {
        try {
            $productsResponse = $this->synergy->listSSLProducts();
            $products = $productsResponse['pricing'] ?? [];
            $clients = Client::orderBy('business_name')->get();

            return view('admin.services.ssl-purchase', compact('products', 'clients'));
        } catch (\Exception $e) {
            return view('admin.services.ssl-purchase', [
                'products' => [],
                'clients' => Client::orderBy('business_name')->get(),
                'error' => 'Unable to load SSL products: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Purchase SSL certificate.
     */
    public function purchase(Request $request)
    {
        $request->validate([
            'product_id' => 'required|string',
            'domain' => 'required|string',
            'years' => 'required|integer|min:1|max:5',
            'client_id' => 'required|exists:clients,id',
            'csr' => 'required|string',
            'private_key' => 'required|string',
        ]);

        DB::beginTransaction();

        try {
            $client = Client::findOrFail($request->client_id);

            $contactName = trim((string) ($client->primary_contact_name ?: $client->business_name));
            [$firstName, $lastName] = array_pad(preg_split('/\s+/', $contactName, 2) ?: [], 2, '');

            $result = $this->synergy->purchaseSSL(
                $request->product_id,
                $request->domain,
                $request->years,
                [
                    'csr' => $request->csr,
                    'privateKey' => $request->private_key,
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'emailAddress' => $client->email ?: 'support@example.com',
                    'address' => $client->address ?: 'Unknown',
                    'city' => $client->city ?: 'Unknown',
                    'state' => $client->state ?: 'Unknown',
                    'postCode' => $client->postcode ?: '0000',
                    'country' => $client->country ?: 'AU',
                    'phone' => $client->phone ?: '0000000000',
                    'fax' => $client->phone ?: '0000000000',
                ]
            );

            if (isset($result['status']) && in_array(strtolower((string) $result['status']), ['ok', 'pending'], true)) {
                // Find or create domain record
                $domain = Domain::where('name', $request->domain)->first();
                if (!$domain) {
                    $domain = Domain::create([
                        'client_id' => $client->id,
                        'name' => $request->domain,
                        'status' => 'active',
                        'auto_renew' => false,
                    ]);
                }

                // Create SSL certificate in database
                $ssl = SslCertificate::create([
                    'client_id' => $client->id,
                    'domain_id' => $domain->id,
                    'cert_id' => $result['certID'] ?? null,
                    'common_name' => $result['commonName'] ?? $request->domain,
                    'product_name' => $request->product_id,
                    'start_date' => now(),
                    'expire_date' => now()->addYears($request->years),
                    'status' => $result['certStatus'] ?? 'pending',
                ]);

                DB::commit();

                AuditLogger::logAction('ssl.purchase', $ssl, "SSL purchased for {$request->domain}.", [
                    'context' => [
                        'service' => 'ssl',
                        'function' => 'purchase',
                        'client_id' => $client->id,
                    ],
                    'new_values' => [
                        'domain' => $request->domain,
                        'years' => (int) $request->years,
                        'product_id' => $request->product_id,
                    ],
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "SSL certificate for {$request->domain} purchased successfully!",
                    'ssl_id' => $ssl->id,
                ]);
            } else {
                DB::rollBack();
                AuditLogger::logSystem('purchase.failed', "SSL purchase failed for {$request->domain}.", [
                    'service' => 'ssl',
                    'function' => 'purchase',
                    'client_id' => $request->client_id,
                ], [
                    'new_values' => ['error' => $result['errorMessage'] ?? 'Unknown error'],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'SSL purchase failed: ' . ($result['errorMessage'] ?? 'Unknown error'),
                ], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            AuditLogger::logSystem('purchase.failed', "SSL purchase exception for {$request->domain}.", [
                'service' => 'ssl',
                'function' => 'purchase',
                'client_id' => $request->client_id,
            ], [
                'new_values' => ['error' => $e->getMessage()],
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error purchasing SSL: ' . $e->getMessage(),
            ], 500);
        }
    }
}
