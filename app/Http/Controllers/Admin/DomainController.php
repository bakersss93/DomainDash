<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Client;
use Illuminate\Http\Request;
use App\Services\Synergy\SynergyWholesaleClient;
use App\Support\WhoisFormatter;

class DomainController extends Controller
{
    public function index(Request $request)
    {
        $sort   = $request->get('sort', 'name');
        $dir    = $request->get('dir',  'asc');
        $search = $request->get('q');

        $allowedSorts = ['name', 'status', 'expiry_date', 'dns_config'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'name';
        }
        if (! in_array($dir, ['asc','desc'], true)) {
            $dir = 'asc';
        }

        $q = Domain::query()->with('client');

        if ($search) {
            $q->where('name', 'like', '%'.$search.'%');
        }

        $domains = $q->orderBy($sort, $dir)
            ->paginate(50)
            ->withQueryString();

        $clients = Client::orderBy('business_name')->get();

        return view('admin.domains.index', compact('domains', 'sort', 'dir', 'search', 'clients'));
    }

    public function show(Domain $domain)
    {
        $domain->load('client');

        $dnsMap = [
            1 => 'Custom Nameservers',
            2 => 'URL & Email Forwarding',
            3 => 'Domain Parking',
            4 => 'DNS Hosting',
        ];

        $dnsLabel = null;
        if (! is_null($domain->dns_config)) {
            $code     = (int) $domain->dns_config;
            $dnsLabel = $dnsMap[$code] ?? $domain->dns_config;
        }

        $clients = Client::orderBy('business_name')->get();

        $whoisOverview = WhoisFormatter::overview(
            $domain->whois_data ?? [],
            $domain->name,
            $domain->whois_synced_at
        );

        $whoisText = WhoisFormatter::formatText(
            $domain->whois_data ?? [],
            $domain->name,
            $domain->whois_synced_at
        );

        $nameservers = $domain->name_servers;
        if (empty($nameservers) && !empty($whoisOverview['nameservers'])) {
            $nameservers = $whoisOverview['nameservers'];
        }

        if (is_string($nameservers)) {
            $nameservers = array_filter(array_map('trim', explode(',', $nameservers)));
        }

        return view('admin.domains.show', compact(
            'domain',
            'dnsLabel',
            'clients',
            'whoisOverview',
            'whoisText',
            'nameservers'
        ));
    }

    public function assignClient(Request $request, Domain $domain)
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $domain->client_id = $data['client_id'] ?: null;
        $domain->save();

        return back()->with('status', 'Client assignment updated for '.$domain->name);
    }

public function authCode(Domain $domain, SynergyWholesaleClient $synergy)
{
    try {
        $code = $synergy->getDomainPassword($domain->name);

        if (!$code) {
            throw new \RuntimeException('No auth/EPP code returned from registrar');
        }

        return response()->json([
            'ok'   => true,
            'code' => $code,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'ok'      => false,
            'message' => 'Unable to fetch auth code: '.$e->getMessage(),
        ], 500);
    }
}
    
    public function bulkSync(\Illuminate\Http\Request $request, SynergyWholesaleClient $synergy)
{
    $page      = 1;
    $limit     = 500;
    $imported  = 0;

    do {
        $response = $synergy->listDomains($page, $limit);

        if (($response['status'] ?? null) !== 'OK') {
            // If Synergy returned an error, show it to the admin
            $msg = $response['errorMessage'] ?? 'Unknown error from Synergy listDomains';
            return back()->with('status', 'Bulk sync failed: ' . $msg);
        }

        $list = $response['domainList'] ?? [];

        foreach ($list as $entry) {
            // Skip not-found errors in the list
            if (($entry->status ?? '') === 'ERR_DOMAIN_NOT_FOUND') {
                continue;
            }

            $name = $entry->domainName ?? null;
            if (! $name) {
                continue;
            }

            \App\Models\Domain::updateOrCreate(
                ['name' => $name],
                [
                    'status'          => $entry->domainStatus
                                         ?? $entry->domain_status
                                         ?? null,
                    'expiry_date'     => isset($entry->domain_expiry)
                                         ? substr($entry->domain_expiry, 0, 10)
                                         : null,
                    'name_servers'    => $entry->nameServers ?? [],
                    'dns_config'      => $entry->dnsConfig   ?? null,
                    'auto_renew'      => isset($entry->autoRenew)
                        ? in_array(strtolower((string)$entry->autoRenew), ['on', 'true', '1'], true)
                        : null,
                    'transfer_status' => $entry->transfer_status ?? null,
                ]
            );

            $imported++;
        }

        $received = count($list);
        $page++;

        // If fewer than limit returned, we've reached the end.
        $hasMore = $received >= $limit;

    } while ($hasMore && $page < 1000); // hard safety cap

    return back()->with('status', "Bulk sync complete. Imported/updated {$imported} domains.");
}


    public function searchAvailability(Request $request, SynergyWholesaleClient $synergy)
    {
        $request->validate(['domain'=>'required']);
        $res = $synergy->checkDomain($request->domain);
        return back()->with('availability', $res);
    }

    public function renew(Request $request, SynergyWholesaleClient $synergy, Domain $domain)
    {
        $request->validate(['years'=>'required|integer|min:1|max:10']);
        $res = $synergy->renewDomain($domain->name, (int)$request->years);
        return back()->with('status','Renewal requested')->with('renewal', $res);
    }

    public function transfer(Request $request, SynergyWholesaleClient $synergy)
    {
        $payload = $request->validate([
            'domainName'=>'required',
            'authInfo'=>'required',
            'organisation'=>'required',
            'firstname'=>'required',
            'lastname'=>'required',
            'address'=>'required|array',
            'suburb'=>'required',
            'state'=>'required',
            'country'=>'required',
            'postcode'=>'required',
            'phone'=>'required',
            'email'=>'required|email',
            'doRenewal'=>'required',
            'idProtect'=>'required',
        ]);
        $res = $synergy->transferDomain($payload);
        return back()->with('status','Transfer initiated')->with('transfer', $res);
    }
    
    public function transferForm(Domain $domain)
    {
        return view('admin.domains.transfer', compact('domain'));
    }

    public function delete(Domain $domain)
    {
        // For now just mark as inactive/archived in our DB without touching Synergy.
        // Adjust field names to your schema.
        if ($domain->fillable && in_array('active', $domain->getFillable(), true)) {
            $domain->active = false;
            $domain->save();
        }

        return back()->with('status', 'Domain marked as deleted in DomainDash (Synergy unchanged).');
    }

}
