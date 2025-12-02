<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_name',
        'abn',
        'halopsa_reference',  // Changed from halopsa_id
        'itglue_org_id',
        'itglue_org_name',    // Added
        'active'
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'client_user');
    }

    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    public function hostingServices()
    {
        return $this->hasMany(HostingService::class);
    }

    public function sslCertificates()
    {
        return $this->hasMany(SslCertificate::class);
    }
    
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}