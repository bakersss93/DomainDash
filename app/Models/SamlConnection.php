<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SamlConnection extends Model
{
    public const TYPE_STAFF = 'staff';

    protected $fillable = [
        'name', 'type', 'enabled', 'entra_tenant_id', 'idp_entity_id', 'idp_sso_url', 'idp_slo_url',
        'idp_x509_cert', 'idp_x509_cert_2', 'metadata_url', 'group_role_map', 'default_role',
        'jit_provisioning', 'skip_local_mfa',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'jit_provisioning' => 'boolean',
            'skip_local_mfa' => 'boolean',
            'group_role_map' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->uuid ??= (string) Str::uuid();
            // SAML is staff-only.
            $c->type = self::TYPE_STAFF;
            // Secure default: connections keep DomainDash's own MFA unless explicitly told to trust Entra.
            if (! array_key_exists('skip_local_mfa', $c->getAttributes())) {
                $c->skip_local_mfa = false;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SamlConnectionDomain::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isStaff(): bool
    {
        return $this->type === self::TYPE_STAFF;
    }

    /** @return list<string> */
    public function domainList(): array
    {
        return $this->domains()->orderBy('domain')->pluck('domain')->all();
    }

    /** @param list<string> $domains */
    public function syncDomains(array $domains): void
    {
        $domains = array_values(array_unique($domains));
        $this->domains()->whereNotIn('domain', $domains)->delete();
        foreach ($domains as $d) {
            $this->domains()->firstOrCreate(['domain' => $d]);
        }
    }

    /** @return list<string> */
    public function signingCerts(): array
    {
        return array_values(array_filter([$this->idp_x509_cert, $this->idp_x509_cert_2]));
    }

    // SP values the admin pastes into Entra.
    public function spEntityId(): string
    {
        return url('/saml/'.$this->uuid.'/metadata');
    }

    public function acsUrl(): string
    {
        return url('/saml/'.$this->uuid.'/acs');
    }

    public function loginUrl(): string
    {
        return url('/saml/'.$this->uuid.'/login');
    }
}
