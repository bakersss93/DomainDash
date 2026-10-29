<?php

namespace App\Services\Https;

/** Uses the system resolver (PHP DNS functions; `dig` is not required). */
class PhpTxtResolver implements TxtResolver
{
    public function lookupTxt(string $name): array
    {
        $records = @dns_get_record($name . '.', DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $out = [];
        foreach ($records as $r) {
            if (isset($r['txt'])) {
                $out[] = (string) $r['txt'];
            } elseif (isset($r['entries'])) {
                $out[] = implode('', (array) $r['entries']);
            }
        }

        return $out;
    }

    public function lookupNs(string $zone): array
    {
        $records = @dns_get_record($zone . '.', DNS_NS);

        return is_array($records)
            ? array_values(array_map(fn ($r) => strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $records))
            : [];
    }
}
