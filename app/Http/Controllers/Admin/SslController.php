<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\AuditLogger;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function sync(SynergyWholesaleClient $synergy)
    {
        $response = $synergy->listAllSSLCerts();

        if (strtoupper((string) ($response['status'] ?? '')) !== 'OK') {
            $message = $response['errorMessage'] ?? 'Unknown error';
            AuditLogger::logSystem('sync.failed', 'SSL sync from Synergy failed.', [
                'service' => 'synergy',
                'function' => 'ssl-sync',
            ], [
                'new_values' => ['error' => $message],
            ]);

            return back()->with('status', 'Synergy SSL sync failed: ' . $message);
        }

        $productsResponse = $synergy->listSSLProducts();
        $productNameById = collect($productsResponse['pricing'] ?? [])
            ->map(fn (array $entry): array => [
                'id' => (string) ($entry['productID'] ?? ''),
                'name' => $entry['productName'] ?? null,
            ])
            ->filter(fn (array $entry): bool => $entry['id'] !== '' && !empty($entry['name']))
            ->pluck('name', 'id')
            ->all();

        $certs = $response['certs'] ?? [];
        $synced = 0;

        foreach ($certs as $entry) {
            $commonName = $entry['commonName'] ?? null;
            $normalizedDomainName = $commonName ? strtolower(ltrim($commonName, '*.')) : null;
            $domain = $normalizedDomainName ? Domain::where('name', $normalizedDomainName)->first() : null;
            $productId = (string) ($entry['productID'] ?? '');

            $ssl = !empty($entry['certID'])
                ? SslCertificate::firstOrNew(['cert_id' => (string) $entry['certID']])
                : SslCertificate::firstOrNew(['common_name' => $commonName]);

            $ssl->fill([
                'client_id' => $domain?->client_id,
                'domain_id' => $domain?->id,
                'cert_id' => $entry['certID'] ?? $ssl->cert_id,
                'common_name' => $commonName,
                'product_name' => $productNameById[$productId] ?? ($entry['productName'] ?? $productId ?: null),
                'start_date' => !empty($entry['startDate']) ? date('Y-m-d', strtotime($entry['startDate'])) : null,
                'expire_date' => !empty($entry['expireDate']) ? date('Y-m-d', strtotime($entry['expireDate'])) : null,
                'status' => $entry['status'] ?? null,
            ]);
            $ssl->save();

            $synced++;
        }

        AuditLogger::logSystem('sync.completed', 'SSL sync from Synergy completed.', [
            'service' => 'synergy',
            'function' => 'ssl-sync',
        ], [
            'new_values' => ['synced_count' => $synced],
        ]);

        return back()->with('status', "SSL certificates synced from Synergy ({$synced} records processed).");
    }

    public function index(Request $request)
    {
        $q = SslCertificate::query()->with('domain', 'client');

        if ($client = $request->get('client_id')) {
            $q->where('client_id', $client);
        }

        $ssls = $q->orderByDesc('expire_date')->paginate(25);
        $clients = Client::orderBy('business_name')->get();

        return view('admin.ssls.index', compact('ssls', 'clients'));
    }

    public function show(SslCertificate $ssl, SynergyWholesaleClient $synergy)
    {
        $ssl->load('client', 'domain');

        $statusPayload = null;

        if ($ssl->cert_id) {
            $statusPayload = $synergy->getSSLCertSimpleStatus($ssl->cert_id);
        }

        return view('admin.ssls.show', [
            'ssl' => $ssl,
            'statusPayload' => $statusPayload,
            'certPayload' => session('ssl_certificate_payload'),
            'actionMessage' => session('ssl_action_message'),
        ]);
    }

    public function getCertificate(SslCertificate $ssl, SynergyWholesaleClient $synergy)
    {
        if (!$ssl->cert_id) {
            return back()->with('ssl_action_message', 'This SSL record has no Synergy cert ID. Run sync first.');
        }

        $payload = $synergy->getSSLCertificate($ssl->cert_id);

        AuditLogger::logAction('ssl.get-certificate', $ssl, "Fetched certificate bundle for {$ssl->common_name}.", [
            'context' => ['service' => 'ssl', 'function' => 'get-certificate'],
            'new_values' => ['cert_id' => $ssl->cert_id],
        ]);

        return back()
            ->with('ssl_action_message', $payload['errorMessage'] ?? 'Certificate bundle fetched from Synergy.')
            ->with('ssl_certificate_payload', $payload);
    }

    public function renew(Request $request, SslCertificate $ssl, SynergyWholesaleClient $synergy)
    {
        if (!$ssl->cert_id) {
            return back()->with('ssl_action_message', 'This SSL record has no Synergy cert ID. Run sync first.');
        }

        $contact = $this->buildContactPayload($ssl->client);
        $payload = $synergy->renewSSLCertificate($ssl->cert_id, $contact);

        AuditLogger::logAction('ssl.renew', $ssl, "Renew attempted for {$ssl->common_name}.", [
            'context' => ['service' => 'ssl', 'function' => 'renew'],
            'new_values' => ['cert_id' => $ssl->cert_id, 'status' => $payload['status'] ?? null],
        ]);

        return back()->with('ssl_action_message', $payload['errorMessage'] ?? ($payload['status'] ?? 'Renew request submitted.'));
    }

    public function rekey(Request $request, SslCertificate $ssl, SynergyWholesaleClient $synergy)
    {
        $validated = $request->validate([
            'csr' => 'required|string',
        ]);

        if (!$ssl->cert_id) {
            return back()->with('ssl_action_message', 'This SSL record has no Synergy cert ID. Run sync first.');
        }

        $payload = $synergy->reissueSSLCertificate($ssl->cert_id, $validated['csr']);

        AuditLogger::logAction('ssl.rekey', $ssl, "Rekey/reissue attempted for {$ssl->common_name}.", [
            'context' => ['service' => 'ssl', 'function' => 'rekey'],
            'new_values' => ['cert_id' => $ssl->cert_id, 'status' => $payload['status'] ?? null],
        ]);

        return back()->with('ssl_action_message', $payload['errorMessage'] ?? ($payload['status'] ?? 'Rekey request submitted.'));
    }

    public function assignClient(Request $request, SslCertificate $ssl)
    {
        $validated = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $ssl->client_id = $validated['client_id'] ?? null;
        $ssl->save();

        AuditLogger::logAction('ssl.assign-client', $ssl, "Updated SSL client assignment for {$ssl->common_name}.", [
            'context' => ['service' => 'ssl', 'function' => 'assign-client'],
            'new_values' => ['client_id' => $ssl->client_id],
        ]);

        return back()->with('ssl_action_message', 'Client assignment updated.');
    }

    public function decodeCsr(Request $request, SynergyWholesaleClient $synergy)
    {
        $validated = $request->validate([
            'csr' => 'required|string',
        ]);

        $payload = $synergy->decodeSSLCsr($validated['csr']);
        $status = strtoupper((string) ($payload['status'] ?? ''));

        if ($status !== 'OK') {
            return response()->json([
                'success' => false,
                'message' => $payload['errorMessage'] ?? 'Unable to decode CSR.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'decoded' => [
                'commonName' => $payload['commonName'] ?? null,
                'organisation' => $payload['organisation'] ?? null,
                'organisationUnit' => $payload['organisationUnit'] ?? null,
                'city' => $payload['city'] ?? null,
                'state' => $payload['state'] ?? null,
                'country' => $payload['country'] ?? null,
                'emailAddress' => $payload['emailAddress'] ?? null,
                'privateKeyLength' => $payload['privateKeyLength'] ?? null,
            ],
        ]);
    }

    private function buildContactPayload(?Client $client): array
    {
        $contactName = trim((string) (($client?->primary_contact_name) ?: ($client?->business_name) ?: 'DomainDash Contact'));
        [$firstName, $lastName] = array_pad(preg_split('/\s+/', $contactName, 2) ?: [], 2, '');

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'emailAddress' => $client?->email ?: 'support@example.com',
            'address' => $client?->address ?: 'Unknown',
            'city' => $client?->city ?: 'Unknown',
            'state' => $client?->state ?: 'Unknown',
            'postCode' => $client?->postcode ?: '0000',
            'country' => $client?->country ?: 'AU',
            'phone' => $client?->phone ?: '0000000000',
            'fax' => $client?->phone ?: '0000000000',
        ];
    }
}
