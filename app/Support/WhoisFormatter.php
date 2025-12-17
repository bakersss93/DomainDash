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
            ?? $data['registrar']
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

        $syncedLabel = null;
        if ($syncedAt) {
            $syncedLabel = $syncedAt instanceof Carbon
                ? $syncedAt->toDayDateTimeString()
                : (string) $syncedAt;
        }

        return [
            'has_data' => !empty($data),
            'domain' => $data['domain_name'] ?? $domain,
            'registrar' => $registrar,
            'status' => $data['status'] ?? null,
            'created_at' => self::formatDate($data['create_date'] ?? $data['created'] ?? null),
            'updated_at' => self::formatDate($data['update_date'] ?? $data['updated'] ?? null),
            'expires_at' => self::formatDate($data['expiry_date'] ?? $data['expires'] ?? null),
            'registrant' => $registrantOrg && $registrantName
                ? $registrantName . ' (' . $registrantOrg . ')'
                : ($registrantName ?? $registrantOrg),
            'registrant_email' => $registrantEmail,
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
