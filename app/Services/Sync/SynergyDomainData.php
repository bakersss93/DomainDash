<?php

namespace App\Services\Sync;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use SoapClient;

/**
 * Synergy Wholesale lookups needed when pushing a domain to Halo / IT Glue.
 * Runs inside queued jobs only (SOAP calls are slow); previously duplicated
 * as private methods in SyncController and ClientsController.
 */
class SynergyDomainData
{
    private function soap(): ?array
    {
        $cfg = Setting::get('synergy', []);
        $wsdl = $cfg['wsdl_path'] ?? null;
        $reseller = $cfg['reseller_id'] ?? null;
        $key = $cfg['api_key'] ?? null;

        if (empty($wsdl) || empty($reseller) || empty($key)) {
            Log::warning('Synergy Wholesale not fully configured for sync lookups');

            return null;
        }

        return [new SoapClient($wsdl, ['trace' => 1, 'exceptions' => true]), ['resellerID' => $reseller, 'apiKey' => $key]];
    }

    /**
     * DNS zone records, [] when the zone is genuinely empty, null when the
     * lookup could not be completed (so callers can keep existing remote data).
     */
    public function dnsRecords(string $domainName): ?array
    {
        try {
            $soap = $this->soap();
            if (! $soap) {
                return null;
            }

            [$client, $auth] = $soap;
            $response = $client->__soapCall('listDNSZone', [$auth + ['domainName' => $domainName]]);

            if (($response->status ?? null) === 'OK') {
                return (array) ($response->records ?? []);
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Error fetching DNS from Synergy: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @return array{formatted: string, nameservers: array}
     */
    public function whois(string $domainName): array
    {
        try {
            $soap = $this->soap();
            if (! $soap) {
                return ['formatted' => 'WHOIS data unavailable - Synergy Wholesale not configured', 'nameservers' => []];
            }

            [$client, $auth] = $soap;
            $response = $client->__soapCall('domainInfo', [$auth + ['domainName' => $domainName]]);

            if (($response->status ?? null) === 'OK') {
                $nameservers = isset($response->nameServers) && is_array($response->nameServers)
                    ? $response->nameServers
                    : [];

                $whois = "=== WHOIS Information for {$domainName} ===\n\n";
                foreach ([
                    'registrantName' => 'Registrant',
                    'registrantOrganisation' => 'Organization',
                    'registrantEmail' => 'Email',
                    'expiryDate' => 'Expiry Date',
                    'registrarName' => 'Registrar',
                    'status' => 'Status',
                ] as $field => $label) {
                    if (isset($response->{$field})) {
                        $whois .= "{$label}: {$response->{$field}}\n";
                    }
                }

                if (! empty($nameservers)) {
                    $whois .= "\nName Servers:\n";
                    foreach ($nameservers as $ns) {
                        $whois .= "  - {$ns}\n";
                    }
                }

                return ['formatted' => $whois, 'nameservers' => $nameservers];
            }

            return ['formatted' => 'WHOIS lookup failed: ' . ($response->statusDescription ?? 'Unknown error'), 'nameservers' => []];
        } catch (\Throwable $e) {
            Log::error('Error fetching WHOIS from Synergy: ' . $e->getMessage());

            return ['formatted' => 'WHOIS data unavailable - Error: ' . $e->getMessage(), 'nameservers' => []];
        }
    }
}
