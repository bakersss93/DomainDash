<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Assets\AssetEntity;
use App\Services\AuditLogger;
use App\Services\Halo\HaloAssetMapping;
use App\Services\ItGlue\ItGlueAssetMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * JSON CRUD behind the Assets tabs of Settings → HaloPSA / IT Glue (<x-ui.mapping-table> in remote mode).
 *
 *   GET    /admin/settings/asset-mappings/{provider}            one row per DomainDash entity (always all four)
 *   POST   /admin/settings/asset-mappings/{provider}            create/replace the mapping of body.entity
 *   PUT    /admin/settings/asset-mappings/{provider}/{entity}   update it
 *   DELETE /admin/settings/asset-mappings/{provider}/{entity}   reset it (domains fall back to the legacy keys)
 *
 * {provider} = halo | itglue, {entity} = domain | internet_service | hosting_service | ssl_certificate.
 *
 * Stored in `halo.asset_mappings[entity]` / `itglue.asset_mappings[entity]` (see HaloAssetMapping /
 * ItGlueAssetMapping). Saving the domain mapping also mirrors the historical top-level keys, so a rollback keeps
 * working. Administrator area only; every write is audited with ids and names (no secrets).
 */
class AssetMappingController extends Controller
{
    public function index(Request $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);

        $rows = array_map(fn (string $entity) => $this->row($provider, $entity), AssetEntity::KEYS);

