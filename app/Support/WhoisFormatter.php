<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class WhoisFormatter
{
    public static function overview(?array $data, ?string $domain = null, $syncedAt = null): array
    {
        $data = $data ?? [];

        $nameservers = [];

        if (!empty($data['name_servers']) && is_array($data['name_servers'])) {
            $nameservers = $data['name_servers'];
        } elseif (!empty($data['nameservers']) && is_array($data['nameservers'])) {
            $nameservers = $data['nameservers'];
        } elseif (!empty($data['name_server']) && is_string($data['name_server'])) {
            $nameservers = preg_split('/\s+/', trim($data['name_server']));
        }

        $nameservers = array_values(array_unique(array_filter(array_map('trim', $nameservers))));

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

        return [
            'has_data' => !empty($data),
            'domain' => $data['domain_name'] ?? $domain,
            'domain_id' => $data['domain_id'] ?? $data['registry_domain_id'] ?? null,
            'registrar' => $registrar,
            'registrar_url' => $registrarUrl,
            'registrar_whois' => $registrarWhois,
            'status' => $statusList ? implode(', ', $statusList) : null,
            'status_list' => $statusList,
            'created_at' => self::formatDate($data['create_date'] ?? $data['created'] ?? null),
            'updated_at' => self::formatDate($data['update_date'] ?? $data['updated'] ?? null),
            'expires_at' => self::formatDate($data['expiry_date'] ?? $data['expires'] ?? null),
            'registrant' => $registrantOrg && $registrantName
                ? $registrantName . ' (' . $registrantOrg . ')'
                : ($registrantName ?? $registrantOrg),
            'registrant_email' => $registrantEmail,
            'registrant_phone' => $registrantPhone,
            'registrant_address' => $registrantAddress,
            'registrant_city' => $registrantCity,
            'registrant_state' => $registrantState,
            'registrant_postal' => $registrantPostal,
            'registrant_country' => $registrantCountry,
            'nameservers' => $nameservers,
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

        if ($overview['status']) {
            $lines[] = 'Status: ' . $overview['status'];
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

        if ($overview['registrant']) {
            $lines[] = 'Registrant: ' . $overview['registrant'];
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

        if (!empty($overview['nameservers'])) {
            $lines[] = 'Nameservers:';
            foreach ($overview['nameservers'] as $ns) {
                $lines[] = '  - ' . $ns;
            }
        }

        if ($overview['synced_at']) {
            $lines[] = 'WHOIS last synced: ' . $overview['synced_at'];
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
