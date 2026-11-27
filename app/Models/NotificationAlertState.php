<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAlertState extends Model
{
    protected $fillable = ['user_id', 'alert_key', 'snoozed_until'];

    protected $casts = ['snoozed_until' => 'datetime'];
}
