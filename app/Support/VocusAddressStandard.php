<?php

namespace App\Support;

/**
 * Vocus "Street Address Component and PostCode Standard" reference data.
 *
 * SOURCE: the vendor workbook "Vocus Street Address Component and PostCode
 * Standard.xlsx", sheet "ADDRESS COMPONENT" (Type / Abbreviation / Full Name /
 * AU Standard). The tables below were generated from that sheet; rows with no
 * "YES" in the AU Standard column are the non-standard variants (kept so that
 * user input such as AVE, CRES or BLVD can be normalised to the standard
 * abbreviation AV, CR, BVD). Regenerate from the workbook when Vocus issues a
 * new version. Postcodes are governed by Australia Post (sheet "POSTCODE").
 *
 * Field limits (StandardDataTypes.xsd / Vocus Data Types Definition):
 * Main.StreetName 1..25, Main.Suburb 1..40, Main.StreetType 1..8,
 * Main.PostCode 1..6, Main.State 1..10, Main.Unit/Street numbers 0..99999 with
 * a separate 1..3 (street) / 1 (unit) character suffix.
 */
final class VocusAddressStandard
{
    public const MAX_STREET_NAME = 25;
    public const MAX_SUBURB = 40;
    public const MAX_NUMBER = 99999;

    /** @var array<int,array{0:string,1:string,2:bool}> [abbreviation, full name, AU standard] */
    public const UNIT_TYPES = [
        ['AT', 'APARTMENT', true],
        ['F', 'FLAT', true],
        ['FY', 'FACTORY', true],
        ['MB', 'MARINE BERTH', true],
        ['OE', 'OFFICE', true],
        ['RM', 'ROOM', true],
        ['SD', 'SHED', true],
        ['SE', 'SUITE', true],
        ['SI', 'SITE', true],
        ['SL', 'STALL', true],
        ['SP', 'SHOP', true],
        ['U', 'UNIT', true],
        ['WE', 'WAREHOUSE', true],
        ['APT', 'APARTMENT', false],
        ['ATC', 'ATCO PORTABLE DWELLING', false],
        ['BAY', 'BAY', false],
        ['BLG', 'BUILDING', false],
        ['BLK', 'BLOCK', false],
        ['BS', 'BARRACKS', false],
        ['BT', 'BERTH', false],
        ['CB', 'CHAMBERS', false],
        ['CN', 'COUNTER', false],
        ['CRV', 'CARAVAN', false],
        ['CTG', 'COTTAGE', false],
        ['CX', 'COMPLEX', false],
        ['DUP', 'DUPLEX', false],
        ['ENT', 'ENTRANCE', false],
        ['FAR', 'FARM', false],
        ['FLT', 'FLAT', false],
        ['GTE', 'GATE', false],
        ['HG', 'HANGAR', false],
        ['HSE', 'HOUSE', false],
        ['IG', 'IGLOO', false],
        ['JT', 'JETTY', false],
        ['KSK', 'KIOSK', false],
        ['LN', 'LANE', false],
        ['MST', 'MAISONETTE', false],
        ['OFC', 'OFFICE', false],
        ['PHS', 'PENTHOUSE', false],
        ['PR', 'PIER', false],
        ['RE', 'RESIDENCE', false],
        ['SHD', 'SHED', false],
        ['SHP', 'SHOP', false],
        ['SIT', 'SITE', false],
        ['STE', 'SUITE', false],
        ['STL', 'STALL', false],
        ['STU', 'STUDIO', false],
        ['THS', 'TOWNHOUSE', false],
        ['TR', 'TIER', false],
        ['TWR', 'TOWER', false],
        ['UNT', 'UNIT', false],
        ['UNIT', 'UNIT', false],
        ['VIL', 'VILLA', false],
        ['WF', 'WHARF', false],
        ['WRD', 'WARD', false],
    ];

    /** @var array<int,array{0:string,1:string,2:bool}> [abbreviation, full name, AU standard] */
    public const LEVEL_TYPES = [
        ['FL', 'FLOOR', true],
        ['L', 'LEVEL', true],
        ['UG', 'UPPER GROUND FLOOR', true],
        ['LG', 'LOWER GROUND FLOOR', true],
        ['B', 'BASEMENT', true],
        ['M', 'MEZZANINE', true],
        ['BMT', 'BASEMENT', false],
        ['FLR', 'FLOOR', false],
        ['G', 'GROUND FLOOR', false],
        ['LVL', 'LEVEL', false],
    ];

