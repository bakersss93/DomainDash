<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VocusPlan extends Model
{
    protected $fillable = ['plan_id', 'name', 'scopes', 'speed_tier', 'cost_price', 'sell_price', 'active'];

    protected $casts = [
        'scopes'     => 'array',
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
            'cost_price' => (string) $this->cost_price,
            'sell_price' => (string) $this->sell_price,
            'active'     => (bool) $this->active,
        ];
    }
}
