<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostingPackage extends Model
{
    protected $fillable = [
        'package_name',
        'category',
        'disk_mb',
        'bandwidth_mb',
        'cpu_percent',
        'memory_mb',
        'io_mbps',
        'inodes_soft',
        'inodes_hard',
        'email_accounts',
        'databases',
        'ssh_access',
        'price_monthly',
        'price_annually',
        'description',
    ];

    protected $casts = [
        'ssh_access' => 'boolean',
        'price_monthly' => 'decimal:2',
        'price_annually' => 'decimal:2',
    ];

    /**
     * Get the hosting services using this package.
     */
    public function services()
    {
        return $this->hasMany(HostingService::class, 'plan', 'package_name');
    }
}
