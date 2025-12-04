<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\HostingService;
use App\Models\HostingPackage;
use App\Models\Client;
use App\Services\Synergy\SynergyWholesaleClient;

class ServicesController extends Controller
{
    /**
     * List hosting services with optional client and status filters.
     */
    public function index(Request $request)
    {
        $q = HostingService::query()->with('domain', 'client');

        $clientId = $request->get('client_id');
        if ($clientId) {
            $q->where('client_id', $clientId);
        }

        // Default to ACTIVE status if no filter specified (first page load)
        // If user explicitly selects "All statuses", status param will be empty string
        $statusFilter = $request->has('status') ? $request->get('status') : 'ACTIVE';
        if ($statusFilter && Schema::hasColumn('hosting_services', 'service_status')) {
            $q->where('service_status', $statusFilter);
        }

        // domain_name now exists; if it doesn't for some reason, drop the orderBy
        if (Schema::hasColumn('hosting_services', 'domain_name')) {
            $q->orderBy('domain_name');
        }

        $services = $q->paginate(25);
        $clients  = Client::orderBy('business_name')->get();

        return view('admin.services.index', [
            'services'     => $services,
            'clients'      => $clients,
            'clientId'     => $clientId,
            'statusFilter' => $statusFilter,
        ]);
    }

    /**
     * Show detailed overview of a hosting service with live stats from Synergy.
     */
    public function show(HostingService $service, SynergyWholesaleClient $synergy)
    {
        $serviceData = null;
        $error = null;

        // Get the hosting package for bandwidth limits
        $package = null;
        if ($service->plan) {
            $package = HostingPackage::where('package_name', $service->plan)->first();
        }

        try {
            // Use HOID as primary identifier (per Synergy API docs page 107)
            // HOID can be used as the identifier field
            $identifier = $service->hoid ?: ($service->domain_name ?: $service->username);

            \Log::info('Fetching service overview', [
                'service_id' => $service->id,
                'identifier' => $identifier,
                'identifier_type' => $service->hoid ? 'hoid' : ($service->domain_name ? 'domain' : 'username'),
                'domain_name' => $service->domain_name,
                'username' => $service->username,
                'hoid' => $service->hoid
            ]);

            // When using HOID as identifier, don't pass hoid parameter separately
            $serviceData = $synergy->hostingGetService($identifier, null);

            \Log::info('Service overview data retrieved', [
                'service_id' => $service->id,
                'status' => $serviceData['status'] ?? 'no status',
                'errorMessage' => $serviceData['errorMessage'] ?? 'no error message',
                'data_keys' => array_keys($serviceData),
                'has_diskUsage' => isset($serviceData['diskUsage']),
                'has_bandwidth' => isset($serviceData['bandwidth']),
                'full_response' => json_encode($serviceData)
            ]);

            // Check if the API call was successful (status can be 'OK', 'Active', etc.)
            // Consider it an error only if there's no actual service data
            $hasData = isset($serviceData['domain']) || isset($serviceData['username']);

            if (!$hasData) {
                $error = $serviceData['errorMessage'] ?? 'Failed to retrieve service data from Synergy.';
                \Log::warning('Synergy API returned no service data', [
                    'service_id' => $service->id,
                    'status' => $serviceData['status'] ?? 'no status',
                    'error' => $error,
                    'identifier_used' => $identifier,
                    'hoid_used' => $service->hoid
                ]);
            }

        } catch (\Throwable $e) {
            \Log::error('Failed to retrieve service overview', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $error = $e->getMessage();
        }

        return view('admin.services.show', [
            'service' => $service,
            'serviceData' => $serviceData,
            'error' => $error,
            'package' => $package
        ]);
    }

    /**
     * Sync hosting services from Synergy listHosting().
     */
    public function sync(Request $request, SynergyWholesaleClient $synergy)
    {
        // First, sync hosting packages
        $this->syncPackages($synergy);

        $page          = 1;
        $limit         = 100;
        $totalImported = 0;

        do {
            $res = $synergy->listHosting(null, $page, $limit);

            if (($res['status'] ?? null) !== 'OK') {
                $message = $res['errorMessage'] ?? 'Unknown error';
                return redirect()
                    ->route('admin.services.hosting')
                    ->with('status', 'Synergy listHosting failed: ' . $message);
            }

            $items = $res['items'] ?? [];
            if (!is_array($items) || empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $hoid = $item['hoid'] ?? null;
                if (! $hoid) {
                    continue;
                }

                $model = HostingService::firstOrNew(['hoid' => $hoid]);

                // Core fields from Synergy
                $domain   = $item['domain']          ?? $item['domainName'] ?? null;
                $username = $item['username']        ?? null;
                $plan     = $item['plan']            ?? null;
                $server   = $item['server']          ?? null;
                $status   = $item['serviceStatus']   ?? null;
                $ip       = $item['dedicatedIPv4']   ?? $item['serverIPAddress'] ?? null;

                // Raw disk values (often look like "2048M")
                $diskUsedRaw = $item['diskUsage'] ?? null;
                $diskMaxRaw  = $item['diskLimit'] ?? null;

                // Normalise to integer MB for *_mb columns
                $diskUsed = $this->parseDiskMb($diskUsedRaw);
                $diskMax  = $this->parseDiskMb($diskMaxRaw);

                if ($domain !== null && Schema::hasColumn('hosting_services', 'domain_name')) {
                    $model->domain_name = strtolower($domain);
                }

                if ($username !== null && Schema::hasColumn('hosting_services', 'username')) {
                    $model->username = $username;
                }

                if ($plan !== null && Schema::hasColumn('hosting_services', 'plan')) {
                    $model->plan = $plan;
                }

                if ($server !== null && Schema::hasColumn('hosting_services', 'server')) {
                    $model->server = $server;
                }

                if ($status !== null && Schema::hasColumn('hosting_services', 'service_status')) {
                    $model->service_status = $status;
                }

                if ($ip !== null) {
                    if (Schema::hasColumn('hosting_services', 'ip')) {
                        $model->ip = $ip;
                    }
                    if (Schema::hasColumn('hosting_services', 'ip_address')) {
                        $model->ip_address = $ip;
                    }

                }

                if ($diskUsed !== null && Schema::hasColumn('hosting_services', 'disk_usage_mb')) {
                    $model->disk_usage_mb = $diskUsed;
                }

                if ($diskMax !== null && Schema::hasColumn('hosting_services', 'disk_limit_mb')) {
                    $model->disk_limit_mb = $diskMax;
                }

                $model->save();
                $totalImported++;
            }

            $page++;
        } while (!empty($res['items']) && count($res['items']) === $limit);

        return redirect()
            ->route('admin.services.hosting')
            ->with('status', "Hosting services synced from Synergy ({$totalImported} records processed).");
    }

    /**
     * AJAX: details for the slide-down overview panel.
     */
    public function details(HostingService $service)
    {
        $data = [
            'id'         => $service->id,
            'domain'     => $service->domain_name,
            'plan'       => $service->plan,
            'username'   => $service->username,
            'server'     => $service->server,
            'status'     => $service->service_status,
            'ip'         => $service->ip ?? $service->ip_address,
            'disk_usage' => $service->disk_usage_mb,
            'disk_limit' => $service->disk_limit_mb,
            'client'     => optional($service->client)->business_name
                           ?? optional($service->client)->name,
        ];

        return response()->json($data);
    }

    /**
     * AJAX: fetch the password from Synergy for the password modal.
     */
    public function password(HostingService $service, SynergyWholesaleClient $synergy)
    {
        try {
            // Use HOID as primary identifier (per Synergy API docs page 107)
            // HOID can be used as the identifier field
            $identifier = $service->hoid ?: ($service->domain_name ?: $service->username);

            \Log::info('Fetching password for service', [
                'service_id' => $service->id,
                'identifier' => $identifier,
                'identifier_type' => $service->hoid ? 'hoid' : ($service->domain_name ? 'domain' : 'username'),
                'domain_name' => $service->domain_name,
                'username' => $service->username,
                'hoid' => $service->hoid
            ]);

            // Call Synergy's hostingGetService to get password
            // When using HOID as identifier, don't pass hoid parameter separately
            $result = $synergy->hostingGetService($identifier, null);

            \Log::info('Password fetch result', [
                'service_id' => $service->id,
                'status' => $result['status'] ?? 'no status',
                'errorMessage' => $result['errorMessage'] ?? 'no error message',
                'has_password' => isset($result['password']),
                'result_keys' => array_keys($result),
                'full_response' => json_encode($result)
            ]);

            // Check if password exists (status can be 'OK', 'Active', etc.)
            $password = $result['password'] ?? null;

            if ($password) {
                return response()->json([
                    'ok'       => true,
                    'password' => $password,
                ]);
            }

            return response()->json([
                'ok'      => false,
                'message' => $result['errorMessage'] ?? 'Password not available from Synergy.',
            ], 400);

        } catch (\Throwable $e) {
            \Log::error('Password fetch error', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Error fetching password: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login to cPanel – get URL from Synergy and redirect.
     */
    public function login(HostingService $service, SynergyWholesaleClient $synergy)
    {
        try {
            // Use HOID as primary identifier (per Synergy API docs page 107)
            $identifier = $service->hoid ?: ($service->domain_name ?: $service->username);

            \Log::info('Attempting cPanel login', [
                'service_id' => $service->id,
                'identifier' => $identifier,
                'identifier_type' => $service->hoid ? 'hoid' : ($service->domain_name ? 'domain' : 'username')
            ]);

            // Call Synergy's hostingGetLogin to get SSO URL
            // When using HOID as identifier, don't pass hoid parameter separately
            $result = $synergy->hostingGetLogin($identifier, null);

            \Log::info('cPanel login response received', [
                'service_id' => $service->id,
                'status' => $result['status'] ?? 'no status',
                'has_url' => isset($result['url']),
                'response_keys' => array_keys($result),
                'full_response' => json_encode($result)
            ]);

            // Extract the URL from the response
            $url = $result['url'] ?? $result['loginUrl'] ?? null;

            if (!empty($url)) {
                return redirect()->away($url);
            }

            $errorMsg = $result['errorMessage'] ?? 'Unable to get cPanel login URL from Synergy.';
            return back()->with('error', $errorMsg);

        } catch (\Throwable $e) {
            \Log::error('cPanel login error', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Error getting cPanel login: ' . $e->getMessage());
        }
    }

    /**
     * Assign a client to the hosting service.
     */
    public function assignClient(Request $request, HostingService $service)
    {
        $data = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
        ]);

        if (Schema::hasColumn('hosting_services', 'client_id')) {
            $service->client_id = $data['client_id'] ?? null;
            $service->save();
        }

        return back()->with('status', 'Client assignment updated.');
    }

    /**
     * Change primary domain (local only – Synergy call TODO if needed).
     */
    public function changePrimaryDomain(Request $request, HostingService $service)
    {
        $data = $request->validate([
            'domain_name' => 'required|string|max:255',
        ]);

        if (Schema::hasColumn('hosting_services', 'domain_name')) {
            $service->domain_name = strtolower($data['domain_name']);
            $service->save();
        }

        return back()->with('status', 'Primary domain updated locally.');
    }

    /**
     * Suspend/unsuspend locally (Synergy integration left as a TODO).
     */
    public function suspend(Request $request, HostingService $service)
    {
        if (Schema::hasColumn('hosting_services', 'is_suspended')) {
            $service->is_suspended = ! (bool) $service->is_suspended;
            $service->save();
        }

        // TODO: call Synergy suspend/unsuspend API if you want that behaviour.

        return back()->with('status', 'Service suspension state toggled.');
    }

    /**
     * Convert disk size strings from Synergy to integer megabytes.
     *
     * Examples:
     *  "1041M" -> 1041
     *  "2048"  -> 2048
     *  "1.5G"  -> 1536
     */
    private function parseDiskMb($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Already numeric – assume it's megabytes
        if (is_numeric($value)) {
            return (int) $value;
        }

        $str = strtoupper(trim((string) $value));

        // Extract leading number (e.g. "1041M" => "1041", "1.5G" => "1.5")
        if (!preg_match('/([\d\.]+)/', $str, $m)) {
            return null;
        }

        $number = (float) $m[1];

        $lastChar = substr($str, -1);

        if ($lastChar === 'G') {
            // Gigabytes -> MB
            return (int) round($number * 1024);
        }

        // Default: treat as MB (covers "M", "MB", or bare number)
        return (int) round($number);
    }

    /**
     * Sync hosting packages from Synergy.
     *
     * @param SynergyWholesaleClient $synergy
     * @return int Number of packages synced
     */
    private function syncPackages(SynergyWholesaleClient $synergy): int
    {
        try {
            $response = $synergy->hostingListPackages();

            \Log::info('Hosting packages API response', [
                'status' => $response['status'] ?? 'no status',
                'has_packages' => isset($response['packages']),
                'packages_type' => isset($response['packages']) ? gettype($response['packages']) : 'not set',
                'full_response' => json_encode($response)
            ]);

            if (($response['status'] ?? null) !== 'OK') {
                \Log::warning('Failed to sync hosting packages', [
                    'status' => $response['status'] ?? 'no status',
                    'errorMessage' => $response['errorMessage'] ?? 'no error message'
                ]);
                return 0;
            }

            $packages = $response['packages'] ?? [];
            if (!is_array($packages)) {
                \Log::warning('Packages response is not an array', ['type' => gettype($packages)]);
                return 0;
            }

            $count = 0;
            foreach ($packages as $pkg) {
                // API returns: name, product, price
                $packageName = $pkg['name'] ?? null;
                if (!$packageName) {
                    continue;
                }

                $model = HostingPackage::firstOrNew(['package_name' => $packageName]);

                // Product/category
                if (isset($pkg['product'])) {
                    $model->category = $pkg['product'];
                }

                // Price (appears to be a single price field, store as monthly)
                if (isset($pkg['price'])) {
                    $model->price_monthly = (float) $pkg['price'];
                }

                $model->save();
                $count++;

                \Log::info('Package synced', [
                    'name' => $packageName,
                    'product' => $pkg['product'] ?? null,
                    'price' => $pkg['price'] ?? null
                ]);
            }

            \Log::info('Hosting packages synced', ['count' => $count]);
            return $count;

        } catch (\Throwable $e) {
            \Log::error('Error syncing hosting packages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 0;
        }
    }
}