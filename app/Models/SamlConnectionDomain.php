<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SamlConnectionDomain extends Model
{
    protected $fillable = ['saml_connection_id', 'domain'];
}
