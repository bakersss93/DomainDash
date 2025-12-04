<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Models\Client;
use App\Models\Domain;
use App\Models\SslCertificate;
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
            $products = $this->synergy->listSSLProducts();
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
        ]);

        DB::beginTransaction();

        try {
            $client = Client::findOrFail($request->client_id);

            // Purchase SSL with Synergy
            $result = $this->synergy->purchaseSSL(
                $request->product_id,
                $request->domain,
                $request->years
            );

            if (isset($result['status']) && in_array($result['status'], ['OK', 'pending'])) {
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
                    'common_name' => $request->domain,
                    'product_name' => $request->product_id,
                    'start_date' => now(),
                    'expire_date' => now()->addYears($request->years),
                    'status' => 'pending',
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "SSL certificate for {$request->domain} purchased successfully!",
                    'ssl_id' => $ssl->id,
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'SSL purchase failed: ' . ($result['errorMessage'] ?? 'Unknown error'),
                ], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error purchasing SSL: ' . $e->getMessage(),
            ], 500);
        }
    }
}
