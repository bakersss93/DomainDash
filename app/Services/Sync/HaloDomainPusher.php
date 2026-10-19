<?php

namespace App\Services\Sync;

use App\Models\Domain;
use App\Models\HaloAsset;
use App\Services\Halo\HaloPsaClient;
use App\Support\WhoisFormatter;

/**
 * Pushes one DomainDash domain to Halo as a Domain asset. Create vs update is
 * decided from the local halo_assets mirror (no per-domain search call), and
 * unchanged payloads are skipped via domains.halo_sync_hash.
 */
class HaloDomainPusher
{
    public const MODE_ASSET = 'asset';
    public const MODE_DNS = 'dns';

    public function __construct(private SynergyDomainData $synergy)
    {
    }

    /**
     * @return string created|updated|skipped
     */
    public function push(Domain $domain, HaloPsaClient $halo, int $assetTypeId, bool $force = false, string $mode = self::MODE_ASSET): string
    {
        $client = $domain->client;
        if (! $client) {
            throw new SyncItemFailed('No client assigned');
        }
        if (! $client->halopsa_reference) {
            throw new SyncItemFailed('Client not linked to HaloPSA');
        }

        $haloClientId = (int) $client->halopsa_reference;

        if ($mode === self::MODE_DNS) {
            return $this->pushDnsOnly($domain, $halo, $haloClientId);
        }

        if (! $domain->expiry_date) {
            throw new SyncItemFailed('Missing expiry date');
        }

        $whoisData = $domain->whois_data ?? [];
        $overview = WhoisFormatter::overview($whoisData, $domain->name, $domain->whois_synced_at);
        $whoisNotes = WhoisFormatter::formatText($whoisData, $domain->name, $domain->whois_synced_at);
        $expiryDate = $domain->expiry_date instanceof \Illuminate\Support\Carbon
            ? $domain->expiry_date->toDateString()
            : (string) $domain->expiry_date;

        $nameservers = $domain->name_servers;
        if (empty($nameservers) && ! empty($overview['nameservers'])) {
            $nameservers = $overview['nameservers'];
        }

        // Hash of locally known state only: decides "nothing to push" before any remote call.
        $hash = sha1(json_encode([
            $domain->name, $expiryDate, $nameservers, $whoisNotes, $haloClientId, $assetTypeId,
        ]));

        if (! $force && $domain->halo_asset_id && $domain->halo_sync_hash === $hash) {
            return 'skipped';
        }

        // Synergy fallback only when local data is missing.
        if (empty($nameservers) || ! $overview['has_data']) {
            $fallback = $this->synergy->whois($domain->name);
            if (empty($nameservers)) {
                $nameservers = $fallback['nameservers'] ?? [];
            }
            if (! $overview['has_data'] && ! empty($fallback['formatted'])) {
                $whoisNotes = $fallback['formatted'];
            }
        }

        if (is_array($nameservers)) {
            $nameservers = implode(', ', array_filter($nameservers));
        }
        if (empty($nameservers)) {
            $nameservers = 'N/A'; // key_field3 is mandatory in Halo
        }

        [$siteId, $siteName] = $this->site($halo, $domain, $haloClientId);

        $assetData = [
            'client_id' => $haloClientId,
            'clientid' => $haloClientId,
            'site_id' => $siteId,
            'siteid' => $siteId,
            'site_name' => $siteName,
            'assettype_id' => $assetTypeId,
            'inventory_number' => $domain->name,
            'key_field' => $domain->name,
            'key_field2' => $expiryDate,
            'key_field3' => $nameservers,
            'fields' => [
                ['id' => 148, 'value' => $domain->name],
                ['id' => 149, 'value' => $expiryDate],
                ['id' => 150, 'value' => $nameservers],
                ['id' => 165, 'value' => $whoisNotes],
            ],
        ];

        $existingId = $this->resolveAssetId($domain, $halo, $haloClientId, $assetTypeId);

        if ($existingId) {
            // Notes are owned by the DomainDash block (see syncDomainAssetDns); do not overwrite them here.
            $result = $halo->updateAsset($existingId, $assetData);
            $action = 'updated';
        } else {
            $assetData['notes'] = $whoisNotes;
            $result = $halo->createDomainAsset($assetData);
            $action = 'created';
        }

        $assetId = (int) ($result['id'] ?? $result['Id'] ?? $existingId ?? 0);
        if ($assetId <= 0) {
            throw new SyncItemFailed('Failed to sync asset - no ID in response');
        }

        HaloAsset::updateOrCreate(['halo_id' => $assetId], [
            'halo_client_id' => $haloClientId,
            'assettype_id' => $assetTypeId,
            'assettype_name' => 'Domain',
            'name' => $domain->name,
            'inventory_number' => $domain->name,
            'synced_at' => now(),
        ]);

        $domain->forceFill([
            'halo_asset_id' => $assetId,
            'halo_sync_hash' => $hash,
            'halo_synced_at' => now(),
            'halo_sync_error' => null,
        ])->save();

        return $action;
    }

    private function pushDnsOnly(Domain $domain, HaloPsaClient $halo, int $haloClientId): string
    {
        $assetId = $domain->halo_asset_id ?: HaloAsset::query()
            ->where('halo_client_id', $haloClientId)
            ->whereRaw('lower(inventory_number) = ?', [strtolower($domain->name)])
            ->value('halo_id');

        if (! $assetId) {
            throw new SyncItemFailed('No HaloPSA asset linked to this domain');
        }

        $dns = $this->synergy->dnsRecords($domain->name);
        if ($dns === null) {
            throw new SyncItemFailed('DNS records could not be loaded from Synergy');
        }

        $halo->syncDomainAssetDns($domain, (int) $assetId, $dns);

        $domain->forceFill(['halo_synced_at' => now(), 'halo_sync_error' => null])->save();

        return 'updated';
    }

    private function resolveAssetId(Domain $domain, HaloPsaClient $halo, int $haloClientId, int $assetTypeId): ?int
    {
        if ($domain->halo_asset_id) {
            return (int) $domain->halo_asset_id;
        }

        $mirrored = HaloAsset::query()
            ->where('halo_client_id', $haloClientId)
            ->whereRaw('lower(inventory_number) = ?', [strtolower($domain->name)])
            ->value('halo_id');

        if ($mirrored) {
            return (int) $mirrored;
        }

        // Mirror never filled for this client: one live search beats creating a duplicate.
        if (! $domain->client->halo_synced_at) {
            $live = $halo->findAssetByInventory($haloClientId, $assetTypeId, $domain->name);
            $id = $live['id'] ?? $live['Id'] ?? null;

            return $id ? (int) $id : null;
        }

        return null;
    }

    /**
     * @return array{0:int|null,1:string}
     */
    private function site(HaloPsaClient $halo, Domain $domain, int $haloClientId): array
    {
        $client = $domain->client;

        if ($client->halo_default_site_id) {
            return [(int) $client->halo_default_site_id, 'Main'];
        }

        $site = $halo->getDefaultSiteForClient($haloClientId);
        if (! empty($site['site_id'])) {
            $client->forceFill(['halo_default_site_id' => (int) $site['site_id']])->save();
        }

        return [$site['site_id'] ?? null, $site['site_name'] ?? 'Main'];
    }
}
