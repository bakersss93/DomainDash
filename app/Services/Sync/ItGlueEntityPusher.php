<?php

namespace App\Services\Sync;

use App\Services\Assets\AssetEntity;
use App\Services\Integrations\IntegrationApiException;
use App\Services\ItGlue\ItGlueAssetMapping;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Monitoring\IntegrationRequestLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Pushes one internet service / hosting service / SSL certificate to IT Glue as a flexible asset of the type mapped
 * in Settings → IT Glue → Assets (domains keep ItGlueDomainPusher). Create vs update by itglue_id, then a lookup by
 * organisation + type + name; unchanged payloads are skipped through itglue_sync_hash.
 */
class ItGlueEntityPusher
{
    /**
     * @param bool $listed the caller knows an asset without itglue_id does not exist (skips the find call)
     * @return string created|updated|skipped
     */
    public function push(string $entity, Model $record, ItGlueClient $itglue, ItGlueAssetMapping $mapping, bool $force = false, bool $listed = false): string
    {
        return app(IntegrationRequestLogger::class)->withContext(
            ['entity_type' => $entity, 'reference' => AssetEntity::name($entity, $record)],
            fn () => $this->pushInner($entity, $record, $itglue, $mapping, $force, $listed)
        );
    }

    /**
     * @return array{name:string,org_id:int,traits:array<string,string>,values:array}
     */
    public function build(string $entity, Model $record, ItGlueAssetMapping $mapping): array
    {
        $client = $record->client;
        if (! $client || ! $client->itglue_org_id) {
            throw new SyncItemFailed(ucfirst(AssetEntity::singular($entity)) . ' is not linked to an IT Glue organization');
        }
        if (! $mapping->typeId) {
            throw new SyncItemFailed('No IT Glue flexible asset type is mapped for ' . AssetEntity::plural($entity) . '.');
        }

        $values = AssetEntity::values($entity, $record);

        return [
            'name' => AssetEntity::name($entity, $record),
            'org_id' => (int) $client->itglue_org_id,
            'traits' => $mapping->traitsFor($values),
            'values' => $values,
        ];
    }

    private function pushInner(string $entity, Model $record, ItGlueClient $itglue, ItGlueAssetMapping $mapping, bool $force, bool $listed): string
    {
        $cols = AssetEntity::columns($entity, 'itglue');
        $built = $this->build($entity, $record, $mapping);

        $hash = sha1(json_encode([$entity, $built['name'], $built['traits'], $built['org_id'], $mapping->fingerprint()]));

        if (! $force && $record->{$cols['id']} && $record->{$cols['hash']} === $hash) {
            return 'skipped';
        }

        $typeId = (int) $mapping->typeId;

        if ($record->{$cols['id']}) {
            try {
                $result = $itglue->syncAsset($typeId, $built['org_id'], $built['name'], $built['traits'], ['id' => (int) $record->{$cols['id']}]);
            } catch (IntegrationApiException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
                // Deleted in IT Glue: look it up by name, create if truly absent.
                $result = $itglue->syncAsset($typeId, $built['org_id'], $built['name'], $built['traits']);
            }
        } else {
            $result = $itglue->syncAsset($typeId, $built['org_id'], $built['name'], $built['traits'], $listed ? false : null);
        }

        $update = [
            $cols['hash'] => $hash,
            $cols['synced'] => now(),
            $cols['error'] => null,
        ];
        if (! empty($result['flexible_asset_id'])) {
            $update[$cols['id']] = $result['flexible_asset_id'];
        }
        $record->forceFill($update)->save();

        return $result['action'] ?? 'updated';
    }
}
