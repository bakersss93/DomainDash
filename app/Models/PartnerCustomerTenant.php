<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCustomerTenant extends Model
{
    protected $fillable = ['tenant_id', 'display_name', 'default_domain', 'domains', 'relationship_status', 'source', 'last_seen_at'];

    protected $casts = ['domains' => 'array', 'last_seen_at' => 'datetime'];

    /** The DomainDash client mapped to this tenant (clients.microsoft_tenant_id is the single source of truth). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'tenant_id', 'microsoft_tenant_id');
    }
}
