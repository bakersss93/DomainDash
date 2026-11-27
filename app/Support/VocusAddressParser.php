<?php

namespace App\Support;

/**
 * Best-effort parser for a free-text Australian address into Vocus DIR
 * ("Main.*") lookup parameters, following the Vocus Street Address Component
 * and PostCode Standard (see VocusAddressStandard).
 *
 * Postcode and state are only ever removed as WHOLE trailing tokens, from the
 * end of the string, so substrings inside words ("Hunter", "Sandy", "Victoria")
 * are never touched.
 *
 * Examples:
 *   "2/99 Example St, Upper Melbourne VIC 4009"
 *   "12 Main St Hobart TAS 7000"
 *   "Unit 5, 12A Smith Street, Newtown NSW 2042"
 *   "Level 10, 452 Flinders Street, Melbourne"
 */
class VocusAddressParser
{
    /** @return array<string,string|int> only non-empty keys */
    public static function parse(string $address): array
    {
        $params = [];
        $rest = trim((string) preg_replace('/\s+/', ' ', $address));
        $rest = trim($rest, " \t,;");

        // Postcode: a final whole token of 4 digits.
        if (preg_match('/(?:^|[\s,])(\d{4})$/', $rest, $m)) {
            $params['Main.PostCode'] = $m[1];
            $rest = rtrim(substr($rest, 0, strlen($rest) - strlen($m[1])), " ,");
        }

        // State: the final whole token (after the postcode was removed).
        if (preg_match('/(?:^|[\s,])([A-Za-z]{2,3})$/', $rest, $m) && ($state = VocusAddressStandard::state($m[1])) !== null) {
            $params['Main.State'] = $state;
            $rest = rtrim(substr($rest, 0, strlen($rest) - strlen($m[1])), " ,");
        }

        // "street part, suburb" when there is a comma; the street part is the
        // first segment that carries the street (see below for the no-comma case).
        $suburb = null;
        $streetPart = $rest;
        if (str_contains($rest, ',')) {
            $segments = array_values(array_filter(array_map('trim', explode(',', $rest)), fn ($s) => $s !== ''));
            if (count($segments) >= 2) {
                $suburb = array_pop($segments);
                $streetPart = implode(', ', $segments);
            } elseif (count($segments) === 1) {
                $streetPart = $segments[0];
            }
        }

        // Level / unit prefixes may be in their own comma segment ("Level 10, 452 Flinders St").
        $streetPart = self::extractLevel($streetPart, $params);
        $streetPart = self::extractUnit($streetPart, $params);
        $streetPart = self::extractNumber($streetPart, $params);

        $tokens = $streetPart === '' ? [] : explode(' ', $streetPart);

        // Without a comma the suburb follows the street type: "12 Main St Hobart".
        if ($suburb === null && $tokens !== []) {
            [$words, $ambiguous] = VocusAddressStandard::streetTypeWords();
            for ($i = count($tokens) - 1; $i >= 1; $i--) {
                $w = strtoupper($tokens[$i]);
                if (isset($words[$w]) && ! isset($ambiguous[$w]) && $i < count($tokens) - 1 && count($tokens) - 1 - $i <= 3) {
                    $suburb = implode(' ', array_slice($tokens, $i + 1));
                    $tokens = array_slice($tokens, 0, $i + 1);
                    break;
                }
            }
        }

        // Street suffix ("... St North") then street type at the end.
        if (count($tokens) >= 3) {
            $suffix = VocusAddressStandard::streetSuffix(end($tokens));
            [$words] = VocusAddressStandard::streetTypeWords();
            if ($suffix !== null && isset($words[strtoupper($tokens[count($tokens) - 2])])) {
                $params['Main.StreetSuffix'] = $suffix;
                array_pop($tokens);
            }
        }

        if (count($tokens) >= 2) {
            $type = VocusAddressStandard::streetType(end($tokens));
            if ($type !== null) {
                $params['Main.StreetType'] = $type;
                array_pop($tokens);
            }
        }

        if ($tokens !== []) {
            $params['Main.StreetName'] = self::cut(strtoupper(implode(' ', $tokens)), VocusAddressStandard::MAX_STREET_NAME);
        }

        if ($suburb !== null && trim($suburb) !== '') {
            $params['Main.Suburb'] = self::cut(strtoupper(trim($suburb)), VocusAddressStandard::MAX_SUBURB);
        }

        return array_filter($params, fn ($v) => $v !== '' && $v !== null);
    }