        return response()->json(['rows' => $rows, 'total' => count($rows), 'page' => 1, 'per_page' => count($rows)]);
    }

    public function store(Request $request, string $provider): JsonResponse
    {
        $this->assertProvider($provider);
        $entity = (string) $request->input('entity', '');
        abort_unless(AssetEntity::valid($entity), 422, 'Choose what to map.');

        return $this->save($request, $provider, $entity, 201);
    }

    public function update(Request $request, string $provider, string $entity): JsonResponse
    {
        $this->assertProvider($provider);
        $this->assertEntity($entity);

        return $this->save($request, $provider, $entity, 200);
    }

    public function destroy(Request $request, string $provider, string $entity): JsonResponse
    {
        $this->assertProvider($provider);
        $this->assertEntity($entity);

        $setting = $this->setting($provider);
        $cfg = $this->readSetting($setting);
        $maps = is_array($cfg['asset_mappings'] ?? null) ? $cfg['asset_mappings'] : [];
        $old = $maps[$entity] ?? null;

        if ($entity === AssetEntity::DOMAIN) {
            // Reset means "not configured": turn the domain mapping off and forget the type and fields, including
            // the legacy keys, so the row shows as not configured instead of silently reverting.
            $maps[$entity] = ['enabled' => false];
            foreach ($provider === 'halo'
                ? ['asset_type_id', 'asset_type_name', 'asset_field_ids', 'asset_field_names', 'asset_key_fields']
                : ['flexible_asset_type_id', 'flexible_asset_type_name', 'flexible_asset_traits'] as $legacy) {
                unset($cfg[$legacy]);
            }
        } else {
            unset($maps[$entity]);
        }
        $cfg['asset_mappings'] = $maps;
        Setting::put($setting, $cfg);
        $this->audit($provider, $entity, 'reset', is_array($old) ? $old : []);

        return response()->json(['row' => $this->row($provider, $entity), 'deleted' => true]);
    }

    // ------------------------------------------------------------------

    private function save(Request $request, string $provider, string $entity, int $status): JsonResponse
    {
        $points = AssetEntity::points($entity, $provider);
        $halo = $provider === 'halo';

        $data = $request->validate([
            'enabled' => 'nullable|boolean',
            'asset_type' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'asset_type_label' => 'nullable|string|max:180',
            'fields' => 'nullable|array',
            'fields.*' => 'nullable|string|max:120',
            'fields_label' => 'nullable|array',
            'fields_label.*' => 'nullable|string|max:180',
            'key_fields' => 'nullable|array',
            'key_fields.*' => 'nullable|string|max:60',
        ]);

        $typeId = (int) ($data['asset_type'] ?? 0);
        $enabled = (bool) ($data['enabled'] ?? false);
        if ($enabled && $typeId <= 0 && $entity !== AssetEntity::DOMAIN) {
            throw ValidationException::withMessages(['asset_type' => ['Choose an asset type before turning this mapping on.']]);
        }

        $fieldIds = [];
        $fieldNames = [];
        $traits = [];
        foreach ((array) ($data['fields'] ?? []) as $point => $value) {
            if (! array_key_exists($point, $points)) {
                throw ValidationException::withMessages(['fields' => ['Unknown DomainDash value: ' . mb_substr((string) $point, 0, 60)]]);
            }
            $value = trim((string) $value);
            if ($halo) {
                if ($value !== '' && ! ctype_digit($value)) {
                    throw ValidationException::withMessages(['fields' => ['Field ids must be numbers.']]);
                }
                // '' = "don't sync" for non-domain entities; domains keep "blank = built-in default" semantics.
                $fieldIds[$point] = $value === '' ? 0 : (int) $value;
                $label = trim((string) ($data['fields_label'][$point] ?? ''));
                if ($fieldIds[$point] > 0 && $label !== '') {
                    $fieldNames[$point] = mb_substr($label, 0, 180);
                }
            } else {
                if ($value !== '' && ! preg_match('/^[a-z0-9_-]+$/', $value)) {
                    throw ValidationException::withMessages(['fields' => ['Trait keys may only contain lowercase letters, numbers, dashes and underscores.']]);
                }
                $traits[$point] = $value;
            }
        }

        $item = ['enabled' => $enabled];
        if ($halo) {
            $keyFields = [];
            foreach ([1, 2, 3] as $slot) {
                $point = (string) ($data['key_fields'][$slot] ?? $data['key_fields'][(string) $slot] ?? '');
                if ($point !== '' && ! array_key_exists($point, $points)) {
                    throw ValidationException::withMessages(['key_fields' => ['Unknown DomainDash value for key field ' . $slot . '.']]);
                }
                $keyFields[$slot] = $point;
            }
            $item += [
                'asset_type_id' => $typeId > 0 ? $typeId : null,
                'asset_type_name' => $typeId > 0 ? trim((string) ($data['asset_type_label'] ?? '')) : '',
                'asset_field_ids' => $fieldIds,
                'asset_field_names' => $fieldNames,
                'asset_key_fields' => $keyFields,
            ];
        } else {
            $item += [
                'flexible_asset_type_id' => $typeId > 0 ? $typeId : null,
                'flexible_asset_type_name' => $typeId > 0 ? trim((string) ($data['asset_type_label'] ?? '')) : '',
                'flexible_asset_traits' => $traits,
            ];
        }

        $setting = $this->setting($provider);
        $cfg = $this->readSetting($setting);
        $maps = is_array($cfg['asset_mappings'] ?? null) ? $cfg['asset_mappings'] : [];
        $maps[$entity] = $item;
        $cfg['asset_mappings'] = $maps;

        if ($entity === AssetEntity::DOMAIN) {
            // Mirror the historical keys (rollback safety; the pushers read asset_mappings.domain first).
            foreach ($item as $key => $value) {
                if ($key !== 'enabled') {
                    $cfg[$key] = $value;
                }
            }
        }

        Setting::put($setting, $cfg);
        $this->audit($provider, $entity, $status === 201 ? 'added' : 'updated', $item);

        return response()->json(['row' => $this->row($provider, $entity)], $status);
    }

    /** One mapping-table row (cells for the table, values for the edit dialog). */
    private function row(string $provider, string $entity): array
    {
        $halo = $provider === 'halo';
        $mapping = $halo ? HaloAssetMapping::forEntity($entity) : ItGlueAssetMapping::forEntity($entity);
        $points = AssetEntity::points($entity, $provider);
        $label = AssetEntity::label($entity);

        $typeLabel = $mapping->typeId
            ? ($mapping->typeName !== '' ? $mapping->typeName : 'Asset type #' . $mapping->typeId)
            : '';
        $mapped = $mapping->mappedCount();

        $map = [];
        foreach ($points as $point => $pointLabel) {
            if ($halo) {
                $id = (int) ($mapping->fieldIds[$point] ?? 0);
                $map[$point] = $id > 0
                    ? ['id' => (string) $id, 'label' => ($mapping->fieldNames[$point] ?? '') !== '' ? $mapping->fieldNames[$point] : 'Field #' . $id]
                    : ['id' => '', 'label' => ''];
            } else {
                $key = (string) ($mapping->traits[$point] ?? '');
                $map[$point] = ['id' => $key, 'label' => $key];
            }
        }

        $notConfigured = $entity === AssetEntity::DOMAIN && $halo ? 'Looked up by name (Domain)' : 'Not configured';

        return [
            'id' => $entity,
            'cells' => [
                'entity' => $label,
                'asset_type' => $typeLabel !== '' ? $typeLabel : $notConfigured,
                'fields' => $mapped . ' of ' . count($points) . ' mapped',
                'enabled' => $mapping->enabled ? 'Enabled' : 'Off',
            ],
            'values' => [
                'entity' => ['id' => $entity, 'label' => $label],
                'asset_type' => ['id' => $mapping->typeId ? (string) $mapping->typeId : '', 'label' => $typeLabel],
                'enabled' => $mapping->enabled ? '1' : '0',
                'fields' => [
                    'entity' => $entity,
                    'points' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($points), array_values($points)),
                    'map' => $map,
                    'key_fields' => $halo ? array_map('strval', $mapping->keyFields) : null,
                ],
            ],
        ];
    }

    private function setting(string $provider): string
    {
        return $provider === 'halo' ? 'halo' : 'itglue';
    }

    private function readSetting(string $setting): array
    {
        $cfg = Setting::get($setting, []);

        return is_array($cfg) ? $cfg : [];
    }

    private function assertProvider(string $provider): void
    {
        abort_unless(in_array($provider, AssetEntity::PROVIDERS, true), 404);
    }

    private function assertEntity(string $entity): void
    {
        abort_unless(AssetEntity::valid($entity), 404);
    }

    private function audit(string $provider, string $entity, string $what, array $detail): void
    {
        AuditLogger::logSystem('settings.mapping.' . $what, "Asset mapping {$provider}/{$entity} {$what}.", [
            'service' => 'settings',
            'function' => "asset-mapping-{$provider}-{$entity}",
        ], ['new_values' => ['mapping' => "{$provider}.asset-mappings.{$entity}", 'item' => $detail]]);
    }
}
