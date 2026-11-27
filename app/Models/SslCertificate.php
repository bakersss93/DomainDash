<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SslCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id','domain_id','cert_id','common_name','product_name','start_date','expire_date','status',
        'halo_asset_id', 'halo_asset_synced_at', 'halo_asset_sync_error', 'halo_asset_sync_hash', 'itglue_id', 'itglue_synced_at', 'itglue_sync_error', 'itglue_sync_hash',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expire_date'=> 'date',
        'halo_asset_synced_at' => 'datetime',
        'itglue_synced_at' => 'datetime',
    ];

    public function client(){ return $this->belongsTo(Client::class); }
    public function domain(){ return $this->belongsTo(Domain::class); }

    public function isExpiringSoon(): bool
    {
        return $this->expire_date && $this->expire_date->isBefore(now()->addDays(30));
    }
}
