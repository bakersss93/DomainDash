<?php

namespace App\Support\Security;

/** Shared "is this a public internet address" checks (SSRF guards for admin-configurable hosts). */
class PublicNetwork
{
    /** @return list<string> */
    public static function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) {
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        return $ips;
    }

    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) is judged by the embedded IPv4 address.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // CGNAT 100.64.0.0/10 and IPv6 link-local are not covered by the PHP flags on every version.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            return ! (($long & 0xFFC00000) === ip2long('100.64.0.0'));
        }

        return ! str_starts_with(strtolower($ip), 'fe80:');
    }
}
