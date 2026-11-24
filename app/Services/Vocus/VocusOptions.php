<?php

namespace App\Services\Vocus;

use App\Models\InternetService;
use App\Models\Setting;
use App\Support\Https\HostnameValidator;

/**
 * Admin-configurable ordering options kept in the `vocus` setting: PPPoE realms and the
 * enabled service scopes. Everything here is non-secret.
 */
final class VocusOptions
{
    /** Scopes documented for ProductID FIBRE (Examples 4.1 RESELLER-CONNECT, 4.2 NETWORK-CONNECT). */
    public const SCOPES = [
        'RESELLER-CONNECT' => 'Reseller-Connect (Layer 3, hosted)',
        'NETWORK-CONNECT'  => 'Network-Connect (Layer 2, NCL2)',
    ];

    /**
     * Customer username: the unique part only. Data Types "Username": the user_id part "must be between 2 to 22
     * characters", characters a-z A-Z 0-9 underscore; the realm is appended by composeUsername().
     */
    public const USERNAME_REGEX = '/^[A-Za-z0-9_]{2,22}$/';

    /**
     * Customer password. Data Types "Password": string 2..30 (the older XSD allowed 50; the current Data Types
     * Definition says 30). Characters A-z 0-9 - . are the portal rule; the catalogue does not list a charset.
     */
    public const PASSWORD_REGEX = '/^[A-Za-z0-9.-]{2,30}$/';

    /** Reference: string 2..30, a-z A-Z 0-9 dash underscore dot (Data Types "Reference"). */
    public const REFERENCE_REGEX = '/^[A-Za-z0-9._-]{2,30}$/';

    /** CarrierID (the FNN for voiceband continuity): integer 2..15 digits (Data Types "CarrierID"). */
    public const CARRIER_ID_REGEX = '/^\d{2,15}$/';

    /** ServiceLevel (Data Types "ServiceLevel"; default STANDARD). Note "24x7", not "24/7". */
    public const SERVICE_LEVELS = [
        'STANDARD', 'ENHANCED4', 'ENHANCED6', 'ENHANCED8', 'ENHANCED12',
        'ENHANCED4-24x7', 'ENHANCED6-24x7', 'ENHANCED8-24x7', 'ENHANCED12-24x7',
    ];

    /** FIBRE::Create ServiceType (Product Catalogue FIBRE 4.1.1.2): FTTP, WIRELESS, FTTB, FTTN, HFC, FTTC. */
    public const SERVICE_TYPES = ['FTTP', 'WIRELESS', 'FTTB', 'FTTN', 'HFC', 'FTTC'];

    /** FIBRE::Create AuthType (RESELLER-CONNECT only; PPPoE when omitted). Data Types "AuthType" also lists NA (read only). */
    public const AUTH_TYPES = ['PPPoE', 'IPoE'];

    /** FIBRE::Create IPAssignment (RESELLER-CONNECT only; CGNAT when omitted). */
    public const IP_ASSIGNMENTS = ['CGNAT', 'STATIC', 'DYNAMIC'];

    /** FIBRE::Create OrderType for transfers (consolidated table 6.1: NEW, TRANSFER or OUTSTANDING). */
    public const TRANSFER_ORDER_TYPES = [
        'TRANSFER'    => 'Transfer (AVC ID is verified)',
        'OUTSTANDING' => 'Connect outstanding (no AVC ID needed)',
    ];

    /**
     * NBNCPEPlanID values (Product Catalogue FIBRE appendix 6.4) this app can order. Only the options that need
     * no CPEDirectoryID are offered: the end-user self install and warehouse-shipped NTD / NCD variants need a
     * shipping address created with DIR::Create (not implemented).
     * Shape: technology => [value => [label, scopes]]. "-REPLACE" values need the NTDID being replaced.
     */
    public const NBN_CPE_PLANS = [
        'FTTP' => [
            'FTTPNTDNBNPROFINSTALL-V3-1P'         => ['New 1-port NTD (V3), NBN professional install', ['RESELLER-CONNECT', 'NETWORK-CONNECT']],
            'FTTPNTDNBNPROFINSTALL-V4-4P'         => ['Additional 4-port NTD (V4), NBN professional install', ['RESELLER-CONNECT', 'NETWORK-CONNECT']],
            'FTTPNTDNBNPROFINSTALL-V3-1P-REPLACE' => ['Replace the NTD with a 1-port NTD (V3)', ['RESELLER-CONNECT', 'NETWORK-CONNECT']],
            'FTTPNTDNBNPROFINSTALL-V4-4P-REPLACE' => ['Replace the NTD with a 4-port NTD (V4)', ['RESELLER-CONNECT', 'NETWORK-CONNECT']],
        ],
        'HFC' => [
            'HFCNTDNBNPROFINSTALL' => ['New HFC NTD, NBN professional install', ['RESELLER-CONNECT', 'NETWORK-CONNECT']],
        ],
        'FTTC' => [
            'FTTCNCDNBNPROFINSTALL' => ['New FTTC NCD, NBN professional install', ['RESELLER-CONNECT', 'NETWORK-CONNECT']],
        ],
    ];

