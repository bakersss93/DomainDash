<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'tld',
        'registration_price',
        'renewal_price',
        'restore_price',
        'transfer_price',
        'minimum_years',
        'maximum_years',
        'id_protection',
        'dnssec',
        'sale_registration_1_year_price',
        'sale_registration_2_year_price',
        'sale_registration_3_year_price',
        'sale_registration_4_year_price',
        'sale_registration_5_year_price',
        'sale_registration_6_year_price',
        'sale_registration_7_year_price',
        'sale_registration_8_year_price',
        'sale_registration_9_year_price',
        'sale_registration_10_year_price',
        'sale_renew_price',
        'sale_transfer_price',
        'sale_end_date',
        'sell_price',
    ];

    protected $casts = [
        'id_protection' => 'boolean',
        'dnssec' => 'boolean',
        'sale_end_date' => 'date',
    ];

    public function getEffectiveRegistrationPriceAttribute(): ?float
    {
        if ($this->sale_registration_1_year_price !== null && $this->sale_end_date !== null && ! $this->sale_end_date->isPast()) {
            return (float) $this->sale_registration_1_year_price;
        }

        return $this->registration_price !== null ? (float) $this->registration_price : null;
    }
}
