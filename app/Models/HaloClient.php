<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of a Halo client (see SyncHaloClientsJob).
 */
class HaloClient extends Model
{
    protected $table = 'halo_clients';
    protected $primaryKey = 'halo_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['halo_id', 'name', 'abn', 'inactive', 'remote_updated_at', 'synced_at'];

    protected $casts = [
        'inactive' => 'boolean',
        'remote_updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
