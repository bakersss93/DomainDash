<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SslProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'description',
        'remote_product_type',
        'price',
        'last_synced_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'last_synced_at' => 'datetime',
    ];
}
