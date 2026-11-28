<?php

namespace App\Support;

use App\Models\Domain;
use App\Services\Synergy\SynergyWholesaleClient;

/**
 * Synergy record ids are global to the reseller account, not scoped to a domain. Before a record is
 * changed or deleted through a domain the caller is authorised for, confirm that record really
 * belongs to that domain's zone (fails closed if the zone cannot be read).
 */
class DnsZoneGuard
{
    public static function recordBelongsToDomain(SynergyWholesaleClient $synergy, Domain $domain, string $recordId): bool
    {
        try {
            $records = $synergy->listDNSZone($domain->name);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        foreach ((array) $records as $record) {
            $record = (array) $record;

            if (isset($record['id']) && (string) $record['id'] === $recordId) {
                return true;
            }
        }

        return false;
    }
}
