<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformAcmeChallenge extends Model
{
    protected $fillable = ['domain', 'zone', 'record_name', 'value', 'record_id'];
}
