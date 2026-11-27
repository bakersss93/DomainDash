<?php

namespace App\Services\Https;

/** Looks up TXT records; an interface so propagation polling is testable. */
interface TxtResolver
{
    /** @return list<string> TXT strings currently visible for $name (no trailing dot). */
    public function lookupTxt(string $name): array;

    /** @return list<string> NS host names for $zone (lower-case, no trailing dot). */
    public function lookupNs(string $zone): array;
}
