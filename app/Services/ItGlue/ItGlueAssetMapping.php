<?php

namespace App\Services\ItGlue;

use App\Models\Setting;
use App\Services\Assets\AssetEntity;

/**
 * Where each DomainDash data point goes in an IT Glue flexible asset type, per entity.
 *
 * Structure: `itglue.asset_mappings[entity]` = {enabled, flexible_asset_type_id, flexible_asset_type_name,
 * flexible_asset_traits: {point: trait name-key ('' = don't sync)}}. Domains read the historical top-level keys
 * (flexible_asset_type_id / flexible_asset_traits) until `asset_mappings.domain` has been saved, and stay enabled,
 * so existing installs keep working unchanged. Other entities are off until configured and have no default traits.
 */
final class ItGlueAssetMapping
{
    /**
     * @param array<string,string> $traits point => trait key ('' = skip)
     */
    public function __construct(
        public readonly string $entity,
        public readonly bool $enabled,
        public readonly ?int $typeId,
        public readonly string $typeName,
        public readonly array $traits,
    ) {
    }

    public static function forEntity(string $entity): self
    {
        $cfg = Setting::get('itglue', []);
        $cfg = is_array($cfg) ? $cfg : [];
        $stored = is_array($cfg['asset_mappings'][$entity] ?? null) ? $cfg['asset_mappings'][$entity] : null;

        if ($stored !== null) {
            return self::fromArray($stored, $entity);
        }

        return $entity === AssetEntity::DOMAIN ? self::fromArray($cfg, $entity) : self::fromArray([], $entity);
    }

    /** @return array<string,self> */
    public static function all(): array
    {
        $out = [];
        foreach (AssetEntity::KEYS as $entity) {
            $out[$entity] = self::forEntity($entity);
        }

        return $out;
    }

    public static function fromArray(array $cfg, string $entity = AssetEntity::DOMAIN): self
    {
        $isDomain = $entity === AssetEntity::DOMAIN;
        $enabled = array_key_exists('enabled', $cfg) ? filter_var($cfg['enabled'], FILTER_VALIDATE_BOOLEAN) : $isDomain;
        $typeId = isset($cfg['flexible_asset_type_id']) && is_numeric($cfg['flexible_asset_type_id']) && (int) $cfg['flexible_asset_type_id'] > 0
            ? (int) $cfg['flexible_asset_type_id']
            : null;
        $typeName = $typeId ? trim((string) ($cfg['flexible_asset_type_name'] ?? '')) : '';

        $points = AssetEntity::points($entity, 'itglue');
        if (is_array($cfg['flexible_asset_traits'] ?? null)) {
            $configured = $cfg['flexible_asset_traits'];
        } else {
            $configured = $isDomain ? ItGlueClient::DEFAULT_TRAITS : [];
        }

        $traits = [];
        foreach ($points as $point => $_label) {
            $traits[$point] = trim((string) ($configured[$point] ?? ''));
        }

        return new self($entity, $enabled, $typeId, $typeName, $traits);
    }

    public function active(): bool
    {
        return $this->enabled && $this->typeId !== null;
    }

    public function points(): array
    {
        return AssetEntity::points($this->entity, 'itglue');
    }

    public function typeLabel(): string
    {
        return $this->typeName !== '' ? $this->typeName : AssetEntity::label($this->entity);
    }

    public function mappedCount(): int
    {
        return count(array_filter($this->traits, fn ($key) => $key !== ''));
    }

    /**
     * Traits payload: {trait key: value} for every point that has a trait key. Empty values are left out so a
     * PATCH never blanks a trait with ''.
     *
     * @param array<string,string> $values point => value
     * @return array<string,string>
     */
    public function traitsFor(array $values): array
    {
        $out = [];
        foreach ($this->traits as $point => $key) {
            if ($key === '') {
                continue;
            }
            $value = $values[$point] ?? '';
            if ($value !== '' && $value !== null) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /** Everything that changes the pushed payload; part of the sync hash. */
    public function fingerprint(): array
    {
        return [$this->typeId, $this->typeName, $this->traits];
    }
}
