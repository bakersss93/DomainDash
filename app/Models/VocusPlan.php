<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VocusPlan extends Model
{
    protected $fillable = ['plan_id', 'name', 'scopes', 'speed_tier', 'download_mbps', 'upload_mbps', 'cost_price', 'sell_price', 'active'];

    protected $casts = [
        'scopes'     => 'array',
        'download_mbps' => 'integer',
        'upload_mbps'   => 'integer',
        'cost_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'active'     => 'boolean',
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
            'download_mbps' => $this->download_mbps,
            'upload_mbps'   => $this->upload_mbps,
            'cost_price' => (string) $this->cost_price,
            'sell_price' => (string) $this->sell_price,
            'active'     => (bool) $this->active,
        ];
    }
}
