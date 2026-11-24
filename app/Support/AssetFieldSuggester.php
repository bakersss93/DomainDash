<?php

namespace App\Support;

/**
 * Suggests which asset-type field should receive each DomainDash data point,
 * purely from field names (case-insensitive "contains" matching). The user
 * always confirms the suggestion in Settings.
 *
 * A field is claimed by the first data point whose pattern matches, in the
 * order expiry, name servers, dns, whois, domain, so "Domain Expiry Date" is
 * an expiry field and not the domain-name field.
 */
class AssetFieldSuggester
{
    /** point => [regex, preferred kinds (lower-case), allowed kinds or null = any] */
    private const HALO = [
        'expiry' => ['/expir|renew/i', [], null],
        'nameservers' => ['/name\s*-?_?servers?|\bns\b|nameservers?/i', [], null],
        'whois' => ['/whois|registrant/i', [], null],
        'domain' => ['/domain/i', [], null],
    ];

    private const ITGLUE = [
        'expiry' => ['/expir|renew/i', ['date'], ['date', 'text', 'textbox']],
        'name_servers' => ['/name\s*-?_?servers?|\bns\b|nameservers?/i', ['text', 'textbox'], ['text', 'textbox']],
        'dns' => ['/\bdns\b|records/i', ['textbox'], ['textbox', 'text']],
        'whois' => ['/whois|registrant/i', ['textbox'], ['textbox', 'text']],
        'domain' => ['/domain/i', ['text'], ['text', 'textbox']],
    ];

    /**
     * Name patterns for the non-domain entities, in claim order (a field goes to the first point that matches, so
     * the specific ones come first: "IP address" is not the service address, "Service type" is not the service id).
     *
     * @var array<string,array<string,string>> entity => point => regex
     */
    private const ENTITY_RULES = [
        'internet_service' => [
            'avc_id' => '/\bavc\b/i',
            'postcode' => '/post\s*-?_?code|\bzip\b/i',
            'location_id' => '/\bloc\b|location|directory|\bdid\b/i',
            'service_type' => '/service\s*type/i',
            'service_id' => '/service\s*-?_?(id|no|number)\b|^\s*service\s*$/i',
            'reference' => '/reference|\bref\b/i',
            'ntd_port' => '/\bntd\b|\buni\b|port/i',
            'username' => '/user\s*-?_?name|pppoe|login/i',
            'ip_address' => '/\bip\b|ip\s*-?_?address/i',
            'plan' => '/plan|package|speed|product/i',
            'technology' => '/technolog|access\s*type|\bfttp\b|\bfttb\b|\bfttn\b/i',
            'service_class' => '/class/i',
            'status' => '/status|state/i',
            'address' => '/address|install|site/i',
            'client' => '/client|customer/i',
        ],
        'hosting_service' => [
            'ip_address' => '/\bip\b|ip\s*-?_?address/i',
            'renewal' => '/renew|expir|due/i',
            'disk' => '/disk|storage/i',
            'bandwidth' => '/bandwidth|traffic/i',
            'username' => '/user/i',
            'plan' => '/plan|package/i',
            'server' => '/server|host(?!ing)/i',
            'status' => '/status|state/i',
            'domain' => '/domain|website|\bsite\b/i',
        ],
        'ssl_certificate' => [
            'expiry' => '/expir|valid\s*(to|until)|\bend\b/i',
            'issued' => '/issued|valid\s*from|\bstart\b|begin/i',
            'cert_id' => '/cert\w*\s*-?_?id|serial|order/i',
            'status' => '/status|state/i',
            'product' => '/product|issuer|brand|type/i',
            'common_name' => '/common\s*-?_?name|\bcn\b|hostname|domain/i',
        ],
    ];

    private const DATE_POINTS = ['expiry', 'renewal', 'issued'];

