<?php

namespace App\Services\Sync;

use App\Models\Domain;
use App\Models\HaloAsset;
use App\Services\Halo\HaloAssetMapping;
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
        $mapping = HaloAssetMapping::fromSettings();
        $hash = sha1(json_encode([
            $domain->name, $expiryDate, $nameservers, $whoisNotes, $haloClientId, $assetTypeId, $mapping->fingerprint(),
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

        $values = [
            'domain' => $domain->name,
            'expiry' => $expiryDate,
            'nameservers' => $nameservers,
            'whois' => $whoisNotes,
        ];

        $assetData = [
            'client_id' => $haloClientId,
            'clientid' => $haloClientId,
            'site_id' => $siteId,
            'siteid' => $siteId,
            'site_name' => $siteName,
            'assettype_id' => $assetTypeId,
            'inventory_number' => $domain->name,
            // Field ids/names and key fields come from Settings → HaloPSA → Domain asset type.
            'fields' => $mapping->fields($values),
        ] + $mapping->keyFieldPayload($values);

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
            'assettype_name' => $mapping->typeLabel(),
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

    /**
     * Dry run for Settings → HaloPSA "Test mapping": the values and Halo
     * targets that WOULD be sent for this domain. Makes no HTTP call (no site
     * lookup, no Synergy fallback) and writes nothing.
     *
     * @return array{asset_type_id:?int,asset_type_name:string,rows:array,key_fields:array,payload:array,warnings:array}
     */
    public function preview(Domain $domain, HaloAssetMapping $mapping): array
    {
        $whoisData = $domain->whois_data ?? [];
        $overview = WhoisFormatter::overview($whoisData, $domain->name, $domain->whois_synced_at);
        $expiry = $domain->expiry_date instanceof \Illuminate\Support\Carbon
            ? $domain->expiry_date->toDateString()
            : ($domain->expiry_date ? (string) $domain->expiry_date : '');
        $nameservers = $domain->name_servers;
        if (empty($nameservers) && ! empty($overview['nameservers'])) {
            $nameservers = $overview['nameservers'];
        }
        $nameservers = is_array($nameservers) ? implode(', ', array_filter($nameservers)) : (string) $nameservers;

        $warnings = [];
        if ($expiry === '') {
            $warnings[] = 'This domain has no expiry date, so a real sync would fail ("Missing expiry date").';
        }
        if ($nameservers === '') {
            $nameservers = 'N/A';
            $warnings[] = 'No name servers stored locally; a real sync falls back to Synergy, then "N/A".';
        }
        if (! $overview['has_data']) {
            $warnings[] = 'No WHOIS data stored locally; a real sync may fall back to Synergy.';
        }

        $values = [
            'domain' => $domain->name,
            'expiry' => $expiry,
            'nameservers' => $nameservers,
            'whois' => WhoisFormatter::formatText($whoisData, $domain->name, $domain->whois_synced_at),
        ];

        $fields = $mapping->fields($values);
        $rows = [];
        foreach (HaloAssetMapping::POINTS as $point => $label) {
            $id = $mapping->fieldIds[$point] ?? 0;
            $rows[] = [
                'point' => $point,
                'label' => $label,
                'field_id' => $id > 0 ? $id : null,
                'field_name' => $mapping->fieldNames[$point] ?? null,
                'value' => $id > 0 ? mb_strimwidth((string) $values[$point], 0, 400, '…') : null,
                'synced' => $id > 0,
            ];
        }

        $keyFields = [];
        foreach ($mapping->keyFieldPayload($values) as $prop => $value) {
            $keyFields[] = ['property' => $prop, 'value' => mb_strimwidth((string) $value, 0, 200, '…')];
        }

        return [
            'asset_type_id' => $mapping->typeId,
            'asset_type_name' => $mapping->typeLabel(),
            'rows' => $rows,
            'key_fields' => $keyFields,
            'payload' => [
                'assettype_id' => $mapping->typeId,
                'inventory_number' => $domain->name,
                'fields' => array_map(function (array $f) {
                    $f['value'] = is_string($f['value']) ? mb_strimwidth($f['value'], 0, 400, '…') : $f['value'];

                    return $f;
                }, $fields),
            ] + array_map(fn ($v) => mb_strimwidth((string) $v, 0, 200, '…'), $mapping->keyFieldPayload($values)),
            'warnings' => $warnings,
        ];
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
