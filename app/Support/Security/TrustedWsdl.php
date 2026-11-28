<?php

namespace App\Support\Security;

/**
 * Single definition of which WSDL locations the Synergy SOAP clients may load (SSRF / local file read guard).
 * Used by SynergyWholesaleClient, SynergyDomainData and the settings validation.
 */
class TrustedWsdl
{
    public static function isTrusted(string $location): bool
    {
        if (preg_match('#^https://([^/:]+)(:\d+)?/#i', $location, $m)) {
            $host = strtolower($m[1]);

            return $host === 'synergywholesale.com' || str_ends_with($host, '.synergywholesale.com');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return false;
        }

        $real = realpath($location);
        if ($real === false) {
            return false;
        }

        foreach ([base_path('resources'), storage_path('app')] as $root) {
            $root = realpath($root);
            if ($root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** The WSDL to use: the stored `wsdl_path` when trusted, otherwise config('services.synergy.wsdl'). */
    public static function resolve(?string $stored): string
    {
        if (is_string($stored) && $stored !== '' && self::isTrusted($stored)) {
            return $stored;
        }

        return (string) (config('services.synergy.wsdl') ?: base_path('resources/wsdl/synergy.wsdl'));
    }
}
