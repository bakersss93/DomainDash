<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    /**
     * All valid API scopes, keyed by scope string with a human-readable description.
     * These align 1-to-1 with the route groups in routes/api.php.
     */
    public const SCOPES = [
        'domains.read'   => 'View domains and expiry dates',
        'domains.auth-code' => 'Read domain EPP / transfer auth codes (transfer-capable secret)',
        'domains.write'  => 'Sync from Synergy, renew, transfer and assign domains to clients',
        'dns.read'       => 'View DNS zone records for a domain',
        'dns.write'      => 'Add, update and delete DNS records; change nameservers / DNS mode',
        'clients.read'   => 'View client organisations and their domain list',
        'clients.write'  => 'Create, update and delete client organisations',
        'hosting.read'   => 'View hosting services and their usage statistics',
        'hosting.write'  => 'Trigger hosting service sync from Synergy Wholesale',
        'ssl.read'       => 'View SSL certificates and their status',
        'ssl.write'      => 'Trigger SSL certificate sync from Synergy Wholesale',
        'internet.read'  => 'View internet / broadband services',
        'internet.write' => 'Trigger internet service sync from Vocus WSM',
        'tickets.read'   => 'View HaloPSA support tickets and their replies',
        'tickets.write'  => 'Create tickets and post replies; close tickets',
        'pricing.read'   => 'View the domain pricing catalogue',
        'users.read'     => 'View portal user accounts',
        'users.write'    => 'Create, update and delete non-admin portal users (Technician/Customer)',
        'users.admin'    => 'ADMIN-EQUIVALENT: additionally allow assigning/modifying/deleting Administrator users via the API',
        'audit.read'     => 'View the system audit log',
    ];

    /** Scopes that are never satisfied by legacy broad ("read"/"write") scopes. */
    public const SENSITIVE_SCOPES = ['users.read', 'users.write', 'users.admin', 'audit.read', 'domains.auth-code'];

    protected $fillable = ['name','key_prefix','key_hash','allowed_ips','rate_limit_per_hour','scopes','active','expires_at','last_used_at'];

    protected $casts = [
        'scopes' => 'array',
        'active' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Generate a new key. Format: "<12 hex prefix>.<48 hex secret>".
     * Only the prefix (indexed identifier) and a SHA-256 of the full key are stored.
     *
     * @return array{plain:string, prefix:string, hash:string}
     */
    public static function generate(): array
    {
        $prefix = bin2hex(random_bytes(6));
        $plain = $prefix . '.' . bin2hex(random_bytes(24));

        return ['plain' => $plain, 'prefix' => $prefix, 'hash' => self::hashKey($plain)];
    }

    public static function hashKey(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Identifier portion used for the indexed lookup. */
    public static function prefixOf(string $plain): string
    {
        $pos = strpos($plain, '.');

        return $pos !== false ? substr($plain, 0, $pos) : substr($plain, 0, 12);
    }

    /**
     * Resolve a presented raw key to an active ApiKey: indexed prefix lookup and one
     * constant-time hash comparison. Legacy bcrypt keys (no prefix yet) go through a
     * transitional path and are upgraded in place on first successful use.
     */
    public static function findByPlain(string $plain): ?self
    {
        if ($plain === '' || strlen($plain) > 200) {
            return null;
        }

        $candidate = static::where('active', true)->where('key_prefix', self::prefixOf($plain))->first();
        if ($candidate && hash_equals((string) $candidate->key_hash, self::hashKey($plain))) {
            return $candidate;
        }

        // Transitional: pre-upgrade keys are bare 48-hex strings with a bcrypt hash.
        if (! str_contains($plain, '.') && preg_match('/^[0-9a-f]{48}$/', $plain)) {
            foreach (static::where('active', true)->whereNull('key_prefix')->get() as $legacy) {
                if (password_verify($plain, $legacy->key_hash)) {
                    $legacy->forceFill([
                        'key_prefix' => self::prefixOf($plain),
                        'key_hash' => self::hashKey($plain),
                    ])->save();

                    return $legacy;
                }
            }
        }

        return null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check whether this key is permitted to use the given scope.
     *
     * A key with no scopes has NO access. Legacy broad scopes ('read', 'write',
     * 'services.*') continue to satisfy ordinary *.read / *.write scopes but never
     * the sensitive scopes in SENSITIVE_SCOPES.
     */
    public function allowsScope(string $scope): bool
    {
        if (empty($this->scopes)) {
            return false;
        }

        if (in_array($scope, $this->scopes, true)) {
            return true;
        }

        if (in_array($scope, self::SENSITIVE_SCOPES, true)) {
            return false;
        }

        $suffix = str_contains($scope, '.') ? substr($scope, strrpos($scope, '.') + 1) : null;
        if ($suffix && in_array($suffix, ['read', 'write'], true) && in_array($suffix, $this->scopes, true)) {
            return true;
        }

        $prefix = str_contains($scope, '.') ? substr($scope, 0, strrpos($scope, '.')) : null;
        if ($prefix && in_array($prefix, ['hosting', 'ssl', 'internet'], true) && $suffix
            && in_array("services.{$suffix}", $this->scopes, true)) {
            return true;
        }

        return false;
    }
}