    /** @var array<int,array{0:string,1:string,2:bool}> [abbreviation, full name, AU standard] */
    public const STATES = [
        ['AAT', 'AUSTRALIAN ANTARTIC TERRITORY', true],
        ['ACT', 'AUSTRALIAN CAPITAL TERRITORY', true],
        ['NSW', 'NEW SOUTH WALES', true],
        ['NT', 'NORTHERN TERRITORY', true],
        ['QLD', 'QUEENSLAND', true],
        ['SA', 'SOUTH AUSTRALIA', true],
        ['TAS', 'TASMANIA', true],
        ['VIC', 'VICTORIA', true],
        ['WA', 'WESTERN AUSTRALIA', true],
    ];

    /** @var array<int,array{0:string,1:string,2:bool}> [abbreviation, full name, AU standard] */
    public const STREET_SUFFIXES = [
        ['N', 'NORTH', true],
        ['S', 'SOUTH', true],
        ['E', 'EAST', true],
        ['W', 'WEST', true],
        ['L', 'LOWER', true],
        ['U', 'UPPER', true],
        ['NE', 'NORTH EAST', true],
        ['NW', 'NOTH WEST', true],
        ['SE', 'SOUTH EAST', true],
        ['SW', 'SOUTH WEST', true],
        ['CN', 'CENTRAL', true],
        ['EX', 'EXTENSION', true],
    ];

