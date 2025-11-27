<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SslCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id','domain_id','cert_id','common_name','product_name','start_date','expire_date','status'
    ];

    protected $casts = [
        'start_date' => 'date',
        'expire_date'=> 'date',
    ];

    public function client(){ return $this->belongsTo(Client::class); }
    public function domain(){ return $this->belongsTo(Domain::class); }

    public function isExpiringSoon(): bool
    {
        return $this->expire_date && $this->expire_date->isBefore(now()->addDays(30));
    }
}
