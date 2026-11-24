<?php

namespace App\Services\Sync;

use App\Models\HaloAsset;
use App\Services\Assets\AssetEntity;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\Monitoring\IntegrationRequestLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Pushes one internet service / hosting service / SSL certificate to HaloPSA as an asset of the type mapped in
 * Settings → HaloPSA → Assets. (Domains keep HaloDomainPusher, which also owns WHOIS / DNS handling.)
 *
 * Documentation only: internet services are billed separately by HaloSubscriptionPusher.
 *
 * Create vs update comes from the record's halo_asset_id, then the local halo_assets mirror (client + type +
 * inventory number), then one live search by inventory number before a create; unchanged payloads are skipped
 * through the halo_asset_sync_hash column.
 */
class HaloEntityPusher
{
    /**
     * @return string created|updated|skipped
     */
    public function push(string $entity, Model $record, HaloPsaClient $halo, HaloAssetMapping $mapping, bool $force = false): string
    {
        return app(IntegrationRequestLogger::class)->withContext(
            ['entity_type' => $entity, 'reference' => AssetEntity::name($entity, $record)],
            fn () => $this->pushInner($entity, $record, $halo, $mapping, $force)
        );
    }

    /**
     * The payload that would be sent (no HTTP): shared by the push, the hash and tests.
     *
     * @return array{name:string,values:array,client_id:int,payload:array}
     */
    public function build(string $entity, Model $record, HaloAssetMapping $mapping): array
    {
        $client = $record->client;
        if (! $client) {
            throw new SyncItemFailed('No client assigned');
        }
        if (! $client->halopsa_reference) {
            throw new SyncItemFailed('Client not linked to HaloPSA');
        }
        if (! $mapping->typeId) {
            throw new SyncItemFailed('No HaloPSA asset type is mapped for ' . AssetEntity::plural($entity) . '.');
        }

        $haloClientId = (int) $client->halopsa_reference;
        $name = AssetEntity::name($entity, $record);
        $values = AssetEntity::values($entity, $record);

        $keys = [];
        foreach ($mapping->keyFieldPayload($values) as $prop => $value) {
            $keys[$prop] = (string) $value !== '' ? $value : 'N/A'; // Halo key fields are mandatory
        }

        return [
            'name' => $name,
            'values' => $values,
            'client_id' => $haloClientId,
            'payload' => [
                'client_id' => $haloClientId,
                'clientid' => $haloClientId,
                'assettype_id' => $mapping->typeId,
                'inventory_number' => $name,
                'fields' => $mapping->fields($values),
            ] + $keys,
        ];
    }

    private function pushInner(string $entity, Model $record, HaloPsaClient $halo, HaloAssetMapping $mapping, bool $force): string
    {
        $cols = AssetEntity::columns($entity, 'halo');
        $built = $this->build($entity, $record, $mapping);
        $haloClientId = $built['client_id'];

        $hash = sha1(json_encode([$entity, $built['name'], $built['values'], $haloClientId, $mapping->fingerprint()]));

        if (! $force && $record->{$cols['id']} && $record->{$cols['hash']} === $hash) {
            return 'skipped';
        }

        [$siteId, $siteName] = $this->site($halo, $record, $haloClientId);
        $payload = $built['payload'] + [
            'site_id' => $siteId,
            'siteid' => $siteId,
            'site_name' => $siteName,
        ];

        $existingId = $this->resolveAssetId($record, $cols, $halo, $haloClientId, $mapping->typeId, $built['name']);

        if ($existingId) {
            $result = $halo->updateAsset($existingId, $payload);
            $action = 'updated';
        } else {
            $result = $halo->createDomainAsset($payload); // generic POST /asset
            $action = 'created';
        }

        $assetId = (int) ($result['id'] ?? $result['Id'] ?? $existingId ?? 0);
        if ($assetId <= 0) {
            throw new SyncItemFailed('Failed to sync asset - no ID in response');
        }

        HaloAsset::updateOrCreate(['halo_id' => $assetId], [
            'halo_client_id' => $haloClientId,
            'assettype_id' => $mapping->typeId,
            'assettype_name' => $mapping->typeLabel(),
            'name' => $built['name'],
            'inventory_number' => $built['name'],
            'synced_at' => now(),
        ]);

        $record->forceFill([
            $cols['id'] => $assetId,
            $cols['hash'] => $hash,
            $cols['synced'] => now(),
            $cols['error'] => null,
        ])->save();

        return $action;
    }

    private function resolveAssetId(Model $record, array $cols, HaloPsaClient $halo, int $haloClientId, int $typeId, string $name): ?int
    {
        if ($record->{$cols['id']}) {
            return (int) $record->{$cols['id']};
        }

        $mirrored = HaloAsset::query()
            ->where('halo_client_id', $haloClientId)
            ->where('assettype_id', $typeId)
            ->whereRaw('lower(inventory_number) = ?', [strtolower($name)])
            ->value('halo_id');

        if ($mirrored) {
            return (int) $mirrored;
        }

        // The asset mirror only tracks the Domain type, so for other types one live search (a GET, rate limited)
        // beats creating a duplicate when the record lost its halo_asset_id.
        $live = $halo->findAssetByInventory($haloClientId, $typeId, $name);
        $id = $live['id'] ?? $live['Id'] ?? null;

        return $id ? (int) $id : null;
    }

    /**
     * @return array{0:int|null,1:string}
     */
    private function site(HaloPsaClient $halo, Model $record, int $haloClientId): array
    {
        $client = $record->client;

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