    /** "Level 3, ..." / "L3 ..." / "Floor 2 ...": FloorType + FloorNumber. */
    private static function extractLevel(string $s, array &$params): string
    {
        $words = array_keys(VocusAddressStandard::levelTypeWords());
        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alt = implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words));

        if (preg_match('/^(' . $alt . ')\s*(\d{1,4})\s*(?:,|\/)?\s*(.+)$/i', $s, $m)
            && ($type = VocusAddressStandard::levelType($m[1])) !== null) {
            $params['Main.FloorType'] = $type;
            $params['Main.FloorNumber'] = (int) $m[2];

            return trim($m[3], " ,");
        }

        return $s;
    }

    /** "Unit 2, 99 ..." / "U2/99 ..." / "2A/99 ..." / "Shop 4 12 ...": unit type, number and suffix. */
    private static function extractUnit(string $s, array &$params): string
    {
        $words = array_keys(VocusAddressStandard::unitTypeWords());
        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alt = implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words));

        // Typed unit: "Unit 2A, 99 Example St", "U2/99 Example St", "Flat 5 12 Smith St".
        if (preg_match('/^(' . $alt . ')\s*(\d{1,5})([A-Za-z])?\s*(?:,|\/|\s)\s*(\d.*)$/i', $s, $m)
            && ($type = VocusAddressStandard::unitType($m[1])) !== null) {
            $params['Main.AddressType'] = $type;
            $params['Main.Unit1stNumber'] = (int) $m[2];
            if (($m[3] ?? '') !== '') {
                $params['Main.Unit1stSuffix'] = strtoupper($m[3]);
            }

            return trim($m[4], " ,");
        }

        // Untyped "2/99 Example St" or "2A/99B Example St".
        if (preg_match('/^(\d{1,5})([A-Za-z])?\s*\/\s*(\d.*)$/', $s, $m)) {
            $params['Main.Unit1stNumber'] = (int) $m[1];
            if (($m[2] ?? '') !== '') {
                $params['Main.Unit1stSuffix'] = strtoupper($m[2]);
            }

            return trim($m[3]);
        }

        return $s;
    }

    /** "99 Example St", "99B ...", "12-14 ...": street number(s) with optional suffix. */
    private static function extractNumber(string $s, array &$params): string
    {
        if (preg_match('/^(\d{1,5})([A-Za-z])?\s*-\s*(\d{1,5})([A-Za-z])?\s+(.+)$/', $s, $m)) {
            $params['Main.Street1stNumber'] = (int) $m[1];
            if (($m[2] ?? '') !== '') {
                $params['Main.Street1stNumberSuffix'] = strtoupper($m[2]);
            }
            $params['Main.Street2ndNumber'] = (int) $m[3];

            return trim($m[5]);
        }

        // The suffix letter must be a single letter followed by a space ("12A Main St", not "2nd Ave").
        if (preg_match('/^(\d{1,5})([A-Za-z])?\s+(.+)$/', $s, $m)) {
            $params['Main.Street1stNumber'] = (int) $m[1];
            if (($m[2] ?? '') !== '') {
                $params['Main.Street1stNumberSuffix'] = strtoupper($m[2]);
            }

            return trim($m[3]);
        }

        return $s;
    }

    private static function cut(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    /**
     * Build DIR lookup params from the manual form. Street type, unit type, level
     * type and state are normalised to the standard abbreviations (AVE => AV,
     * DRIVE => DR ...); names are upper-cased; "12A" / "2A" are split into number
     * and suffix. Unknown street types are passed through upper-cased (validation
     * rejects them before this point).
     *
     * @param array<string,mixed> $in street_number, street_name, street_type, suburb, state, postcode,
     *                                unit_number, unit_type, level_type, level_number, property_name
     * @return array<string,string|int>
     */
    public static function fromStructured(array $in): array
    {
        $params = [];
        $str = fn (string $k) => trim((string) ($in[$k] ?? ''));

        if (($u = $str('unit_number')) !== '' && preg_match('/^(\d{1,5})([A-Za-z])?$/', $u, $m)) {
            $params['Main.Unit1stNumber'] = (int) $m[1];
            if (($m[2] ?? '') !== '') {
                $params['Main.Unit1stSuffix'] = strtoupper($m[2]);
            }
            if (($t = VocusAddressStandard::unitType($str('unit_type'))) !== null) {
                $params['Main.AddressType'] = $t;
            }
        }

        if (($l = $str('level_number')) !== '' && ctype_digit($l)) {
            $params['Main.FloorNumber'] = (int) $l;
            if (($t = VocusAddressStandard::levelType($str('level_type'))) !== null) {
                $params['Main.FloorType'] = $t;
            }
        }

        if (($n = $str('street_number')) !== '' && preg_match('/^(\d{1,5})([A-Za-z]{1,3})?$/', $n, $m)) {
            $params['Main.Street1stNumber'] = (int) $m[1];
            if (($m[2] ?? '') !== '') {
                $params['Main.Street1stNumberSuffix'] = strtoupper($m[2]);
            }
        }

        if ($str('property_name') !== '') {
            $params['Main.PropertyName'] = self::cut(strtoupper($str('property_name')), 40);
        }

        $params['Main.StreetName'] = self::cut(strtoupper($str('street_name')), VocusAddressStandard::MAX_STREET_NAME);

        if ($str('street_type') !== '') {
            $params['Main.StreetType'] = VocusAddressStandard::streetType($str('street_type')) ?? strtoupper($str('street_type'));
        }
        if ($str('suburb') !== '') {
            $params['Main.Suburb'] = self::cut(strtoupper($str('suburb')), VocusAddressStandard::MAX_SUBURB);
        }
        if ($str('state') !== '') {
            $params['Main.State'] = VocusAddressStandard::state($str('state')) ?? strtoupper($str('state'));
        }
        if ($str('postcode') !== '') {
            $params['Main.PostCode'] = $str('postcode');
        }

        return array_filter($params, fn ($v) => $v !== '' && $v !== null);
    }
}
