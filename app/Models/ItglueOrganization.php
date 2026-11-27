<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of an IT Glue organisation (see SyncItGlueOrganizationsJob).
 */
class ItglueOrganization extends Model
{
    protected $table = 'itglue_organizations';
    protected $primaryKey = 'itglue_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['itglue_id', 'name', 'remote_updated_at', 'synced_at'];

    protected $casts = [
        'remote_updated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
