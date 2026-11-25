<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded integration call: outbound (Halo, IT Glue, Synergy, Vocus, ip2whois) or inbound (API keys).
 * Everything stored here has already been redacted and truncated by IntegrationRequestLogger.
 */
class IntegrationRequest extends Model
{
    public const OUTBOUND = 'outbound';
    public const INBOUND = 'inbound';

    public const PROVIDERS = [
        'halo' => 'HaloPSA',
        'itglue' => 'IT Glue',
        'synergy' => 'Synergy Wholesale',
        'vocus' => 'Vocus WSM',
        'ip2whois' => 'IP2WHOIS',
        'api' => 'API',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'request_headers' => 'array',
        'response_headers' => 'array',
        'meta' => 'array',
        'is_error' => 'boolean',
        'request_truncated' => 'boolean',
        'response_truncated' => 'boolean',
        'created_at' => 'datetime',
    ];

    public static function providerLabel(?string $provider): string
    {
        return self::PROVIDERS[$provider ?? ''] ?? ucfirst((string) $provider);
    }
}
