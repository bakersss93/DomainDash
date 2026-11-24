<?php

namespace App\Services\Halo;

use App\Models\Setting;
use App\Services\Assets\AssetEntity;

/**
 * Where each DomainDash data point goes in the HaloPSA asset type, per entity (domains, internet services, hosting,
 * SSL certificates).
 *
 * Structure: `halo.asset_mappings[entity]` holds the keys below plus `enabled`. Domains keep reading the historical
 * top-level keys when `asset_mappings.domain` has never been saved, so existing installs migrate without a data
 * migration (domains stay enabled, exactly as before). Other entities are off until configured.
 *
 * Keys (identical at top level for the legacy domain mapping and inside an entity mapping):
 *   asset_type_id / asset_type_name  the configured asset type (empty = legacy lookup by name "Domain")
 *   asset_field_ids[point]           Halo field id; 0 = "don't sync"; empty/invalid = built-in default
 *   asset_field_names[point]         field name shown in the picker (sent alongside the id)
 *   asset_key_fields[1..3]           which data point feeds Device.key_field / key_field2 / key_field3
 *                                    ('' = leave empty); absent = legacy domain / expiry / nameservers
 */
final class HaloAssetMapping
{
    /** data point => label */
    public const POINTS = [
        'domain' => 'Domain name',
        'expiry' => 'Expiry date',
        'nameservers' => 'Name servers',
        'whois' => 'WHOIS / registrant summary',
    ];

    public const DEFAULT_FIELD_IDS = ['domain' => 148, 'expiry' => 149, 'nameservers' => 150, 'whois' => 165];

    public const DEFAULT_KEY_FIELDS = [1 => 'domain', 2 => 'expiry', 3 => 'nameservers'];

    /**
     * @param array<string,int>    $fieldIds    0 = skip
     * @param array<string,string> $fieldNames
     * @param array<int,string>    $keyFields   slot => data point ('' = none)
     */
    public function __construct(
        public readonly ?int $typeId,
        public readonly string $typeName,
        public readonly array $fieldIds,
        public readonly array $fieldNames,
        public readonly array $keyFields,
        public readonly string $entity = AssetEntity::DOMAIN,
        public readonly bool $enabled = true,
    ) {
    }

    /** The domain mapping (what every domain pusher and the asset mirror use). */
    public static function fromSettings(): self
    {
        return self::forEntity(AssetEntity::DOMAIN);
    }

    public static function forEntity(string $entity): self
    {
        $halo = Setting::get('halo', []);
        $halo = is_array($halo) ? $halo : [];
        $stored = is_array($halo['asset_mappings'][$entity] ?? null) ? $halo['asset_mappings'][$entity] : null;

        if ($stored !== null) {
            return self::fromArray($stored, $entity);
        }

        // Legacy install: the single domain mapping lives in the top-level keys.
        return $entity === AssetEntity::DOMAIN ? self::fromArray($halo, $entity) : self::fromArray([], $entity);
    }

    /** @return array<string,self> every entity => its mapping (configured or not) */
    public static function all(): array
    {
        $out = [];
        foreach (AssetEntity::KEYS as $entity) {
            $out[$entity] = self::forEntity($entity);
        }

        return $out;
    }

    /** Ready to push: enabled and a type chosen (domains may still fall back to the type named "Domain"). */
    public function active(): bool
    {
        return $this->enabled && ($this->typeId !== null || $this->entity === AssetEntity::DOMAIN);
    }

    public function points(): array
    {
        return AssetEntity::points($this->entity, 'halo');
    }

    public static function fromArray(array $halo, string $entity = AssetEntity::DOMAIN): self
    {
        $isDomain = $entity === AssetEntity::DOMAIN;
        $points = AssetEntity::points($entity, 'halo');
        $enabled = array_key_exists('enabled', $halo) ? filter_var($halo['enabled'], FILTER_VALIDATE_BOOLEAN) : $isDomain;

        $typeId = isset($halo['asset_type_id']) && is_numeric($halo['asset_type_id']) && (int) $halo['asset_type_id'] > 0
            ? (int) $halo['asset_type_id']
            : null;
        $typeName = $typeId ? trim((string) ($halo['asset_type_name'] ?? '')) : '';

        $configured = is_array($halo['asset_field_ids'] ?? null) ? $halo['asset_field_ids'] : [];
        $names = is_array($halo['asset_field_names'] ?? null) ? $halo['asset_field_names'] : [];
        $ids = [];
        $fieldNames = [];
        foreach ($points as $point => $_label) {
            $default = $isDomain ? (self::DEFAULT_FIELD_IDS[$point] ?? 0) : 0;
            $value = $configured[$point] ?? null;
            $fromConfig = true;
            if (is_numeric($value) && (int) $value === 0) {
                $ids[$point] = 0; // explicit "don't sync"
            } elseif (is_numeric($value) && (int) $value > 0) {
                $ids[$point] = (int) $value;
            } else {
                $ids[$point] = $default;
                $fromConfig = false;
            }
            $name = trim((string) ($names[$point] ?? ''));
            if ($fromConfig && $ids[$point] > 0 && $name !== '') {
                $fieldNames[$point] = mb_substr($name, 0, 180);
            }
        }

        $keys = AssetEntity::haloKeyDefaults($entity);
        if (is_array($halo['asset_key_fields'] ?? null)) {
            $keys = [];
            foreach ([1, 2, 3] as $slot) {
                $point = (string) ($halo['asset_key_fields'][$slot] ?? $halo['asset_key_fields'][(string) $slot] ?? '');
                $keys[$slot] = array_key_exists($point, $points) ? $point : '';
            }
        }

        return new self($typeId, $typeName, $ids, $fieldNames, $keys, $entity, $enabled);
    }

    public function typeLabel(): string
    {
        return $this->typeName !== '' ? $this->typeName : ($this->entity === AssetEntity::DOMAIN ? 'Domain' : AssetEntity::label($this->entity));
    }

    /** How many data points are sent to a Halo field (the rest are "don't sync"). */
    public function mappedCount(): int
    {
        return count(array_filter($this->fieldIds, fn ($id) => $id > 0));
    }

    /**
     * Halo "Asset Fields" entries (Device.fields[]): {id, name?, value}. Data
     * points mapped to "don't sync" are omitted.
     *
     * @param array<string,mixed> $values data point => value
     * @return array<int,array<string,mixed>>
     */
    public function fields(array $values): array
    {
        $out = [];
        foreach ($this->points() as $point => $_) {
            $id = $this->fieldIds[$point] ?? 0;
            if ($id <= 0) {
                continue;
            }
            $entry = ['id' => $id];
            if (($this->fieldNames[$point] ?? '') !== '') {
                $entry['name'] = $this->fieldNames[$point];
            }
            $entry['value'] = $values[$point] ?? null;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Device.key_field / key_field2 / key_field3.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function keyFieldPayload(array $values): array
    {
        $out = [];
        foreach ([1 => 'key_field', 2 => 'key_field2', 3 => 'key_field3'] as $slot => $prop) {
            $point = $this->keyFields[$slot] ?? '';
            if ($point !== '' && array_key_exists($point, $values)) {
                $out[$prop] = $values[$point];
            }
        }

        return $out;
    }

    /** Everything that changes the pushed payload; part of the sync hash so a change re-pushes. */
    public function fingerprint(): array
    {
        return [$this->typeId, $this->typeName, $this->fieldIds, $this->fieldNames, $this->keyFields];
    }
}
