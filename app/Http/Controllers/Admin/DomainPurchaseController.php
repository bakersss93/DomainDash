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

class DomainPurchaseController extends Controller
{
    protected SynergyWholesaleClient $synergy;

    public function __construct(SynergyWholesaleClient $synergy)
    {
        $this->synergy = $synergy;
    }

    /**
     * Show the domain purchase page.
     */
    public function index()
    {
        return view('admin.domains.purchase');
    }

    /**
     * Search for domain availability.
     */
    public function search(Request $request)
    {
        $request->validate([
            'domain_name' => 'required|string',
            'extension' => 'required|string',
        ]);

        $domainName = $request->domain_name;
        $extension = $request->extension;
        $fullDomain = $domainName . '.' . $extension;

        try {
            $result = $this->synergy->checkDomain($fullDomain);

            return response()->json([
                'success' => true,
                'available' => $result['status'] === 'available',
                'domain' => $fullDomain,
                'message' => $result['status'] === 'available'
                    ? "Available! The domain {$fullDomain} is available for registration."
                    : "Sorry, the domain {$fullDomain} is not available.",
                'requiresAuValidation' => str_ends_with($extension, '.au'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking domain availability: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate .au domain with ABN/ACN.
     */
    public function validateAu(Request $request)
    {
        $request->validate([
            'id_type' => 'required|in:ABN,ACN,RBN',
            'id_number' => 'required|string',
        ]);

        try {
            $result = $this->synergy->auRegistrantInfo(
                $request->id_type,
                $request->id_number
            );

            if (isset($result['status']) && $result['status'] === 'OK') {
                return response()->json([
                    'success' => true,
                    'registrant' => [
                        'name' => $result['registrantName'] ?? '',
                        'id_type' => $request->id_type,
                        'id_number' => $request->id_number,
                        'eligibility_type' => $result['eligibilityType'] ?? 'Company',
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid ' . $request->id_type . '. Please check the number and try again.',
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error validating registration number: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete domain purchase.
     */
    public function complete(Request $request)
    {
        $request->validate([
            'domain' => 'required|string',
            'years' => 'required|integer|min:1|max:10',
            'client_type' => 'required|in:existing,new',
            'client_id' => 'required_if:client_type,existing|exists:clients,id',
            'domain_id' => 'required_if:client_type,existing|exists:domains,id',

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

            // .au specific fields
            'au_id_type' => 'nullable|in:ABN,ACN,RBN',
            'au_id_number' => 'nullable|string',
            'au_registrant_name' => 'nullable|string',
            'au_eligibility_type' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            // Get or create client
            if ($request->client_type === 'existing') {
                $client = Client::findOrFail($request->client_id);
                $existingDomain = Domain::findOrFail($request->domain_id);

                // We'll use default contact info from an existing domain for Synergy API
                $contacts = [
                    'registrantName' => $client->business_name,
                    'registrantEmail' => 'admin@' . $existingDomain->name,
                    'registrantPhone' => '+61.400000000',
                    'registrantAddress' => '123 Main St',
                    'registrantCity' => 'Melbourne',
                    'registrantState' => 'VIC',
                    'registrantPostcode' => '3000',
                    'registrantCountry' => 'AU',
                ];
            } else {
                // Create new client with only the fields that exist in the table
                $client = Client::create([
                    'business_name' => $request->business_name,
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

            // Add .au specific fields if provided
            $extra = [];
            if ($request->au_id_type && $request->au_id_number) {
                $extra = [
                    'eligibilityType' => $request->au_eligibility_type ?? 'Company',
                    'eligibilityName' => $request->au_registrant_name,
                    'eligibilityID' => $request->au_id_number,
                    'eligibilityIDType' => $request->au_id_type,
                ];
            }

            // Register domain with Synergy
            $result = $this->synergy->registerDomain(
                $request->domain,
                $request->years,
                $contacts,
                ['ns1.synergywholesale.com', 'ns2.synergywholesale.com'], // Default nameservers
                $extra
            );

            if (isset($result['status']) && in_array($result['status'], ['OK', 'pending'])) {
                // Create domain in database
                $domain = Domain::create([
                    'client_id' => $client->id,
                    'name' => $request->domain,
                    'status' => 'active',
                    'expiry_date' => now()->addYears($request->years),
                    'auto_renew' => false,
                    'name_servers' => json_encode(['ns1.synergywholesale.com', 'ns2.synergywholesale.com']),
                    'dns_config' => 'custom_ns',
                    'registry_id' => $result['domainID'] ?? null,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Domain {$request->domain} registered successfully!",
                    'domain_id' => $domain->id,
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Domain registration failed: ' . ($result['errorMessage'] ?? 'Unknown error'),
                ], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error registering domain: ' . $e->getMessage(),
            ], 500);
        }
    }
}
