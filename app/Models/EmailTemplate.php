<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'trigger_event',
        'audience',
        'title',
        'subject',
        'body',
        'admin_recipient_email',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function notificationTriggers(): HasMany
    {
        return $this->hasMany(NotificationTrigger::class);
    }
}
