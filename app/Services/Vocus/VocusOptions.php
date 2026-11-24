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

    /** Customer username: the unique part only (portal rule 4-22 characters A-z 0-9 _). */
    public const USERNAME_REGEX = '/^[A-Za-z0-9_]{4,22}$/';

    /** Customer password: A-z 0-9 - . (WSM Password data type is 2..50). */
    public const PASSWORD_REGEX = '/^[A-Za-z0-9.-]{2,50}$/';

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
