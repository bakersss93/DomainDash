<?php

namespace App\Support;

class DomainNameExtractor
{
    /**
     * Pull a clean, lower-case domain name out of a Halo asset (inventory
     * number / asset tag / name). Returns null when it is not a domain.
     */
    public static function fromAsset(array $asset): ?string
    {
        $raw = $asset['inventory_number']
            ?? $asset['InventoryNumber']
            ?? $asset['asset_tag']
            ?? $asset['AssetTag']
            ?? $asset['name']
            ?? $asset['Name']
            ?? null;

        return self::clean($raw);
    }

    public static function clean(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        $domainName = strtolower(trim($raw));
        $domainName = preg_replace('#^https?://#', '', $domainName);
        $domainName = preg_replace('#^www\.#', '', $domainName);
        $domainName = preg_replace('#/.*$#', '', $domainName);

        if (! preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/', $domainName)) {
            return null;
        }

        return $domainName;
    }
}