    /** @var array<int,array{0:string,1:string,2:bool}> [abbreviation, full name, AU standard] */
    public const STREET_TYPES = [
        ['ACCS', 'ACCESS', true],
        ['ALLY', 'ALLEY', true],
        ['ALWY', 'ALLEYWAY', true],
        ['AMBL', 'AMBLE', true],
        ['ANCG', 'ANCHORAGE', true],
        ['APP', 'APPROACH', true],
        ['ARC', 'ARCADE', true],
        ['ART', 'ARTERY', true],
        ['AV', 'AVENUE', true],
        ['BASN', 'BASIN', true],
        ['BCH', 'BEACH', true],
        ['BEND', 'BEND', true],
        ['BLK', 'BLOCK', true],
        ['BVD', 'BOULEVARD', true],
        ['BR', 'BRACE', true],
        ['BRAE', 'BRAE', true],
        ['BRK', 'BREAK', true],
        ['BDGE', 'BRIDGE', true],
        ['BDWY', 'BROADWAY', true],
        ['BROW', 'BROW', true],
        ['BYPA', 'BYPASS', true],
        ['BYWY', 'BYWAY', true],
        ['CAUS', 'CAUSEWAY', true],
        ['CTR', 'CENTRE', true],
        ['CNWY', 'CENTREWAY', true],
        ['CH', 'CHASE', true],
        ['CIR', 'CIRCLE', true],
        ['CLT', 'CIRCLET', true],
        ['CCT', 'CIRCUIT', true],
        ['CRCS', 'CIRCUS', true],
        ['CL', 'CLOSE', true],
        ['CLDE', 'COLONNADE', true],
        ['CMMN', 'COMMON', true],
        ['CON', 'CONCOURSE', true],
        ['CPS', 'COPSE', true],
        ['CNR', 'CORNER', true],
        ['CSO', 'CORSO', true],
        ['CT', 'COURT', true],
        ['CTYD', 'COURTYARD', true],
        ['COVE', 'COVE', true],
        ['CR', 'CRESCENT', true],
        ['CRST', 'CREST', true],
        ['CRSS', 'CROSS', true],
        ['CRSG', 'CROSSING', true],
        ['CRD', 'CROSSROAD', true],
        ['COWY', 'CROSSWAY', true],
        ['CUWY', 'CRUISEWAY', true],
        ['CDS', 'CUL-DE-SAC', true],
        ['CTTG', 'CUTTING', true],
        ['DALE', 'DALE', true],
        ['DELL', 'DELL', true],
        ['DEVN', 'DEVIATION', true],
        ['DIP', 'DIP', true],
        ['DSTR', 'DISTRIBUTOR', true],
        ['DR', 'DRIVE', true],
        ['DRWY', 'DRIVEWAY', true],
        ['EDGE', 'EDGE', true],
        ['ELB', 'ELBOW', true],
        ['END', 'END', true],
        ['ENT', 'ENTRANCE', true],
        ['ESP', 'ESPLANADE', true],
        ['EXP', 'EXPRESSWAY', true],
        ['EXTN', 'EXTENSION', true],
        ['FAWY', 'FAIRWAY', true],
        ['FTRK', 'FIRETRACK', true],
        ['FITR', 'FIRETRAIL', true],
        ['FLAT', 'FLAT', true],
        ['FOLW', 'FOLLOW', true],
        ['FTWY', 'FOOTWAY', true],
        ['FSHR', 'FORESHORE', true],
        ['FORM', 'FORMATION', true],
        ['FWY', 'FREEWAY', true],
        ['FRNT', 'FRONT', true],
        ['FRTG', 'FRONTAGE', true],
        ['GAP', 'GAP', true],
        ['GLD', 'GLADE', true],
        ['GLEN', 'GLEN', true],
        ['GRA', 'GRANGE', true],
        ['GRN', 'GREEN', true],
        ['GRND', 'GROUND', true],
        ['GR', 'GROVE', true],
        ['GLY', 'GULLY', true],
        ['HTS', 'HEIGHTS', true],
        ['HRD', 'HIGHROAD', true],
        ['HWY', 'HIGHWAY', true],
        ['HILL', 'HILL', true],
        ['INTG', 'INTERCHANGE', true],
        ['INTN', 'INTERSECTION', true],
        ['JNC', 'JUNCTION', true],
        ['KEY', 'KEY', true],
        ['L', 'LANE', true],
        ['LNWY', 'LANEWAY', true],
        ['LEES', 'LEES', true],
        ['LINE', 'LINE', true],
        ['LINK', 'LINK', true],
        ['LT', 'LITTLE', true],
        ['LKT', 'LOOKOUT', true],
        ['LOOP', 'LOOP', true],
        ['LWR', 'LOWER', true],
        ['MALL', 'MALL', true],
        ['MNDR', 'MEANDER', true],
        ['MEW', 'MEW', true],
        ['MEWS', 'MEWS', true],
        ['MWY', 'MOTORWAY', true],
        ['MT', 'MOUNT', true],
        ['NOOK', 'NOOK', true],
        ['OTLK', 'OUTLOOK', true],
        ['PDE', 'PARADE', true],
        ['PARK', 'PARK', true],
        ['PKLD', 'PARKLANDS', true],
        ['PWY', 'PARKWAY', true],
        ['PART', 'PART', true],
        ['PASS', 'PASS', true],
        ['PATH', 'PATH', true],
        ['PWAY', 'PATHWAY', true],
        ['PIAZ', 'PIAZZA', true],
        ['PL', 'PLACE', true],
        ['PLZA', 'PLAZA', true],
        ['PKT', 'POCKET', true],
        ['PNT', 'POINT', true],
        ['PORT', 'PORT', true],
        ['PROM', 'PROMENADE', true],
        ['QUAD', 'QUAD', true],
        ['QDGL', 'QUADRANGLE', true],
        ['QDRT', 'QUADRANT', true],
        ['RMBL', 'RAMBLE', true],
        ['RAMP', 'RAMP', true],
        ['RNGE', 'RANGE', true],
        ['RES', 'RESERVE', true],
        ['REST', 'REST', true],
        ['RTT', 'RETREAT', true],
        ['RIDE', 'RIDE', true],
        ['RDGE', 'RIDGE', true],
        ['RGWY', 'RIDGEWAY', true],
        ['ROWY', 'RIGHTOFWAY', true],
        ['RING', 'RING', true],
        ['RISE', 'RISE', true],
        ['RVR', 'RIVER', true],
        ['RUN', 'RUN', true],
        ['RVWY', 'RIVERWAY', true],
        ['RVRA', 'RIVIERA', true],
        ['RDSD', 'ROADSIDE', true],
        ['RDWY', 'ROADWAY', true],
        ['RNDE', 'RONDE', true],
        ['RSBL', 'ROSEBOWL', true],
        ['RTY', 'ROTARY', true],
        ['RND', 'ROUND', true],
        ['RTE', 'ROUTE', true],
        ['ROW', 'ROW', true],
        ['RUE', 'RUE', true],
        ['SDNG', 'SIDING', true],
        ['SLPE', 'SLOPE', true],
        ['SPUR', 'SPUR', true],
        ['SQ', 'SQUARE', true],
        ['STRS', 'STAIRS', true],
        ['SHWY', 'STATE HIGHWAY', true],
        ['STPS', 'STEPS', true],
        ['STRA', 'STRAND', true],
        ['ST', 'STREET', true],
        ['STRP', 'STRIP', true],
        ['SBWY', 'SUBWAY', true],
        ['TARN', 'TARN', true],
        ['TCE', 'TERRACE', true],
        ['THOR', 'THOROUGHFARE', true],
        ['TLWY', 'TOLLWAY', true],
        ['TOP', 'TOP', true],
        ['TOR', 'TOR', true],
        ['TWRS', 'TOWERS', true],
        ['TRK', 'TRACK', true],
        ['TRL', 'TRAIL', true],
        ['TRI', 'TRIANGLE', true],
        ['TKWY', 'TRUNKWAY', true],
        ['TURN', 'TURN', true],
        ['UPAS', 'UNDERPASS', true],
        ['VALE', 'VALE', true],
        ['VDCT', 'VIADUCT', true],
        ['VIEW', 'VIEW', true],
        ['VLLS', 'VILLAS', true],
        ['VSTA', 'VISTA', true],
        ['WADE', 'WADE', true],
        ['WK', 'WALK', true],
        ['WKWY', 'WALKWAY', true],
        ['WY', 'WAY', true],
        ['WHRF', 'WHARF', true],
        ['WYND', 'WYND', true],
        ['YARD', 'YARD', true],
        ['GDN', 'GARDEN', true],
        ['GDNS', 'GARDENS', true],
        ['GTE', 'GATE', true],
        ['GTES', 'GATES', true],
        ['QY', 'QUAY', true],
        ['QYS', 'QUAYS', true],
        ['RD', 'ROAD', true],
        ['RDS', 'ROADS', true],
        ['ARTL', 'ARTERIAL', false],
        ['AVE', 'AVENUE', false],
        ['BAY', 'BAY', false],
        ['BLV', 'BOULEVARD', false],
        ['BNDY', 'BOUNDARY', false],
        ['BNK', 'BANK', false],
        ['BRCE', 'BRACE', false],
        ['BRCH', 'BRANCH', false],
        ['BRDG', 'BRIDGE', false],
        ['BREA', 'BREA', false],
        ['BRKS', 'BARRACKS', false],
        ['BWL', 'BOWL', false],
        ['CNTN', 'CONNECTION', false],
        ['COMM', 'COMMUNITY', false],
        ['CORS', 'COURSE', false],
        ['CRCT', 'CIRCUIT', false],
        ['CRES', 'CRESCENT', false],
        ['CRF', 'CRIEF', false],
        ['CRK', 'CREEK', false],
        ['DRV', 'DRIVE', false],
        ['DWNS', 'DOWNS', false],
        ['EMNT', 'EASEMENT', false],
        ['EST', 'ESTATE', false],
        ['EXT', 'EXTENSION', false],
        ['EXWY', 'EXPRESSWAY', false],
        ['FAIR', 'FAIR', false],
        ['FOWL', 'FOLLOW', false],
        ['FTRL', 'FIRETRAIL', false],
        ['GTWY', 'GATEWAY', false],
        ['GV', 'GROVE', false],
        ['HLSD', 'HILLSIDE', false],
        ['HSE', 'HOUSE', false],
        ['HTH', 'HEATH', false],
        ['IS', 'ISLAND', false],
        ['JNCT', 'JUNCTION', false],
        ['KNLL', 'KNOLL', false],
        ['LANE', 'LANE', false],
        ['LDG', 'LANDING', false],
        ['LOCN', 'LOCATION', false],
        ['MEAD', 'MEAD', false],
        ['MKLD', 'MARKETLAND', false],
        ['MKTN', 'MARKETTOWN', false],
        ['MTN', 'MOUNTAIN', false],
        ['NR', 'NOT REQUIRED', false],
        ['OVAL', 'OVAL', false],
        ['PDSE', 'PARADISE', false],
        ['PEN', 'PENINSULA', false],
        ['PKWY', 'PARKWAY', false],
        ['PLAT', 'PLATEAU', false],
        ['PR', 'PIER', false],
        ['PUR', 'PURSUIT', false],
        ['RCH', 'REACH', false],
        ['RCSE', 'RACECOURSE', false],
        ['RTRN', 'RETURN', false],
        ['RWE', 'ROWE', false],
        ['SEC', 'SECTION', false],
        ['SND', 'SOUND', false],
        ['STGT', 'STRAIGHT', false],
        ['STN', 'STATION', false],
        ['STOP', 'STOP', false],
        ['SWY', 'SERVICE WAY', false],
        ['TRLR', 'TRAILER', false],
        ['TWR', 'TOWER', false],
        ['UPR', 'UPPER', false],
        ['VLGE', 'VILLAGE', false],
        ['VLY', 'VALLEY', false],
        ['WALK', 'WALK', false],
        ['WAY', 'WAY', false],
        ['WEST', 'WEST', false],
        ['WOOD', 'WOOD', false],
        ['WTRS', 'WATERS', false],
        ['CRT', 'CRESCENT', false],
        ['PLC', 'PLACE', false],
        ['DVE', 'DRIVE', false],
        ['BLVD', 'BOULEVARD', false],
        ['GVE', 'GROVE', false],
    ];