    /**
     * Auto-suggest for any entity from field names only.
     *
     * Halo: field id per point; IT Glue: trait name-key per point. A point with no matching field is null
     * ("don't sync"). Domains use the original tuned rules.
     *
     * @param array<int,array> $fields Halo {id,name} or IT Glue {name,name_key,kind}
     * @return array<string,int|string|null>
     */
    public static function forEntity(string $provider, string $entity, array $fields): array
    {
        if ($entity === 'domain') {
            return $provider === 'halo' ? self::halo($fields) : self::itglue($fields);
        }

        $rules = [];
        foreach (self::ENTITY_RULES[$entity] ?? [] as $point => $regex) {
            $date = in_array($point, self::DATE_POINTS, true);
            $rules[$point] = [$regex, $date ? ['date'] : ['text', 'textbox'], $provider === 'halo' ? null : ['date', 'text', 'textbox']];
        }

        $candidates = $provider === 'halo'
            ? array_map(fn (array $f) => ['key' => (int) $f['id'], 'text' => (string) ($f['name'] ?? ''), 'kind' => ''], $fields)
            : array_map(fn (array $f) => [
                'key' => (string) $f['name_key'],
                'text' => trim(($f['name'] ?? '') . ' ' . str_replace(['-', '_'], ' ', (string) $f['name_key'])),
                'kind' => strtolower((string) ($f['kind'] ?? '')),
            ], $fields);

        $picked = self::pick($rules, $candidates);
        $out = [];
        foreach (array_keys($rules) as $point) {
            $out[$point] = $picked[$point] ?? null;
        }

        return $out;
    }

    /**
     * @param array<int,array{id:int|string,name:string}> $fields
     * @return array<string,int|null> data point => Halo field id (null = no suggestion)
     */
    public static function halo(array $fields): array
    {
        $candidates = array_map(fn (array $f) => [
            'key' => (int) $f['id'],
            'text' => (string) ($f['name'] ?? ''),
            'kind' => '',
        ], $fields);

        $picked = self::pick(self::HALO, $candidates);
        $out = [];
        foreach (array_keys(self::HALO) as $point) {
            $out[$point] = isset($picked[$point]) ? (int) $picked[$point] : null;
        }

        return [
            'domain' => $out['domain'], 'expiry' => $out['expiry'],
            'nameservers' => $out['nameservers'], 'whois' => $out['whois'],
        ];
    }

    /**
     * @param array<int,array{name:string,name_key:string,kind:string}> $fields
     * @return array<string,string|null> data point => trait name-key (null = no suggestion)
     */
    public static function itglue(array $fields): array
    {
        $candidates = array_map(fn (array $f) => [
            'key' => (string) $f['name_key'],
            'text' => trim(($f['name'] ?? '') . ' ' . str_replace(['-', '_'], ' ', (string) $f['name_key'])),
            'kind' => strtolower((string) ($f['kind'] ?? '')),
        ], $fields);

        $picked = self::pick(self::ITGLUE, $candidates);
        $out = [];
        foreach (['domain', 'name_servers', 'expiry', 'whois', 'dns'] as $point) {
            $out[$point] = isset($picked[$point]) ? (string) $picked[$point] : null;
        }

        return $out;
    }

    /**
     * @param array<string,array> $rules
     * @param array<int,array{key:int|string,text:string,kind:string}> $candidates
     * @return array<string,int|string>
     */
    private static function pick(array $rules, array $candidates): array
    {
        $claimed = [];
        foreach ($candidates as $c) {
            foreach ($rules as $point => [$regex, $preferred, $allowed]) {
                if ($allowed !== null && ! in_array($c['kind'], $allowed, true)) {
                    continue;
                }
                if (preg_match($regex, $c['text'])) {
                    $claimed[$point][] = $c;
                    break;
                }
            }
        }

        $out = [];
        foreach ($rules as $point => [$regex, $preferred]) {
            $list = $claimed[$point] ?? [];
            if ($list === []) {
                continue;
            }
            usort($list, function (array $a, array $b) use ($preferred) {
                $ka = in_array($a['kind'], $preferred, true) ? 0 : 1;
                $kb = in_array($b['kind'], $preferred, true) ? 0 : 1;

                return [$ka, strlen($a['text'])] <=> [$kb, strlen($b['text'])];
            });
            $out[$point] = $list[0]['key'];
        }

        return $out;
    }
}
