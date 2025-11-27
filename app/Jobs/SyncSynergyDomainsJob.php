<?php

namespace App\Jobs;

use App\Services\Synergy\SynergyWholesaleClient;
use App\Models\Domain;

class SyncSynergyDomainsJob extends Job
{
    public function handle(SynergyWholesaleClient $synergy): void
    {
        $names = Domain::pluck('name')->all();
        foreach (array_chunk($names, 100) as $chunk) {
            $info = $synergy->bulkDomainInfo($chunk);
            foreach ($info as $row) {
                Domain::updateOrCreate(['name'=>$row['domainName']], [
                    'status' => $row['status'] ?? null,
                    'expiry_date' => isset($row['expiry']) ? substr($row['expiry'],0,10) : null,
                    'name_servers' => $row['nameServers'] ?? null,
                    'auto_renew' => (bool)($row['autoRenew'] ?? 0),
                    'dns_config' => $row['dnsConfig'] ?? null,
                    'transfer_status' => $row['transfer'] ?? null,
                ]);
            }
        }
    }
}
