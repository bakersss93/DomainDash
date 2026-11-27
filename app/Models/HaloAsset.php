<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of a Halo domain asset (see SyncHaloAssetsJob).
 */
class HaloAsset extends Model
{
    protected $table = 'halo_assets';
    protected $primaryKey = 'halo_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'halo_id', 'halo_client_id', 'assettype_id', 'assettype_name', 'name',
        'inventory_number', 'remote_updated_at', 'synced_at',
    ];

    protected $casts = [
        'remote_updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