    /**
     * Map a ServiceType seen in a QUALIFY response (or stored) to the value FIBRE::Create accepts.
     * QUALIFY returns "FIBRE, WIRELESS, FTTB, FTTN, HFC, FTTC or N/A" (4.2.1) while Create wants FTTP for fibre.
     */
    public static function createServiceType(?string $qualifyType): ?string
    {
        $t = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $qualifyType));

        return match (true) {
            $t === 'FIBRE' || $t === 'FTTP'                                     => 'FTTP',
            in_array($t, ['WIRELESS', 'FIXEDWIRELESS', 'FW'], true)             => 'WIRELESS',
            in_array($t, ['FTTB', 'FTTN', 'HFC', 'FTTC'], true)                 => $t,
            default                                                             => null,
        };
    }

    /** Contact name: first and last name, 4-39 characters, letters / digits / space. */
    public const CONTACT_NAME_REGEX = '/^(?=.{4,39}$)[A-Za-z0-9]+(?: [A-Za-z0-9]+)+$/';

    public const MAX_REALMS = 20;

    /** Realm param is limited to 61 characters in this app (DataTypes max 64 incl. headroom). */
    public const MAX_REALM_LENGTH = 61;

    /** @return list<string> */
    public static function realms(): array
    {
        $raw = (array) (self::settings()['realms'] ?? []);

        return array_values(array_filter(array_map(fn ($r) => is_string($r) ? strtolower(trim($r)) : '', $raw)));
    }

    /**
     * Enabled scopes, in documented order. Nothing stored yet means every documented scope is enabled.
     *
     * @return list<string>
     */
    public static function enabledScopes(): array
    {
        $stored = self::settings()['scopes'] ?? null;
        if (! is_array($stored)) {
            return array_keys(self::SCOPES);
        }

        return array_values(array_filter(array_keys(self::SCOPES), fn ($s) => in_array($s, $stored, true)));
    }

    /**
     * Normalise and validate a realm list. Returns [realms, errors].
     *
     * @param  array<int,mixed> $input
     * @return array{0:list<string>,1:list<string>}
     */
    public static function validateRealms(array $input): array
    {
        $errors = [];
        $realms = [];
        foreach ($input as $value) {
            $realm = is_string($value) ? HostnameValidator::normalize($value) : '';
            if ($realm === '' || strlen($realm) > self::MAX_REALM_LENGTH || ! HostnameValidator::isValidHostname($realm)) {
                $errors[] = 'Invalid realm "' . (is_string($value) ? mb_substr($value, 0, 70) : '') . '": use a hostname such as example.com.au (up to ' . self::MAX_REALM_LENGTH . ' characters).';
                continue;
            }
            if (in_array($realm, $realms, true)) {
                $errors[] = 'Duplicate realm "' . $realm . '".';
                continue;
            }
            $realms[] = $realm;
        }
        if (count($realms) > self::MAX_REALMS) {
            $errors[] = 'At most ' . self::MAX_REALMS . ' realms are allowed.';
        }

        return [$realms, $errors];
    }

    /**
     * Username sent to Vocus: "unique@realm". A blank unique part returns null so the
     * Username parameter is omitted and Vocus applies its default (the LOC ID based
     * username, as in the Vocus portal and the Get SERVICE sample in Examples 4.1.8).
     */
    public static function composeUsername(?string $local, string $realm): ?string
    {
        $local = trim((string) $local);

        return $local === '' ? null : $local . '@' . strtolower($realm);
    }

    /**
     * DomainDash generates the ServiceID: Vocus requires a client-supplied unique id on Create
     * (Examples 4.1.4 "MY-SERVICE-ID"; Sandbox 2.2 "Invalid Parameter: ServiceID" when missing).
     * Format: DD-<12 digits of the LOC ID, or random>-<4 random upper-case alphanumerics>, at most 21
     * characters, charset A-Z 0-9 dash (inside the documented a-zA-Z0-9._- / 1..30).
     */
    public static function generateServiceId(?string $directoryId = null): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $random = fn (int $n) => implode('', array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, $n)));

        $middle = ($directoryId !== null && preg_match('/^LOC(\d{12})$/i', $directoryId, $m)) ? $m[1] : $random(8);

        for ($i = 0; $i < 10; $i++) {
            $id = 'DD-' . $middle . '-' . $random(4);
            if (! InternetService::where('vocus_service_id', $id)->exists()) {
                return $id;
            }
        }

        return 'DD-' . $random(16);
    }

    /** NBN LOC ID: LOC + 12 digits (case-insensitive input, returned upper-case) or null. */
    public static function normalizeLocId(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^LOC\d{12}$/', $value) ? $value : null;
    }

    private static function settings(): array
    {
        $v = Setting::get('vocus', []);

        return is_array($v) ? $v : [];
    }
}
