<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Illuminate\Http\Request;
use App\Services\Synergy\SynergyWholesaleClient;

class DnsController extends Controller
{
    /**
     * Show DNS records and/or nameservers for a domain.
     */
    public function index(Domain $domain, SynergyWholesaleClient $synergy)
    {
        $dnsMode     = (int) ($domain->dns_config ?? 0);
        $nameservers = [];

        // 1) Try to get DNS config + nameservers from Synergy
        try {
            $info = $synergy->getNameserversAndConfig($domain->name);

            if (! $dnsMode && ! empty($info['dnsConfig'])) {
                $dnsMode = (int) $info['dnsConfig'];
            }

            $nameservers = $info['nameServers'] ?? [];
        } catch (\Throwable $e) {
            // fallback: whatever we have locally
            if (is_array($domain->name_servers ?? null)) {
                $nameservers = $domain->name_servers;
            }
        }

        // 2) Always try to load the DNS zone – we’ll decide in the view
        $records = collect();

        try {
            $rawRecords = $synergy->listDNSZone($domain->name);

            $records = collect($rawRecords)->map(function ($r) {
                $r = (array) $r;

                return (object) [
                    'id'      => $r['id']       ?? null,
                    'host'    => $r['hostName'] ?? '',
                    'type'    => $r['type']     ?? '',
                    'content' => $r['content']  ?? '',
                    'ttl'     => (int)($r['ttl'] ?? 3600),
                    'prio'    => (int)($r['prio'] ?? 0),
                ];
            });
        } catch (\Throwable $e) {
            session()->flash(
                'status',
                'Unable to refresh DNS records from Synergy: ' . $e->getMessage()
            );
        }

        return view('dns.index', [
            'domain'      => $domain,
            'records'     => $records,
            'dnsMode'     => $dnsMode,
            'nameservers' => $nameservers,
        ]);
    }

    /**
     * Add a DNS record.
     */
    public function store(Request $request, Domain $domain, SynergyWholesaleClient $synergy)
    {
        $data = $request->validate([
            'host'    => 'nullable|string|max:255',
            'type'    => 'required|string|max:10',
            'content' => 'required|string|max:2000',
            'ttl'     => 'required|integer|min:60|max:604800',
            'prio'    => 'nullable|integer|min:0|max:65535',
        ]);

        $synergy->addDNSRecord(
            $domain->name,
            $data['host'] ?? '',
            $data['type'],
            $data['content'],
            $data['ttl'],
            $data['prio'] ?? 0
        );

        return back()->with('status', 'DNS record added.');
    }

    /**
     * Update a DNS record (delete + add).
     */
    public function update(Request $request, Domain $domain, $recordId, SynergyWholesaleClient $synergy)
    {
        $data = $request->validate([
            'host'    => 'nullable|string|max:255',
            'type'    => 'required|string|max:10',
            'content' => 'required|string|max:2000',
            'ttl'     => 'required|integer|min:60|max:604800',
            'prio'    => 'nullable|integer|min:0|max:65535',
        ]);

        $synergy->deleteDNSRecord($domain->name, $recordId);

        $synergy->addDNSRecord(
            $domain->name,
            $data['host'] ?? '',
            $data['type'],
            $data['content'],
            $data['ttl'],
            $data['prio'] ?? 0
        );

        return back()->with('status', 'DNS record updated.');
    }

    /**
     * Delete a DNS record.
     */
    public function destroy(Domain $domain, $recordId, SynergyWholesaleClient $synergy)
    {
        $synergy->deleteDNSRecord($domain->name, $recordId);

        return back()->with('status', 'DNS record deleted.');
    }

    /**
     * DNS options modal – change dnsConfig + nameservers.
     */
    public function updateOptions(
        Request $request,
        Domain $domain,
        SynergyWholesaleClient $synergy
    ) {
        $data = $request->validate([
            // 1 = Custom NS, 2 = URL & Email Forwarding, 3 = Parking, 4 = DNS Hosting
            'dns_mode'      => 'required|integer|in:1,2,3,4',
            'nameservers'   => 'array',
            'nameservers.*' => 'nullable|string|max:255',
        ]);

        $dnsMode     = (int) $data['dns_mode'];
        $nameservers = array_filter($data['nameservers'] ?? []);

        try {
            $synergy->updateNameServers(
                $domain->name,
                $nameservers,
                $dnsMode,
                null // dnsConfigType is optional
            );
        } catch (\Throwable $e) {
            $domain->dns_config = $dnsMode;
            $domain->save();

            return back()->with(
                'status',
                'DNS options saved locally but failed to update Synergy: ' . $e->getMessage()
            );
        }

        // Keep local state in sync with Synergy
        $domain->dns_config = $dnsMode;

        if ($dnsMode === 1 && $domain->isFillable('name_servers')) {
            $domain->name_servers = array_values($nameservers);
        }

        $domain->save();

        return back()->with(
            'status',
            'DNS options updated for ' . $domain->name
        );
    }
}
