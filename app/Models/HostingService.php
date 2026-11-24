<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HostingService extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id','domain_id','hoid','plan','username','server','disk_limit_mb','disk_usage_mb',
        'bandwidth_limit_mb','bandwidth_used_mb','ip_address','next_renewal_due',
        'domain_name','service_status',
        'halo_asset_id', 'halo_asset_synced_at', 'halo_asset_sync_error', 'halo_asset_sync_hash', 'itglue_id', 'itglue_synced_at', 'itglue_sync_error', 'itglue_sync_hash',
    ];

    protected $casts = [
        'next_renewal_due' => 'date',
        'halo_asset_synced_at' => 'datetime',
        'itglue_synced_at' => 'datetime',
    ];

    public function client(){ return $this->belongsTo(Client::class); }
    public function domain(){ return $this->belongsTo(Domain::class); }
}
