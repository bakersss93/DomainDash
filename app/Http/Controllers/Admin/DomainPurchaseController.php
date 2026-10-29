<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Models\Client;
use App\Models\Domain;
use App\Models\DomainPricing;
use App\Models\User;
use App\Services\AuditLogger;
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
        $commonExtensions = DomainPricing::query()
            ->where('is_common', true)
            ->orderBy('tld')
            ->pluck('tld');

        $otherExtensions = DomainPricing::query()
            ->where(function ($query) {
                $query->where('is_common', false)->orWhereNull('is_common');
            })
            ->orderBy('tld')
            ->pluck('tld');

        return view('admin.domains.purchase', compact('commonExtensions', 'otherExtensions'));
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
            $isAvailable = (bool) ($result['available'] ?? false);

            return response()->json([
                'success' => true,
                'available' => $isAvailable,
                'domain' => $fullDomain,
                'message' => $isAvailable
                    ? "Available! The domain {$fullDomain} is available for registration."
                    : ($result['errorMessage'] ?? "Sorry, the domain {$fullDomain} is not available."),
                'requiresAuValidation' => str_ends_with('.' . ltrim($extension, '.'), '.au'),
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
            $result = $this->synergy->generateAuEligibility(
                $request->id_number,
                $request->id_type
            );

            $status = strtolower((string) ($result['status'] ?? ''));
            $eligibility = (array) ($result['eligibility'] ?? []);
            $registrantName = $eligibility['registrantName'] ?? $eligibility['eligibilityName'] ?? null;
            $eligibilityType = $eligibility['eligibilityType'] ?? null;
            $eligibilityId = $eligibility['eligibilityID'] ?? $request->id_number;
            $eligibilityIdType = $eligibility['eligibilityIDType'] ?? $eligibility['registrantIDType'] ?? $request->id_type;

            if (in_array($status, ['ok', 'success'], true) && !empty($eligibility)) {
                return response()->json([
                    'success' => true,
                    'registrant' => [
                        'name' => $registrantName ?? '',
                        'id_type' => $eligibilityIdType,
                        'id_number' => $eligibilityId,
                        'eligibility_type' => $eligibilityType ?? 'Company',
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['errorMessage'] ?? ('Invalid ' . $request->id_type . '. Please check the number and try again.'),
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

                // Use stored contact info from the client
                $contacts = [
                    'organisation' => $client->business_name,
                    'contactName' => $client->primary_contact_name ?: $client->business_name,
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'address' => $client->address,
                    'suburb' => $client->city,
                    'state' => $client->state,
                    'postcode' => $client->postcode,
                    'country' => $client->country ?: 'AU',
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
                    'organisation' => $request->business_name,
                    'firstname' => $request->first_name,
                    'lastname' => $request->last_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'address' => $request->address,
                    'suburb' => $request->city,
                    'state' => $request->state,
                    'postcode' => $request->postcode,
                    'country' => $request->country,
                ];
            }

            // Use Synergy's default nameservers (dnsConfig 5, docs p.147).
            $extra = ['dnsConfig' => 5];

            // .au specific fields; the client folds these into the JSON
            // `eligibility` string domainRegister expects.
            if ($request->au_id_type && $request->au_id_number) {
                $extra += [
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
                [], // no custom nameservers; dnsConfig 5 in $extra selects the defaults
                $extra
            );

            if (isset($result['status']) && strtoupper((string) $result['status']) === 'OK') {
                // Create domain in database
                $domain = Domain::create([
                    'client_id' => $client->id,
                    'name' => $request->domain,
                    'status' => 'active',
                    'expiry_date' => now()->addYears($request->years),
                    'auto_renew' => false,
                    'name_servers' => SynergyWholesaleClient::DEFAULT_NAMESERVERS,
                    'dns_config' => 5,
                ]);

                DB::commit();

                AuditLogger::logAction('domain.purchase', $domain, "Domain {$request->domain} purchased.", [
                    'context' => [
                        'service' => 'domains',
                        'function' => 'purchase',
                        'client_id' => $client->id,
                    ],
                    'new_values' => [
                        'domain' => $request->domain,
                        'years' => (int) $request->years,
                        'client_id' => $client->id,
                    ],
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Domain {$request->domain} registered successfully!",
                    'domain_id' => $domain->id,
                ]);
            } else {
                DB::rollBack();
                AuditLogger::logSystem('purchase.failed', "Domain purchase failed for {$request->domain}.", [
                    'service' => 'domains',
                    'function' => 'purchase',
                    'client_id' => $request->client_id,
                ], [
                    'new_values' => ['error' => $result['errorMessage'] ?? 'Unknown error'],
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Domain registration failed: ' . ($result['errorMessage'] ?? 'Unknown error'),
                ], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            AuditLogger::logSystem('purchase.failed', "Domain purchase exception for {$request->domain}.", [
                'service' => 'domains',
                'function' => 'purchase',
                'client_id' => $request->client_id,
            ], [
                'new_values' => ['error' => $e->getMessage()],
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error registering domain: ' . $e->getMessage(),
            ], 500);
        }
    }
}
