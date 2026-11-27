<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformCertificate extends Model
{
    protected $fillable = [
        'hostname', 'source', 'issuer', 'subject', 'sans', 'fingerprint_sha256', 'key_type',
        'not_before', 'not_after', 'is_active', 'auto_renew', 'staging', 'last_renewed_at',
        'certificate_pem', 'chain_pem', 'private_key_encrypted', 'request_id',
    ];

    protected $hidden = ['private_key_encrypted'];

    protected $casts = [
        'sans' => 'array',
        'not_before' => 'datetime',
        'not_after' => 'datetime',
        'last_renewed_at' => 'datetime',
        'is_active' => 'boolean',
        'auto_renew' => 'boolean',
        'staging' => 'boolean',
    ];

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->latest('id')->first();
    }

    public function daysRemaining(): ?int
    {
        return $this->not_after ? (int) floor(now()->diffInSeconds($this->not_after, false) / 86400) : null;
    }

    public function isExpired(): bool
    {
        return $this->not_after !== null && $this->not_after->isPast();
    }
}