    /** @var array<string,array<string,string>> memo of "normalised input => standard abbreviation" per table */
    private static array $maps = [];

    /** Standard street type abbreviation for an abbreviation / full name, or null when unknown. */
    public static function streetType(?string $input): ?string
    {
        return self::normalise('street_type', self::STREET_TYPES, $input);
    }

    /** Standard building sub-unit type (Main.AddressType), e.g. "Unit"/"UNT" => "U". */
    public static function unitType(?string $input): ?string
    {
        return self::normalise('unit_type', self::UNIT_TYPES, $input);
    }

    /** Standard building level type (Main.FloorType), e.g. "Level"/"LVL" => "L". */
    public static function levelType(?string $input): ?string
    {
        return self::normalise('level_type', self::LEVEL_TYPES, $input);
    }

    public static function streetSuffix(?string $input): ?string
    {
        return self::normalise('street_suffix', self::STREET_SUFFIXES, $input);
    }

    /** Upper-case state code when it is in the standard, else null. */
    public static function state(?string $input): ?string
    {
        $v = strtoupper(trim((string) $input));

        return self::table('state', self::STATES)[$v] ?? null;
    }

    /** @return string[] standard abbreviations (for validation messages / selects) */
    public static function standardStreetTypes(): array
    {
        return self::standardAbbreviations(self::STREET_TYPES);
    }

