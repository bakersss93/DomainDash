<?php

namespace App\Services\Halo;

use App\Models\Setting;

/**
 * Where each DomainDash data point goes in the HaloPSA asset type.
 *
 * Stored inside the existing `halo` setting (no migration):
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
    ) {
    }

    public static function fromSettings(): self
    {
        $halo = Setting::get('halo', []);

        return self::fromArray(is_array($halo) ? $halo : []);
    }

    public static function fromArray(array $halo): self
    {
        $typeId = isset($halo['asset_type_id']) && is_numeric($halo['asset_type_id']) && (int) $halo['asset_type_id'] > 0
            ? (int) $halo['asset_type_id']
            : null;
        $typeName = $typeId ? trim((string) ($halo['asset_type_name'] ?? '')) : '';

        $configured = is_array($halo['asset_field_ids'] ?? null) ? $halo['asset_field_ids'] : [];
        $names = is_array($halo['asset_field_names'] ?? null) ? $halo['asset_field_names'] : [];
        $ids = [];
        $fieldNames = [];
        foreach (self::DEFAULT_FIELD_IDS as $point => $default) {
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

        $keys = self::DEFAULT_KEY_FIELDS;
        if (is_array($halo['asset_key_fields'] ?? null)) {
            $keys = [];
            foreach ([1, 2, 3] as $slot) {
                $point = (string) ($halo['asset_key_fields'][$slot] ?? $halo['asset_key_fields'][(string) $slot] ?? '');
                $keys[$slot] = array_key_exists($point, self::POINTS) ? $point : '';
            }
        }

        return new self($typeId, $typeName, $ids, $fieldNames, $keys);
    }

    public function typeLabel(): string
    {
        return $this->typeName !== '' ? $this->typeName : 'Domain';
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
        foreach (self::POINTS as $point => $_) {
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
