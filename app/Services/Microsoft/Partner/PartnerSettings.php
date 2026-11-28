<?php

namespace App\Services\Microsoft\Partner;

use App\Models\Setting;

/**
 * Microsoft partner (CSP / GDAP) connection: an app registration in the MSP's OWN tenant, used with the client
 * credentials flow to list the partner's customer tenants. Stored in the encrypted `microsoft_partner` setting;
 * the secret never leaves the server (the form only ever sees the "********" placeholder).
 */
class PartnerSettings
{
    public const KEY = 'microsoft_partner';

    public const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Least privilege: DelegatedAdminRelationship.Read.All. Names + tenant IDs only (no domains). */
    public const SOURCE_DELEGATED = 'delegated_admin_customers';

    /** Directory.Read.All: GET /contracts also returns defaultDomainName (better for domain-match suggestions). */
    public const SOURCE_CONTRACTS = 'contracts';

    /** Both endpoints, merged by tenant ID (needs both permissions). */
    public const SOURCE_BOTH = 'both';

    public const SOURCES = [self::SOURCE_DELEGATED, self::SOURCE_CONTRACTS, self::SOURCE_BOTH];

    public static function all(): array
    {
        return array_merge([
            'partner_tenant_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'source' => self::SOURCE_DELEGATED,
        ], (array) Setting::get(self::KEY, []));
    }

    public static function partnerTenantId(): string
    {
        return strtolower(trim((string) self::all()['partner_tenant_id']));
    }

    public static function clientId(): string
    {
        return strtolower(trim((string) self::all()['client_id']));
    }

    public static function clientSecret(): string
    {
        return (string) self::all()['client_secret'];
    }

    public static function source(): string
    {
        $s = (string) self::all()['source'];

        return in_array($s, self::SOURCES, true) ? $s : self::SOURCE_DELEGATED;
    }

    public static function configured(): bool
    {
        return self::isGuid(self::partnerTenantId()) && self::isGuid(self::clientId()) && self::clientSecret() !== '';
    }

    public static function isGuid(string $value): bool
    {
        return (bool) preg_match(self::GUID, $value);
    }
}
