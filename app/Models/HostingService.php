<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HostingService extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id','domain_id','hoid','plan','username','server','disk_limit_mb','disk_usage_mb',
        'bandwidth_limit_mb','bandwidth_used_mb','ip_address','next_renewal_due'
    ];

    protected $casts = [
        'next_renewal_due' => 'date'
    ];

    public function client(){ return $this->belongsTo(Client::class); }
    public function domain(){ return $this->belongsTo(Domain::class); }
}
