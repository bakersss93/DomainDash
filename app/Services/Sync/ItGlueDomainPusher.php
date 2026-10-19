<?php

namespace App\Services\Sync;

use App\Models\Domain;
use App\Services\Integrations\IntegrationApiException;
use App\Services\ItGlue\ItGlueClient;

/**
 * Pushes one DomainDash domain to IT Glue as a flexible asset.
 */
class ItGlueDomainPusher
{
    public function __construct(private SynergyDomainData $synergy)
    {
    }

    /**
     * @param bool $listed the caller already listed this org's assets, so a
     *                     domain without itglue_id is known not to exist
     * @return string created|updated|skipped
     */
    public function push(Domain $domain, ItGlueClient $itglue, bool $force = false, bool $listed = false): string
    {
        $client = $domain->client;
        if (! $client || ! $client->itglue_org_id) {
            throw new SyncItemFailed('Domain is not linked to an ITGlue organization');
        }

        $orgId = (int) $client->itglue_org_id;
        $typeId = $itglue->configuredFlexibleAssetTypeId();

        // Local state + calendar day: unchanged domains cost zero calls, DNS
        // (fetched at push time) is refreshed at most once a day.
        $hash = sha1(json_encode([
            $domain->name,
            $domain->expiry_date?->toDateString(),
            $domain->name_servers,
            $domain->whois_data,
            $domain->whois_synced_at?->toIso8601String(),
            $domain->dns_config,
            $orgId,
            $typeId,
            now()->toDateString(),
        ]));

        if (! $force && $domain->itglue_id && $domain->itglue_sync_hash === $hash) {
            return 'skipped';
        }

        $dnsRecords = $this->synergy->dnsRecords($domain->name);

        if ($domain->itglue_id) {
            try {
                $result = $itglue->syncDomain($domain, $orgId, $dnsRecords, ['id' => (int) $domain->itglue_id]);
            } catch (IntegrationApiException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
                // Asset was deleted in IT Glue: look it up by name, create if truly absent.
                $result = $itglue->syncDomain($domain, $orgId, $dnsRecords);
            }
        } else {
            $result = $itglue->syncDomain($domain, $orgId, $dnsRecords, $listed ? false : null);
        }

        $update = [
            'itglue_sync_hash' => $hash,
            'itglue_synced_at' => now(),
            'itglue_sync_error' => null,
        ];
        if (! empty($result['flexible_asset_id'])) {
            $update['itglue_id'] = $result['flexible_asset_id'];
        }
        $domain->forceFill($update)->save();

        return $result['action'] ?? 'updated';
    }
}
