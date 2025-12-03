<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HostingPurchaseController extends Controller
{
    protected SynergyWholesaleClient $synergy;

    public function __construct(SynergyWholesaleClient $synergy)
    {
        $this->synergy = $synergy;
    }

    /**
     * Show the hosting purchase page.
     */
    public function index()
    {
        try {
            $plans = $this->synergy->listHostingPlans();
            $clients = Client::orderBy('company_name')->get();

            return view('admin.services.hosting-purchase', compact('plans', 'clients'));
        } catch (\Exception $e) {
            return view('admin.services.hosting-purchase', [
                'plans' => [],
                'clients' => Client::orderBy('company_name')->get(),
                'error' => 'Unable to load hosting plans: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Purchase hosting service.
     */
    public function purchase(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|string',
            'domain' => 'required|string',
            'client_id' => 'required|exists:clients,id',
        ]);

        DB::beginTransaction();

        try {
            $client = Client::findOrFail($request->client_id);

            // Purchase hosting with Synergy
            $result = $this->synergy->purchaseHosting(
                $request->plan_id,
                $request->domain,
                'support@jargonconsulting.com.au'
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

                // Create hosting service in database
                $hosting = HostingService::create([
                    'client_id' => $client->id,
                    'domain_id' => $domain->id,
                    'hoid' => $result['hoid'] ?? null,
                    'plan' => $request->plan_id,
                    'username' => $result['username'] ?? null,
                    'server' => $result['server'] ?? null,
                    'domain_name' => $request->domain,
                    'service_status' => 'active',
                    'next_renewal_due' => $result['renewalDate'] ?? now()->addYear(),
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Hosting service for {$request->domain} purchased successfully!",
                    'service_id' => $hosting->id,
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Hosting purchase failed: ' . ($result['errorMessage'] ?? 'Unknown error'),
                ], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error purchasing hosting: ' . $e->getMessage(),
            ], 500);
        }
    }
}
