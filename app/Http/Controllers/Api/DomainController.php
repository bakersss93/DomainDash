<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Domain::select('id', 'name', 'expiry_date', 'client_id');

        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->client_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return response()->json(
            $q->orderBy('name')->paginate((int) $request->get('per_page', 100))
        );
    }

    public function show(Domain $domain): JsonResponse
    {
        return response()->json($domain);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $domain->client_id = $data['client_id'] ?? null;
        $domain->save();

        AuditLogger::logSystem('domain.assign-client', "Client assignment updated for {$domain->name}.", [
            'service' => 'api',
            'function' => 'domain.assign-client',
        ], ['new_values' => ['client_id' => $domain->client_id]]);

        return response()->json($domain);
    }

    public function sync(SynergyWholesaleClient $synergy): JsonResponse
    {
        $page = 1;
        $limit = 500;
        $imported = 0;

        do {
            $response = $synergy->listDomains($page, $limit);

            if (($response['status'] ?? null) !== 'OK') {
                return response()->json([
                    'message' => 'Synergy error: ' . ($response['errorMessage'] ?? 'Unknown error'),
                ], 502);
            }

            foreach ($response['domainList'] ?? [] as $entry) {
                $name = $entry->domainName ?? null;
                if (!$name || ($entry->status ?? '') === 'ERR_DOMAIN_NOT_FOUND') {
                    continue;
                }

                Domain::updateOrCreate(['name' => $name], [
                    'status'          => $entry->domainStatus ?? null,
                    'expiry_date'     => isset($entry->domain_expiry) ? substr($entry->domain_expiry, 0, 10) : null,
                    'name_servers'    => $entry->nameServers ?? [],
                    'dns_config'      => $entry->dnsConfig ?? null,
                    'auto_renew'      => isset($entry->autoRenew)
                        ? in_array(strtolower((string) $entry->autoRenew), ['on', 'true', '1'], true)
                        : null,
                    'transfer_status' => $entry->transfer_status ?? null,
                ]);

                $imported++;
            }

            $received = count($response['domainList'] ?? []);
            $page++;
        } while ($received >= $limit && $page < 1000);

        AuditLogger::logSystem('sync.completed', "API domain bulk sync completed ({$imported} domains).", [
            'service' => 'api',
            'function' => 'domains.sync',
        ], ['new_values' => ['imported' => $imported]]);

        return response()->json(['message' => "Sync complete. Imported/updated {$imported} domains.", 'count' => $imported], 202);
    }

    public function checkAvailability(Request $request, SynergyWholesaleClient $synergy): JsonResponse
    {
        $request->validate(['domain' => 'required|string']);

        $res = $synergy->checkDomain($request->domain);

        $available = ($res['status'] ?? null) === 'OK' && ($res['available'] ?? false);

        return response()->json([
            'ok'        => ($res['status'] ?? null) === 'OK',
            'available' => $available,
            'domain'    => $request->domain,
            'message'   => $res['errorMessage'] ?? ($available ? 'Domain is available.' : 'Domain is not available.'),
        ]);
    }

    public function renew(Request $request, Domain $domain, SynergyWholesaleClient $synergy): JsonResponse
    {
        $request->validate(['years' => 'required|integer|min:1|max:10']);

        $res = $synergy->renewDomain($domain->name, (int) $request->years);

        AuditLogger::logSystem('domain.renew', "Renewal requested via API for {$domain->name}.", [
            'service' => 'api',
            'function' => 'domain.renew',
        ], ['new_values' => ['years' => (int) $request->years]]);

        return response()->json([
            'ok'      => ($res['status'] ?? null) === 'OK',
            'message' => $res['errorMessage'] ?? 'Renewal submitted.',
        ]);
    }

    public function authCode(Domain $domain, SynergyWholesaleClient $synergy): JsonResponse
    {
        try {
            $code = $synergy->getDomainPassword($domain->name);

            if (!$code) {
                return response()->json(['ok' => false, 'message' => 'No auth code returned from registrar.'], 502);
            }

            return response()->json(['ok' => true, 'code' => $code]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Unable to fetch auth code: ' . $e->getMessage()], 500);
        }
    }

    public function transfer(Request $request, SynergyWholesaleClient $synergy): JsonResponse
    {
        $payload = $request->validate([
            'domainName'   => 'required|string',
            'authInfo'     => 'required|string',
            'organisation' => 'required|string',
            'firstname'    => 'required|string',
            'lastname'     => 'required|string',
            'address'      => 'required|array',
            'suburb'       => 'required|string',
            'state'        => 'required|string',
            'country'      => 'required|string',
            'postcode'     => 'required|string',
            'phone'        => 'required|string',
            'email'        => 'required|email',
            'doRenewal'    => 'required|boolean',
            'idProtect'    => 'required|boolean',
        ]);

        $res = $synergy->transferDomain($payload);

        AuditLogger::logSystem('domain.transfer', "Domain transfer initiated via API for {$payload['domainName']}.", [
            'service' => 'api',
            'function' => 'domain.transfer',
        ], ['new_values' => ['domain' => $payload['domainName']]]);

        return response()->json([
            'ok'      => ($res['status'] ?? null) === 'OK',
            'message' => $res['errorMessage'] ?? 'Transfer initiated.',
        ]);
    }
}
