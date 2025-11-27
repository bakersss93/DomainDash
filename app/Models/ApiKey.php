<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $fillable = ['name','key_hash','allowed_ips','rate_limit_per_hour','scopes','active'];

    protected $casts = ['scopes' => 'array'];

    public function allowsScope(string $scope): bool
    {
        if(!$this->scopes) return true;
        return in_array($scope, $this->scopes, true);
    }
}
