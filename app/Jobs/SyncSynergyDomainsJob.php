<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Synergy\SynergyApiException;
use App\Services\Synergy\SynergyWholesaleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Refreshes status/expiry/nameservers/auto-renew for every known domain using
 * Synergy's `bulkDomainInfo` (100 domains per call).
 *
 * A failing batch is logged and skipped so one bad batch does not stop the
 * rest; the job fails at the end if any batch failed so the failure is visible
 * in failed_jobs / the audit log instead of reporting a silent success.
 */
class SyncSynergyDomainsJob implements ShouldQueue
{
    use Queueable;

    /** Below the queue connection's retry_after (config/queue.php). */
    public int $timeout = 300;

    public int $tries = 1;

    public function handle(SynergyWholesaleClient $synergy): void
    {
        $names = Domain::pluck('name')->all();
        $failedBatches = 0;
        $updated = 0;

        foreach (array_chunk($names, SynergyWholesaleClient::BULK_CHUNK) as $chunk) {
            try {
                $rows = $synergy->bulkDomainInfo($chunk);
            } catch (SynergyApiException $e) {
                $failedBatches++;
                Log::error('Synergy domain sync batch failed', [
                    'operation' => $e->operation,
                    'status'    => $e->apiStatus,
                    'message'   => $e->getMessage(),
                    'batch'     => count($chunk),
                ]);
                continue;
            }

            foreach ($rows as $row) {
                $status = strtoupper((string) ($row['status'] ?? ''));
                if (str_starts_with($status, 'ERR') || empty($row['domainName'])) {
                    continue; // e.g. ERR_DOMAIN_NOT_FOUND for a domain no longer in the account
                }

                $attributes = [
                    'status'          => $row['domain_status'] ?? null,
                    'expiry_date'     => ! empty($row['domain_expiry']) ? substr((string) $row['domain_expiry'], 0, 10) : null,
                    'name_servers'    => $row['nameServers'] ?? [],
                    'dns_config'      => isset($row['dnsConfig']) ? (int) $row['dnsConfig'] : null,
                    'transfer_status' => $row['transfer_status'] ?? null,
                ];

                if (isset($row['autoRenew'])) {
                    $attributes['auto_renew'] = in_array(strtolower((string) $row['autoRenew']), ['on', 'true', '1', 'yes'], true);
                }

                // Only refresh domains we already track; do not create new rows here.
                $domain = Domain::where('name', $row['domainName'])->first();
                if ($domain) {
                    $domain->fill(array_filter($attributes, static fn ($v) => $v !== null))->save();
                    $updated++;
                }
            }
        }

        if ($failedBatches > 0) {
            throw new RuntimeException("Synergy domain sync finished with {$failedBatches} failed batch(es); {$updated} domain(s) updated.");
        }
    }
}