    /** @return array<string,string> abbreviation => full name, AU-standard only (for select lists) */
    public static function streetTypeOptions(): array
    {
        return self::options(self::STREET_TYPES);
    }

    public static function unitTypeOptions(): array
    {
        return self::options(self::UNIT_TYPES);
    }

    public static function levelTypeOptions(): array
    {
        return self::options(self::LEVEL_TYPES);
    }

    public static function stateOptions(): array
    {
        return self::options(self::STATES);
    }

    /** @return string[] */
    public static function stateCodes(): array
    {
        return array_keys(self::options(self::STATES));
    }

    /**
     * Words a free-text parser may treat as a street type: every standard
     * abbreviation, every full name and every mappable non-standard variant,
     * each mapped to the standard abbreviation. Words whose abbreviation equals
     * their name (PARK, HILL, BEND ...) are flagged ambiguous in the second map.
     *
     * @return array{0:array<string,string>,1:array<string,bool>}
     */
    public static function streetTypeWords(): array
    {
        $words = self::table('street_type', self::STREET_TYPES);
        $ambiguous = [];
        foreach (self::STREET_TYPES as [$abbr, $name, $std]) {
            if ($std && $abbr === $name) {
                $ambiguous[$abbr] = true;
            }
        }

        return [$words, $ambiguous];
    }

    /** @return array<string,string> */
    public static function unitTypeWords(): array
    {
        return self::table('unit_type', self::UNIT_TYPES);
    }

    /** @return array<string,string> */
    public static function levelTypeWords(): array
    {
        return self::table('level_type', self::LEVEL_TYPES);
    }

    private static function normalise(string $name, array $rows, ?string $input): ?string
    {
        $v = strtoupper(trim((string) $input));

        return $v === '' ? null : (self::table($name, $rows)[$v] ?? null);
    }

    /**
     * Lookup table "UPPERCASE word => standard abbreviation". Standard rows map
     * both their abbreviation and full name to themselves; a non-standard row
     * maps to the standard abbreviation sharing its full name (when there is one).
     *
     * @return array<string,string>
     */
    private static function table(string $name, array $rows): array
    {
        if (isset(self::$maps[$name])) {
            return self::$maps[$name];
        }

        $byName = [];
        $map = [];
        foreach ($rows as [$abbr, $full, $std]) {
            if ($std) {
                $map[$abbr] = $abbr;
                $byName[$full] ??= $abbr;
                $map[$full] ??= $abbr;
            }
        }
        foreach ($rows as [$abbr, $full, $std]) {
            if (! $std && isset($byName[$full]) && ! isset($map[$abbr])) {
                $map[$abbr] = $byName[$full];
            }
        }

        return self::$maps[$name] = $map;
    }

    /** @return string[] */
    private static function standardAbbreviations(array $rows): array
    {
        return array_keys(self::options($rows));
    }

    /** @return array<string,string> */
    private static function options(array $rows): array
    {
        $out = [];
        foreach ($rows as [$abbr, $full, $std]) {
            if ($std) {
                $out[$abbr] ??= $full;
            }
        }

        return $out;
    }
}
