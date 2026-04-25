<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTrigger extends Model
{
    protected $fillable = [
        'name',
        'event_key',
        'days_before',
        'audience',
        'email_template_id',
        'admin_create_halo_ticket',
        'halo_ticket_board',
        'halo_ticket_type',
    ];

    protected $casts = [
        'days_before' => 'integer',
        'admin_create_halo_ticket' => 'boolean',
    ];

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }
}
