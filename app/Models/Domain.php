<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id','name','status','expiry_date','auto_renew','name_servers','dns_config','registry_id','transfer_status'
    ];

    protected $casts = [
        'name_servers' => 'array',
        'auto_renew'   => 'boolean',
        'expiry_date'  => 'date'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function dnsRecords()
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function isExpiringSoon(): bool
    {
        return $this->expiry_date && $this->expiry_date->isBefore(now()->addDays(30));
    }
}
