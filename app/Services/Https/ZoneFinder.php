<?php

namespace App\Services\Https;

use App\Models\Domain;
use App\Services\Synergy\SynergyWholesaleClient;

/** Finds which registered domain (DNS zone) in Synergy contains a hostname. */
class ZoneFinder
{
    public function __construct(private ?SynergyWholesaleClient $synergy = null)
    {
    }

    /**
     * Candidate zones, longest first, never below two labels: a.b.example.com.au ->
     * [a.b.example.com.au, b.example.com.au, example.com.au, com.au]
     *
     * @return list<string>
     */
    public static function candidates(string $host): array
    {
        $host = strtolower(trim($host, '.'));
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }
        $labels = explode('.', $host);
        $out = [];
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $out[] = implode('.', array_slice($labels, $i));
        }

        return $out;
    }

    public static function fromLocalDomains(string $host): ?string
    {
        $candidates = self::candidates($host);
        if (! $candidates) {
            return null;
        }
        $names = Domain::query()->whereIn('name', $candidates)->pluck('name')->map(fn ($n) => strtolower($n))->all();
        foreach ($candidates as $c) {
            if (in_array($c, $names, true)) {
                return $c;
            }
        }

        return null;
    }

    /** Local domains table first, then the live Synergy domain list. */
    public function find(string $host): ?string
    {
        if (($zone = self::fromLocalDomains($host)) !== null) {
            return $zone;
        }
        if ($this->synergy === null) {
            return null;
        }

        $candidates = self::candidates($host);
        $page = 1;
        while ($page <= 20) {
            $res = $this->synergy->listDomains($page, 500);
            $list = $res['domainList'] ?? [];
            foreach ($candidates as $c) {
                foreach ($list as $row) {
                    $row = (array) $row;
                    if (strtolower((string) ($row['domainName'] ?? '')) === $c) {
                        return $c;
                    }
                }
            }
            if (count($list) < 500) {
                break;
            }
            $page++;
        }

        return null;
    }
}
