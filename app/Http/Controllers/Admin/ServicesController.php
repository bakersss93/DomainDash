<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\HostingService;
use App\Models\Client;
use App\Services\Synergy\SynergyWholesaleClient;

class ServicesController extends Controller
{
    /**
     * List hosting services with optional client filter.
     */
    public function index(Request $request)
    {
        $q = HostingService::query()->with('domain', 'client');

        $clientId = $request->get('client_id');
        if ($clientId) {
            $q->where('client_id', $clientId);
        }

        // domain_name now exists; if it doesn't for some reason, drop the orderBy
        if (Schema::hasColumn('hosting_services', 'domain_name')) {
            $q->orderBy('domain_name');
        }

        $services = $q->paginate(25);
        $clients  = Client::orderBy('business_name')->get();

        return view('admin.services.index', [
            'services' => $services,
            'clients'  => $clients,
            'clientId' => $clientId,
        ]);
    }

    /**
     * Sync hosting services from Synergy listHosting().
     */
    public function sync(Request $request, SynergyWholesaleClient $synergy)
    {
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
            $identifier = $service->domain_name ?: ($service->username ?: (string) $service->hoid);
            $info = $synergy->hostingGetService($identifier, $service->hoid);
            $password = $info['password'] ?? null;
        } catch (\Throwable $e) {
            $password = null;
        }

        if (! $password) {
            return response()->json([
                'ok'      => false,
                'message' => 'Password not available from Synergy.',
            ], 400);
        }

        return response()->json([
            'ok'       => true,
            'password' => $password,
        ]);
    }

    /**
     * Login to cPanel – get URL from Synergy and redirect.
     */
    public function login(HostingService $service, SynergyWholesaleClient $synergy)
    {
        try {
            $identifier = $service->domain_name ?: ($service->username ?: (string) $service->hoid);

            \Log::info('Attempting cPanel login', [
                'service_id' => $service->id,
                'identifier' => $identifier,
                'hoid' => $service->hoid
            ]);

            $url = $synergy->hostingGetLogin($identifier, $service->hoid);

            \Log::info('cPanel login URL retrieved', ['url' => $url]);

        } catch (\Throwable $e) {
            \Log::error('cPanel login failed', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->with('error', 'Error: ' . $e->getMessage());
        }

        if (! $url) {
            return back()->with('error', 'Unable to get cPanel login URL from Synergy. Please check the service configuration.');
        }

        return redirect()->away($url);
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
}
