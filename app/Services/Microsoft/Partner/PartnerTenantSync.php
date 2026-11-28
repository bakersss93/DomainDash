<?php

namespace App\Services\Microsoft\Partner;

use App\Models\PartnerCustomerTenant;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Mirrors the partner's customer tenants into partner_customer_tenants and audits the run (partner.sync.*). */
class PartnerTenantSync
{
    public function __construct(private readonly PartnerGraphClient $graph)
    {
    }

    /**
     * @return array{total:int,created:int,updated:int,not_seen:int}
     *
     * @throws PartnerGraphException
     */
    public function run(bool $automated = false): array
    {
        $source = PartnerSettings::source();
        $ctx = ['function' => 'partner-sync', 'automated' => $automated, 'source' => $source];

        AuditLogger::logSystem('partner.sync.started', 'Microsoft Partner customer sync started.', $ctx);

        try {
            $customers = $this->graph->listCustomers($source);
        } catch (PartnerGraphException $e) {
            AuditLogger::logSystem('partner.sync.failed', 'Microsoft Partner customer sync failed.', $ctx, [
                'new_values' => ['reason' => $e->reason, 'error' => $e->getMessage()],
            ]);

            throw $e;
        }

        $started = now();
        $created = $updated = 0;

        DB::transaction(function () use ($customers, $started, &$created, &$updated) {
            // Everything starts as "not seen"; each tenant found below flips back to active. A mapping must survive
            // a customer dropping out of the list, so rows are kept and only flagged.
            PartnerCustomerTenant::query()->update(['relationship_status' => 'not_seen']);

            foreach ($customers as $c) {
                $row = PartnerCustomerTenant::query()->where('tenant_id', $c['tenant_id'])->first() ?? new PartnerCustomerTenant(['tenant_id' => $c['tenant_id']]);
                $isNew = ! $row->exists;

                $domains = array_values(array_unique(array_filter(array_merge((array) $row->domains, [$c['default_domain']]))));
                $row->fill([
                    'display_name' => $c['display_name'] ?? $row->display_name,
                    // A source without domains (delegatedAdminCustomers) must not erase one learned earlier.
                    'default_domain' => $c['default_domain'] ?? $row->default_domain,
                    'domains' => $domains === [] ? null : $domains,
                    'relationship_status' => 'active',
                    'source' => $c['source'],
                    'last_seen_at' => $started,
                ]);
                $row->save();
                $isNew ? $created++ : $updated++;
            }
        });

        $stats = [
            'total' => count($customers),
            'created' => $created,
            'updated' => $updated,
            'not_seen' => PartnerCustomerTenant::query()->where('relationship_status', 'not_seen')->count(),
        ];

        AuditLogger::logSystem('partner.sync.completed', "Microsoft Partner customer sync completed ({$stats['total']} tenants).", $ctx, ['new_values' => $stats]);

        return $stats;
    }
}
