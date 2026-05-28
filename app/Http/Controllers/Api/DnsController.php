<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DnsController extends Controller
{
    public function index(Domain $domain, SynergyWholesaleClient $synergy): JsonResponse
    {
        try {
            $raw = $synergy->listDNSZone($domain->name);

            $records = collect($raw)->map(fn($r) => [
                'record_id' => $r['id']       ?? null,
                'host'      => $r['hostName'] ?? '',
                'type'      => $r['type']     ?? '',
                'content'   => $r['content']  ?? '',
                'ttl'       => (int) ($r['ttl']  ?? 3600),
                'prio'      => (int) ($r['prio'] ?? 0),
            ])->values();

            return response()->json(['domain_id' => $domain->id, 'domain' => $domain->name, 'records' => $records]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Unable to retrieve DNS zone: ' . $e->getMessage()], 502);
        }
    }

    public function store(Request $request, Domain $domain, SynergyWholesaleClient $synergy): JsonResponse
    {
        $data = $request->validate([
            'host'    => 'nullable|string|max:255',
            'type'    => 'required|string|max:10',
            'content' => 'required|string|max:2000',
            'ttl'     => 'required|integer|min:60|max:604800',
            'prio'    => 'nullable|integer|min:0|max:65535',
        ]);

        try {
            $synergy->addDNSRecord(
                $domain->name,
                $data['host'] ?? '',
                $data['type'],
                $data['content'],
                $data['ttl'],
                $data['prio'] ?? 0
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to add DNS record: ' . $e->getMessage()], 502);
        }

        AuditLogger::logSystem('dns.create', "DNS record added via API for {$domain->name}.", [
            'service' => 'api',
            'function' => 'dns.record-create',
            'client_id' => $domain->client_id,
        ], ['new_values' => $data]);

        return response()->json(['ok' => true, 'message' => 'DNS record added.'], 201);
    }

    public function update(Request $request, Domain $domain, string $recordId, SynergyWholesaleClient $synergy): JsonResponse
    {
        $data = $request->validate([
            'host'    => 'nullable|string|max:255',
            'type'    => 'required|string|max:10',
            'content' => 'required|string|max:2000',
            'ttl'     => 'required|integer|min:60|max:604800',
            'prio'    => 'nullable|integer|min:0|max:65535',
        ]);

        try {
            $synergy->deleteDNSRecord($domain->name, $recordId);
            $synergy->addDNSRecord(
                $domain->name,
                $data['host'] ?? '',
                $data['type'],
                $data['content'],
                $data['ttl'],
                $data['prio'] ?? 0
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to update DNS record: ' . $e->getMessage()], 502);
        }

        AuditLogger::logSystem('dns.update', "DNS record updated via API for {$domain->name}.", [
            'service' => 'api',
            'function' => 'dns.record-update',
            'client_id' => $domain->client_id,
        ], ['new_values' => array_merge($data, ['record_id' => $recordId])]);

        return response()->json(['ok' => true, 'message' => 'DNS record updated.']);
    }

    public function destroy(Domain $domain, string $recordId, SynergyWholesaleClient $synergy): JsonResponse
    {
        try {
            $synergy->deleteDNSRecord($domain->name, $recordId);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to delete DNS record: ' . $e->getMessage()], 502);
        }

        AuditLogger::logSystem('dns.delete', "DNS record deleted via API for {$domain->name}.", [
            'service' => 'api',
            'function' => 'dns.record-delete',
            'client_id' => $domain->client_id,
        ], ['new_values' => ['record_id' => $recordId]]);

        return response()->json(null, 204);
    }

    public function updateOptions(Request $request, Domain $domain, SynergyWholesaleClient $synergy): JsonResponse
    {
        $data = $request->validate([
            'dns_mode'      => 'required|integer|in:1,2,3,4',
            'nameservers'   => 'array',
            'nameservers.*' => 'nullable|string|max:255',
        ]);

        $dnsMode     = (int) $data['dns_mode'];
        $nameservers = array_values(array_filter($data['nameservers'] ?? []));

        try {
            $synergy->updateNameServers($domain->name, $nameservers, $dnsMode, null);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to update nameservers: ' . $e->getMessage()], 502);
        }

        $domain->dns_config = $dnsMode;
        if ($dnsMode === 1) {
            $domain->name_servers = $nameservers;
        }
        $domain->save();

        AuditLogger::logSystem('dns.options-update', "DNS options updated via API for {$domain->name}.", [
            'service' => 'api',
            'function' => 'dns.options',
            'client_id' => $domain->client_id,
        ], ['new_values' => ['dns_mode' => $dnsMode, 'nameservers' => $nameservers]]);

        return response()->json(['ok' => true, 'message' => 'DNS options updated.']);
    }
}
