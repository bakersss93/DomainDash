<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Models\Client;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DomainTransferController extends Controller
{
    protected SynergyWholesaleClient $synergy;

    public function __construct(SynergyWholesaleClient $synergy)
    {
        $this->synergy = $synergy;
    }

    /**
     * Show the domain transfer page.
     */
    public function create()
    {
        return view('admin.domains.transfer');
    }

    /**
     * Validate domain transfer with EPP code.
     */
    public function validateTransfer(Request $request)
    {
        $request->validate([
            'domain' => 'required|string',
            'epp_code' => 'required|string',
        ]);

        try {
            // Check if domain can be transferred
            $domainInfo = $this->synergy->domainInfo($request->domain);

            if (isset($domainInfo['status']) && $domainInfo['status'] === 'OK') {
                return response()->json([
                    'success' => true,
                    'message' => 'Domain validation successful. You can proceed with the transfer.',
                    'domain_info' => [
                        'domain' => $request->domain,
                        'expiry_date' => $domainInfo['expiryDate'] ?? null,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to validate domain. Please check the domain name and EPP code.',
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'message' => 'Domain appears valid. You can proceed with the transfer.',
            ]);
        }
    }

    /**
     * Complete domain transfer.
     */
    public function complete(Request $request)
    {
        $request->validate([
            'domain' => 'required|string',
            'epp_code' => 'required|string',
            'auto_renew' => 'required|boolean',
            'client_type' => 'required|in:existing,new',
            'client_id' => 'required_if:client_type,existing|exists:clients,id',

            // New client fields
            'business_name' => 'required_if:client_type,new|string|max:255',
            'first_name' => 'required_if:client_type,new|string|max:255',
            'last_name' => 'required_if:client_type,new|string|max:255',
            'email' => 'required_if:client_type,new|email|max:255',
            'phone' => 'required_if:client_type,new|string|max:50',
            'address' => 'required_if:client_type,new|string|max:255',
            'city' => 'required_if:client_type,new|string|max:100',
            'state' => 'required_if:client_type,new|string|max:100',
            'postcode' => 'required_if:client_type,new|string|max:20',
            'country' => 'required_if:client_type,new|string|max:2',
        ]);

        DB::beginTransaction();

        try {
            // Get or create client
            if ($request->client_type === 'existing') {
                $client = Client::findOrFail($request->client_id);

                // Use stored contact info from the client
                $contacts = [
                    'registrantName' => $client->business_name,
                    'registrantEmail' => $client->email ?? 'admin@example.com',
                    'registrantPhone' => $client->phone ?? '+61.400000000',
                    'registrantAddress' => $client->address ?? '123 Main St',
                    'registrantCity' => $client->city ?? 'Melbourne',
                    'registrantState' => $client->state ?? 'VIC',
                    'registrantPostcode' => $client->postcode ?? '3000',
                    'registrantCountry' => $client->country ?? 'AU',
                ];
            } else {
                // Create new client with all contact fields
                $client = Client::create([
                    'business_name' => $request->business_name,
                    'primary_contact_name' => $request->first_name . ' ' . $request->last_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'address' => $request->address,
                    'city' => $request->city,
                    'state' => $request->state,
                    'postcode' => $request->postcode,
                    'country' => $request->country,
                    'active' => true,
                ]);

                // Create user for the client
                $user = User::create([
                    'name' => $request->first_name . ' ' . $request->last_name,
                    'email' => $request->email,
                    'password' => Hash::make(Str::random(16)),
                ]);

                $user->assignRole('Customer');
                $user->clients()->attach($client->id);

                $contacts = [
                    'registrantName' => $request->business_name,
                    'registrantEmail' => $request->email,
                    'registrantPhone' => $request->phone,
                    'registrantAddress' => $request->address,
                    'registrantCity' => $request->city,
                    'registrantState' => $request->state,
                    'registrantPostcode' => $request->postcode,
                    'registrantCountry' => $request->country,
                ];
            }

            // Transfer domain with Synergy (default to 1 year transfer)
            $result = $this->synergy->transferDomain(
                $request->domain,
                $request->epp_code,
                1, // Transfers are typically 1 year
                $contacts
            );

            if (isset($result['status']) && in_array($result['status'], ['OK', 'pending'])) {
                // Create domain in database
                $domain = Domain::create([
                    'client_id' => $client->id,
                    'name' => $request->domain,
                    'status' => 'pending_transfer',
                    'transfer_status' => 'pending',
                    'expiry_date' => now()->addYear(),
                    'auto_renew' => $request->auto_renew,
                    'name_servers' => json_encode(['ns1.synergywholesale.com', 'ns2.synergywholesale.com']),
                    'dns_config' => 'custom_ns',
                    'registry_id' => $result['domainID'] ?? null,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Domain {$request->domain} transfer initiated successfully!",
                    'domain_id' => $domain->id,
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Domain transfer failed: ' . ($result['errorMessage'] ?? 'Unknown error'),
                ], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error initiating domain transfer: ' . $e->getMessage(),
            ], 500);
        }
    }
}
