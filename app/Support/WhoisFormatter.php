<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class WhoisFormatter
{
    public static function overview(?array $data, ?string $domain = null, $syncedAt = null): array
    {
        $data = $data ?? [];

        $nameservers = [];
        $nameserversDetail = [];

        $rawNameServers = $data['name_servers'] ?? $data['nameservers'] ?? $data['name_server'] ?? [];

        if (is_string($rawNameServers)) {
            $nameservers = preg_split('/\s+/', trim($rawNameServers));
        } elseif (is_array($rawNameServers)) {
            foreach ($rawNameServers as $idx => $ns) {
                if (is_array($ns)) {
                    $host = $ns['name']
                        ?? $ns['host']
                        ?? $ns['nameserver']
                        ?? $ns['value']
                        ?? null;

                    $ips = array_filter([
                        $ns['ip'] ?? null,
                        $ns['ipv4'] ?? null,
                        $ns['ipv6'] ?? null,
                        $ns['ip_address'] ?? null,
                    ]);

                    if ($host) {
                        $nameservers[] = $host;
                        $nameserversDetail[] = [
                            'host' => $host,
                            'ips' => $ips,
                        ];
                    }
                } else {
                    $host = is_string($ns) ? trim($ns) : null;
                    if ($host) {
                        $nameservers[] = $host;
                        $nameserversDetail[] = [
                            'host' => $host,
                            'ips' => [],
                        ];
                    }
                }
            }
        }

        // Pair IP lists when provided as separate arrays
        $ipList = $data['name_server_ip']
            ?? $data['name_servers_ip']
            ?? $data['nameserver_ips']
            ?? [];

        if (is_array($ipList) && !empty($ipList) && count($nameserversDetail) === count($ipList)) {
            foreach ($nameserversDetail as $i => &$nsDetail) {
                $ip = $ipList[$i] ?? null;
                if ($ip) {
                    $nsDetail['ips'][] = $ip;
                }
            }
            unset($nsDetail);
        }

        $nameservers = array_values(array_unique(array_filter(array_map('trim', $nameservers))));
        $nameserversDetail = array_values($nameserversDetail);

        $registrar = $data['registrar']['name']
            ?? $data['registrar_name']
            ?? $data['registrar']
            ?? null;

        $registrarUrl = $data['registrar']['url']
            ?? $data['registrar_url']
            ?? $data['whois_server']
            ?? null;

        $registrarWhois = $data['registrar']['whois_server']
            ?? $data['registrar_whois_server']
            ?? $data['whois_server']
            ?? null;

        $registrarAbuseEmail = $data['registrar_abuse_contact_email']
            ?? $data['registrar']['abuse_email']
            ?? null;

        $registrarAbusePhone = $data['registrar_abuse_contact_phone']
            ?? $data['registrar']['abuse_phone']
            ?? null;

        $reseller = $data['reseller'] ?? null;

        $registrantName = $data['registrant']['name']
            ?? $data['registrant_name']
            ?? null;

        $registrantOrg = $data['registrant']['organization']
            ?? $data['registrant']['organisation']
            ?? $data['registrant_organization']
            ?? $data['registrantOrganisation']
            ?? null;

        $registrantEmail = $data['registrant']['email']
            ?? $data['registrant_email']
            ?? null;

        $registrantPhone = $data['registrant']['phone']
            ?? $data['registrant_phone']
            ?? null;

        $registrantContactId = $data['registrant']['id']
            ?? $data['registrant_id']
            ?? null;

        $registrantCountry = $data['registrant']['country']
            ?? $data['registrant_country']
            ?? null;

        $registrantState = $data['registrant']['state']
            ?? $data['registrant_state']
            ?? null;

        $registrantCity = $data['registrant']['city']
            ?? $data['registrant_city']
            ?? null;

        $registrantPostal = $data['registrant']['postal_code']
            ?? $data['registrant_postal_code']
            ?? null;

        $registrantAddress = $data['registrant']['street_address']
            ?? $data['registrant_address']
            ?? null;

        $statusRaw = $data['domain_status']
            ?? $data['status']
            ?? null;

        $statusList = [];
        if (is_array($statusRaw)) {
            $statusList = $statusRaw;
        } elseif (is_string($statusRaw)) {
            $statusList = preg_split('/\s*[,;\n]+\s*/', $statusRaw);
        }

        $statusList = array_values(array_filter(array_map('trim', $statusList)));

        $syncedLabel = null;
        if ($syncedAt) {
            $syncedLabel = $syncedAt instanceof Carbon
                ? $syncedAt->toDayDateTimeString()
                : (string) $syncedAt;
        }

        $dnssec = $data['dnssec'] ?? $data['dns_sec'] ?? null;

        $eligibilityType = $data['eligibility_type'] ?? null;
        $eligibilityName = $data['eligibility_name'] ?? null;
        $eligibilityId = $data['eligibility_id'] ?? null;
        $registrantAbn = $data['registrant_abn'] ?? $data['eligibility_abn'] ?? null;
        $lastUpdated = self::formatDate(
            $data['last_update']
                ?? $data['last_updated']
                ?? $data['last_modified']
                ?? $data['updated_date']
                ?? $data['last_update_of_whois_database']
                ?? null
        );

        $techContactId = $data['tech_contact_id'] ?? $data['tech_id'] ?? null;
        $techContactName = $data['tech_contact_name'] ?? $data['tech_name'] ?? null;
        $techContactEmail = $data['tech_contact_email'] ?? $data['tech_email'] ?? null;
        $techContactPhone = $data['tech_contact_phone'] ?? $data['tech_phone'] ?? null;

        return [
            'has_data' => !empty($data),
            'domain' => $data['domain_name'] ?? $domain,
            'domain_id' => $data['domain_id'] ?? $data['registry_domain_id'] ?? null,
            'registrar' => $registrar,
            'registrar_url' => $registrarUrl,
            'registrar_whois' => $registrarWhois,
            'registrar_abuse_email' => $registrarAbuseEmail,
            'registrar_abuse_phone' => $registrarAbusePhone,
            'reseller' => $reseller,
            'status' => $statusList ? implode(', ', $statusList) : null,
            'status_list' => $statusList,
            'created_at' => self::formatDate($data['create_date'] ?? $data['created'] ?? null),
            'updated_at' => self::formatDate($data['update_date'] ?? $data['updated'] ?? null),
            'expires_at' => self::formatDate($data['expiry_date'] ?? $data['expires'] ?? null),
            'registrant' => $registrantOrg && $registrantName
                ? $registrantName . ' (' . $registrantOrg . ')'
                : ($registrantName ?? $registrantOrg),
            'registrant_contact_id' => $registrantContactId,
            'registrant_email' => $registrantEmail,
            'registrant_phone' => $registrantPhone,
            'registrant_address' => $registrantAddress,
            'registrant_city' => $registrantCity,
            'registrant_state' => $registrantState,
            'registrant_postal' => $registrantPostal,
            'registrant_country' => $registrantCountry,
            'tech_contact_id' => $techContactId,
            'tech_contact_name' => $techContactName,
            'tech_contact_email' => $techContactEmail,
            'tech_contact_phone' => $techContactPhone,
            'dnssec' => $dnssec,
            'eligibility_type' => $eligibilityType,
            'eligibility_name' => $eligibilityName,
            'eligibility_id' => $eligibilityId,
            'registrant_abn' => $registrantAbn,
            'last_updated' => $lastUpdated,
            'nameservers' => $nameservers,
            'nameservers_detail' => $nameserversDetail,
            'synced_at' => $syncedLabel,
        ];
    }

    public static function formatText(?array $data, ?string $domain = null, $syncedAt = null): string
    {
        $overview = self::overview($data, $domain, $syncedAt);

        if (!$overview['has_data']) {
            return 'WHOIS data not available. Run an IP2WHOIS sync to pull the latest details.';
        }

        $lines = [];
        $heading = '=== WHOIS Information';
        if ($overview['domain']) {
            $heading .= ' for ' . $overview['domain'];
        }
        $heading .= ' ===';

        $lines[] = $heading;

        $lines[] = '--- Registrar ---';
        if ($overview['registrar']) {
            $lines[] = 'Registrar: ' . $overview['registrar'];
        }

        if ($overview['registrar_url']) {
            $lines[] = 'Registrar URL: ' . $overview['registrar_url'];
        }

        if ($overview['registrar_whois']) {
            $lines[] = 'Registrar WHOIS: ' . $overview['registrar_whois'];
        }

        if ($overview['domain_id']) {
            $lines[] = 'Domain ID: ' . $overview['domain_id'];
        }

        if ($overview['registrar_abuse_email'] || $overview['registrar_abuse_phone']) {
            $lines[] = 'Registrar Abuse: ' . trim(($overview['registrar_abuse_email'] ?? '') . ' ' . ($overview['registrar_abuse_phone'] ?? ''));
        }

        if ($overview['reseller']) {
            $lines[] = 'Reseller: ' . $overview['reseller'];
        }

        $lines[] = '';
        $lines[] = '--- Status ---';
        if ($overview['status']) {
            $lines[] = 'Status: ' . $overview['status'];
        }

        if (!empty($overview['status_list'])) {
            foreach ($overview['status_list'] as $status) {
                $lines[] = '  • ' . $status;
            }
        }

        if ($overview['created_at']) {
            $lines[] = 'Created: ' . $overview['created_at'];
        }

        if ($overview['updated_at']) {
            $lines[] = 'Last Updated: ' . $overview['updated_at'];
        }

        if ($overview['expires_at']) {
            $lines[] = 'Expiry: ' . $overview['expires_at'];
        }

        $lines[] = '';
        $lines[] = '--- Registrant ---';
        if ($overview['registrant']) {
            $lines[] = 'Registrant: ' . $overview['registrant'];
        }

        if ($overview['registrant_contact_id']) {
            $lines[] = 'Registrant Contact ID: ' . $overview['registrant_contact_id'];
        }

        if ($overview['registrant_email']) {
            $lines[] = 'Registrant Email: ' . $overview['registrant_email'];
        }

        if ($overview['registrant_phone']) {
            $lines[] = 'Registrant Phone: ' . $overview['registrant_phone'];
        }

        $locationParts = array_filter([
            $overview['registrant_city'] ?? null,
            $overview['registrant_state'] ?? null,
            $overview['registrant_postal'] ?? null,
            $overview['registrant_country'] ?? null,
        ]);

        if (!empty($locationParts)) {
            $lines[] = 'Registrant Location: ' . implode(', ', $locationParts);
        }

        if ($overview['registrant_address']) {
            $lines[] = 'Registrant Address: ' . $overview['registrant_address'];
        }

        $lines[] = '';
        $lines[] = '--- Technical Contact ---';
        if ($overview['tech_contact_name']) {
            $lines[] = 'Tech Contact: ' . $overview['tech_contact_name'];
        }
        if ($overview['tech_contact_id']) {
            $lines[] = 'Tech Contact ID: ' . $overview['tech_contact_id'];
        }
        if ($overview['tech_contact_email']) {
            $lines[] = 'Tech Contact Email: ' . $overview['tech_contact_email'];
        }
        if ($overview['tech_contact_phone']) {
            $lines[] = 'Tech Contact Phone: ' . $overview['tech_contact_phone'];
        }

        $lines[] = '';
        $lines[] = '--- Nameservers ---';
        if (!empty($overview['nameservers'])) {
            foreach ($overview['nameservers_detail'] ?? [] as $ns) {
                $ipString = '';
                if (!empty($ns['ips'])) {
                    $ipString = ' (' . implode(', ', $ns['ips']) . ')';
                }
                $lines[] = '  - ' . ($ns['host'] ?? '') . $ipString;
            }

            if (empty($overview['nameservers_detail'])) {
                foreach ($overview['nameservers'] as $ns) {
                    $lines[] = '  - ' . $ns;
                }
            }
        }

        if ($overview['dnssec']) {
            $lines[] = 'DNSSEC: ' . $overview['dnssec'];
        }

        $lines[] = '';
        $lines[] = '--- Eligibility ---';
        if ($overview['eligibility_name']) {
            $lines[] = 'Eligibility Name: ' . $overview['eligibility_name'];
        }
        if ($overview['eligibility_type']) {
            $lines[] = 'Eligibility Type: ' . $overview['eligibility_type'];
        }
        if ($overview['eligibility_id']) {
            $lines[] = 'Eligibility ID: ' . $overview['eligibility_id'];
        }
        if ($overview['registrant_abn']) {
            $lines[] = 'Registrant ABN: ' . $overview['registrant_abn'];
        }

        if ($overview['synced_at']) {
            $lines[] = 'WHOIS last synced: ' . $overview['synced_at'];
        }

        if ($overview['last_updated']) {
            $lines[] = 'WHOIS last updated: ' . $overview['last_updated'];
        }

        return implode("\n", array_filter($lines));
    }

    protected static function formatDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return is_string($value) ? $value : null;
        }
    }
}
