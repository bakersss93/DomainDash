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
        'domains.read'   => 'View domains, expiry dates and EPP auth codes',
        'domains.write'  => 'Sync from Synergy, renew, transfer and assign domains to clients',
        'dns.read'       => 'View DNS zone records for a domain',
        'dns.write'      => 'Add, update and delete DNS records; change nameservers / DNS mode',
        'clients.read'   => 'View client organisations and their domain list',
        'clients.write'  => 'Create, update and delete client organisations',
        'services.read'  => 'View hosting services, SSL certificates and internet services',
        'services.write' => 'Trigger sync of hosting, SSL and internet services',
        'tickets.read'   => 'View HaloPSA support tickets and their replies',
        'tickets.write'  => 'Create tickets and post replies; close tickets',
        'pricing.read'   => 'View the domain pricing catalogue',
        'users.read'     => 'View portal user accounts',
        'users.write'    => 'Create, update and delete portal users',
        'audit.read'     => 'View the system audit log',
    ];

    protected $fillable = ['name','key_hash','allowed_ips','rate_limit_per_hour','scopes','active'];

    protected $casts = ['scopes' => 'array'];

    /**
     * Check whether this key is permitted to use the given scope.
     *
     * Exact-match takes priority.  As a legacy fallback the broad scopes
     * 'read' and 'write' (stored before the granular system was introduced)
     * are treated as wildcards that satisfy any *.read or *.write requirement.
     */
    public function allowsScope(string $scope): bool
    {
        if (!$this->scopes) {
            return true;
        }

        if (in_array($scope, $this->scopes, true)) {
            return true;
        }

        // Legacy broad-scope fallback: 'read' satisfies any *.read requirement,
        // 'write' satisfies any *.write requirement.
        $suffix = str_contains($scope, '.') ? substr($scope, strrpos($scope, '.') + 1) : null;
        if ($suffix && in_array($suffix, $this->scopes, true)) {
            return true;
        }

        return false;
    }
}
