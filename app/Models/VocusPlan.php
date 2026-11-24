<?php

namespace App\Models;

use App\Services\Vocus\VocusOptions;
use Illuminate\Database\Eloquent\Model;

class VocusPlan extends Model
{
    protected $fillable = ['plan_id', 'name', 'scopes', 'speed_tier', 'technology', 'mapping', 'download_mbps', 'upload_mbps', 'cost_price', 'sell_price', 'active', 'halo_item_id', 'halo_item_name'];

    protected $casts = [
        'scopes'     => 'array',
        'download_mbps' => 'integer',
        'upload_mbps'   => 'integer',
        'cost_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'active'     => 'boolean',
        'halo_item_id' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /** A plan with no scopes listed applies to every scope. */
    public function appliesToScope(?string $scope): bool
    {
        $scopes = (array) ($this->scopes ?? []);

        return $scopes === [] || $scope === null || in_array($scope, $scopes, true);
    }

    /**
     * Parse a free-text speed tier such as "100/20", "100/20 Mbps" or "1000/50" into [download, upload] Mbps.
     * Anything else (including values outside 1..10000) gives null.
     *
     * @return array{0:int,1:int}|null
     */
    public static function parseSpeedTier(?string $tier): ?array
    {
        if ($tier === null || ! preg_match('/^\s*(\d{1,5})\s*\/\s*(\d{1,5})\s*(?:mbps|mb\/s|mb|m)?\s*$/i', $tier, $m)) {
            return null;
        }
        [$down, $up] = [(int) $m[1], (int) $m[2]];

        return $down >= 1 && $down <= 10000 && $up >= 1 && $up <= 10000 ? [$down, $up] : null;
    }

    /**
     * Whether this plan is offered for a qualified technology and the account mapping. A plan with no technology /
     * mapping is offered everywhere; an unknown qualified technology (manual order, no qualification) does not filter
     * by technology.
     */
    public function offeredFor(?string $technology, ?string $mapping): bool
    {
        return $this->matchesTechnology($technology) && $this->matchesMapping($mapping);
    }

    public function matchesTechnology(?string $technology): bool
    {
        return $technology === null || $technology === '' || $this->technology === null || $this->technology === $technology;
    }

    public function matchesMapping(?string $mapping): bool
    {
        return $mapping === null || $mapping === '' || $this->mapping === null || $this->mapping === $mapping;
    }

    /** Home Fast / Super Fast / Ultra Fast / Hyper Fast tiers only support the STANDARD service level (Vocus sheet note). */
    public function isHomeTier(): bool
    {
        return str_starts_with(VocusOptions::tierKey($this->speed_tier), 'home');
    }

    /** Service levels this plan may be ordered with. */
    public function allowedServiceLevels(): array
    {
        return $this->isHomeTier() ? ['STANDARD'] : VocusOptions::SERVICE_LEVELS;
    }

    /**
     * High tiers not every address can take: HFC 250/100, Home Ultra Fast and Home Hyper Fast; FTTP Home Hyper Fast and
     * 2000/500 (Vocus sheet notes). The result of the service qualification decides, so the form shows a note.
     */
    public function needsSqCheck(): bool
    {
        $key = VocusOptions::tierKey($this->speed_tier);
        $tech = $this->technology;
        $named = in_array($key, ['homeultrafast', 'homehyperfast'], true);

        return match ($tech) {
            'HFC'   => $named || $key === '250100' || ($this->download_mbps === 250 && $this->upload_mbps === 100),
            'FTTP'  => $key === 'homehyperfast' || $key === '2000500' || ($this->download_mbps === 2000 && $this->upload_mbps === 500),
            null    => $named,
            default => false,
        };
    }

    /** True when neither speed is known (such a plan is never hidden by line capability). */
    public function speedUnknown(): bool
    {
        return $this->download_mbps === null && $this->upload_mbps === null;
    }

    public function getMarginAttribute(): string
    {
        return number_format((float) $this->sell_price - (float) $this->cost_price, 2, '.', '');
    }

    /** Shape used by the settings modal and the order form (no secrets here). */
    public function toFormArray(): array
    {
        return [
            'id'         => $this->id,
            'plan_id'    => $this->plan_id,
            'name'       => $this->name,
            'scopes'     => array_values((array) ($this->scopes ?? [])),
            'speed_tier' => $this->speed_tier,
            'technology' => $this->technology,
            'mapping'    => $this->mapping,
            'download_mbps' => $this->download_mbps,
            'upload_mbps'   => $this->upload_mbps,
            'cost_price' => (string) $this->cost_price,
            'sell_price' => (string) $this->sell_price,
            'active'     => (bool) $this->active,
            'halo_item_id'   => $this->halo_item_id ? (int) $this->halo_item_id : null,
            'halo_item_name' => $this->halo_item_name,
        ];
    }
}
