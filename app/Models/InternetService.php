<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternetService extends Model
{
    protected $fillable = [
        'vocus_service_id', 'client_id', 'plan_id', 'service_scope', 'service_status',
        'service_type', 'order_type', 'customer_name', 'phone', 'directory_id',
        'location_reference', 'address_long', 'nbn_instance_id', 'avc_id', 'cvc_id',
        'copper_pair_id', 'realm', 'service_level', 'billing_provider_id',
        'last_transaction_id', 'last_transaction_state', 'notes', 'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function diagnostics()
    {
        return $this->hasMany(InternetServiceDiagnostic::class)->latest('created_at');
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->service_status) {
            'ACTIVE'   => 'green',
            'SUSPEND'  => 'yellow',
            'INACTIVE' => 'red',
            default    => 'gray',
        };
    }

    public function isPending(): bool
    {
        return in_array($this->last_transaction_state, ['QUEUED', 'PROCESSING']);
    }
}
