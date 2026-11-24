<?php

namespace App\Services\Vocus;

/**
 * Normalises a FIBRE::Get QUALIFY response (Examples 4.1.2, Data Types "CopperPairRecord",
 * "NTDRecord", "NBNPortRecord").
 *
 * SPEC (documented):
 *  - Top level params: Result, ServiceType, ServiceClass, ConnectionType, Zone, DevelopmentCharge,
 *    CopperDisconnectionDate (Examples 4.1.2). PrimaryTechnology and HFCSelfInstall exist as data types.
 *  - CopperPairRecord is an FTTB/N record delivered as a CDATA XML fragment (Examples p18, Data Types).
 *  - NTDRecord is "Returned only for FTTP, FixedWireless, and HFC"; NBNPortRecord describes an NTD port with
 *    Available ("whether a port is free or not") and PortType DATA or VOICE (Data Types).
 *  - Examples p18: "for FTTP, we will get NBNPortRecords instead of CopperPairRecords".
 *
 * Settled by the FIBRE catalogue (4.2.1) and Data Types: QUALIFY Result is PASS / REJECTED / REJECTED-CVC / MANUAL;
 * ServiceType is FIBRE (= FTTP on Create), WIRELESS, FTTB, FTTN, HFC, FTTC or N/A; DataPort TRUE means a data port is
 * free at an FTTP / HFC NTD; the service class table is Vocus's (FIBRE appendix 6.2).
 *
 * INFERRED (not stated in the PDFs): that each record occurrence is its own Param, or that one CDATA may hold
 * several records (both are accepted); the Available value spellings (TRUE/FALSE/YES/NO/FREE/IN USE); the
 * technology grouping (FTTN/FTTB/FTTC use a copper pair; FTTP/HFC/Fixed Wireless use an NTD); the NTDRecord speed
 * meaning.
 */
final class VocusQualification
{
    public const GROUP_COPPER = 'COPPER';
    public const GROUP_NTD    = 'NTD';
    public const GROUP_OTHER  = 'OTHER';

    /** Vocus Service Class table (Product Catalogue FIBRE appendix 6.2): class => [technology, description]. */
    public const SERVICE_CLASSES = [
        0  => ['FTTP', 'Not serviceable. Please raise a ticket for further analysis.'],
        1  => ['FTTP', 'Serviceable. NTD shortfall. Infrastructure shortfall.'],
        2  => ['FTTP', 'Serviceable. NTD shortfall.'],
        3  => ['FTTP', 'Serviceable. No shortfall.'],
        4  => ['Fixed Wireless', 'Serviceable. Wireless NTD shortfall. Infrastructure shortfall.'],
        5  => ['Fixed Wireless', 'Serviceable. Wireless NTD shortfall.'],
        6  => ['Fixed Wireless', 'Serviceable. Wireless no shortfall.'],
        7  => ['Satellite', 'Not supported.'],
        8  => ['Satellite', 'Not supported.'],
        9  => ['Satellite', 'Not supported.'],
        10 => ['FTTB/N', 'Not serviceable. Future FTTB/N. Raise a ticket for further analysis.'],
        11 => ['FTTB/N', 'Serviceable. NTD shortfall. Infrastructure shortfall.'],
        12 => ['FTTB/N', 'Serviceable. NTD shortfall.'],
        13 => ['FTTB/N', 'Serviceable. No shortfall.'],
        20 => ['HFC', 'Not serviceable. Future HFC. Raise a ticket for further analysis.'],
        21 => ['HFC', 'Serviceable. TAP available. Infrastructure shortfall.'],
        22 => ['HFC', 'Serviceable. Lead in. Infrastructure shortfall.'],
        23 => ['HFC', 'Serviceable. Lead in. No infrastructure shortfall. No NTD.'],
        24 => ['HFC', 'Serviceable. No shortfall.'],
        30 => ['FTTC', 'Not serviceable. Future FTTC. Raise a ticket for further analysis.'],
        31 => ['FTTC', 'Serviceable. NCD shortfall. Infrastructure shortfall.'],
        32 => ['FTTC', 'Serviceable. NCD shortfall. Infrastructure shortfall.'],
        33 => ['FTTC', 'Serviceable. NCD shortfall.'],
        34 => ['FTTC', 'Serviceable. No shortfall.'],
    ];

    /** QUALIFY Result values (FIBRE 4.2.1) and what they mean. */
    public const RESULTS = [
        'PASS'         => 'Vocus is ready to sell.',
        'REJECTED'     => 'Vocus is unable to service this address.',
        'REJECTED-CVC' => 'Vocus can service the address but the NCL2 CVC is invalid.',
        'MANUAL'       => 'Please raise a support request with Vocus.',
    ];

    /**
     * @param array<string,mixed> $params raw QUALIFY params (strings, CDATA strings, or already decoded arrays)
     * @return array<string,mixed>
     */
    public static function fromParams(array $params): array
    {
        $copper = [];
        foreach (self::records($params['CopperPairRecord'] ?? null, 'CopperPairRecord') as $r) {
            $copper[] = self::copperPair($r);
        }

        $ntds = [];
        foreach (self::records($params['NTDRecord'] ?? null, 'NTDRecord') as $r) {
            $ntd = self::ntd($r);
            // Merge repeated NTDIDs (a repeated record must not duplicate the device).
            $key = self::ntdKey($ntd['ntd_id'], count($ntds));
            $ntds[$key] = ($ntds[$key] ?? []) + $ntd;
        }
        foreach (self::records($params['NBNPortRecord'] ?? null, 'NBNPortRecord') as $r) {
            $port = self::port($r);
            $key  = self::ntdKey($port['ntd_id'], count($ntds));
            if (! isset($ntds[$key])) {
                $ntds[$key] = self::ntd(['NTDID' => $port['ntd_id'], 'NTDVersion' => $port['ntd_version']]);
            }
            $ntds[$key]['ports'][] = $port;
        }

        $ntds = array_values($ntds);
        foreach ($ntds as $i => &$ntd) {
            $ntd['index'] = $i + 1;
            usort($ntd['ports'], fn ($a, $b) => ($a['port_number'] ?? 99) <=> ($b['port_number'] ?? 99));
        }
        unset($ntd);

        $serviceType = self::str($params['ServiceType'] ?? null);
        $primary     = self::str($params['PrimaryTechnology'] ?? null);
        $group       = self::group($serviceType ?? $primary, $copper !== [], $ntds !== []);

        return [
            'result'                    => self::str($params['Result'] ?? null),
            'service_type'              => $serviceType,
            'primary_technology'        => $primary,
            'service_class'             => self::str($params['ServiceClass'] ?? null),
            'service_class_info'        => self::serviceClassInfo(self::str($params['ServiceClass'] ?? null)),
            'connection_type'           => self::str($params['ConnectionType'] ?? null),
            'zone'                      => self::str($params['Zone'] ?? null),
            'development_charge'        => self::bool($params['DevelopmentCharge'] ?? null),
            'hfc_self_install'          => self::bool($params['HFCSelfInstall'] ?? null),
            'copper_disconnection_date' => self::str($params['CopperDisconnectionDate'] ?? null),
            'data_port_available'       => self::bool($params['DataPort'] ?? null),
            'alternative_technology'    => self::bool($params['AlternativeTechnology'] ?? null),
            'avc_id_match'              => self::bool($params['AVCIDMatch'] ?? null),
            'avc_id_target'             => self::str($params['AVCIDTarget'] ?? null),
            'current_epid'              => self::str($params['CurrentEPID'] ?? null),
            'activation_date'           => self::str($params['ActivationDate'] ?? null),
            'technology_group'          => $group,
            'copper_pairs'              => $copper,
            'ntds'                      => $ntds,
            // NTD technology with nothing returned: an NTD (and probably an appointment) will be needed.
            'needs_ntd_install'         => $group === self::GROUP_NTD && self::dataPorts($ntds) === [] && ! self::hasAnyPort($ntds),
        ];
    }

    /** Whether Vocus can sell at this address (Result PASS). */
    public static function passed(?array $q): bool
    {
        return strtoupper((string) ($q['result'] ?? '')) === 'PASS';
    }

    /**
     * COPPER (FTTN, FTTB, FTTC), NTD (FTTP, HFC, Fixed Wireless) or OTHER.
     * Driven by which records Vocus returned (a CopperPairRecord is copper even for an FTTB address, as the Data
     * Types describe it as "An FTTB/N Copper Pair Record"); the ServiceType decides when the records do not
     * (none, or both kinds).
     */
    public static function group(?string $technology, bool $hasCopper = false, bool $hasNtd = false): string
    {
        if ($hasCopper xor $hasNtd) {
            return $hasCopper ? self::GROUP_COPPER : self::GROUP_NTD;
        }

        $t = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $technology));
        if (preg_match('/FTT[NBC]/', $t)) {
            return self::GROUP_COPPER;
        }
        if (preg_match('/FTTP|FIBRE|HFC|FIXEDWIRELESS|^FW$|WIRELESS/', $t)) {
            return self::GROUP_NTD;
        }

        return self::GROUP_OTHER;
    }

    // ------------------------------------------------------------------ line speed capability

    /**
     * Upper bound in Mbps of a Vocus speed value. DownloadSpeed / UploadSpeed on a CopperPairRecord are a
     * min-max theoretical range ("50-100" gives 100); a single number is itself; a "Mbps" suffix is ignored;
     * a list ("25,50,100") gives its largest value. Anything with no number (N/A, blank, junk) gives null.
     */
    public static function parseMbps(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s*(?:mbps|mb\/s|mb|m)\b/i', '', (string) $value));
        if (! preg_match('/^\d+(?:\.\d+)?(?:\s*[-\x{2013},]\s*\d+(?:\.\d+)?)*$/u', $text) || ! preg_match_all('/\d+(?:\.\d+)?/', $text, $m)) {
            return null;
        }
        $max = (int) ceil(max(array_map('floatval', $m[0])));

        return $max > 0 ? $max : null;
    }

    /**
     * Max Mbps of one copper pair: the DownloadSpeed / UploadSpeed range ("Minimum/Maximum theoretical speed in
     * Mbps, e.g. 90-100", Data Types CopperPairRecord). TC2Speed is "Traffic Class 2 supported speed", the speed
     * of the TC2 (CVC) class, not a line rate, so it is shown but never used as a download limit.
     *
     * @param array<string,mixed> $pair a normalised pair (download_speed, upload_speed)
     * @return array{download:?int,upload:?int}
     */
    public static function copperCapability(array $pair): array
    {
        return [
            'download' => self::parseMbps($pair['download_speed'] ?? null),
            'upload'   => self::parseMbps($pair['upload_speed'] ?? null),
        ];
    }

    /** @param array<string,mixed> $ntd @return array{download:?int,upload:?int} */
    public static function ntdCapability(array $ntd): array
    {
        // The NTDRecord speed fields are not defined in the Data Types: treated as a cap only while enabled.
        if (! config('services.vocus.ntd_speed_is_cap', true)) {
            return ['download' => null, 'upload' => null];
        }

        return ['download' => self::parseMbps($ntd['download_speed'] ?? null), 'upload' => self::parseMbps($ntd['upload_speed'] ?? null)];
    }

    /**
     * Max download / upload Mbps for the line chosen on the order form; null means unknown (no restriction).
     *  - COPPER (FTTN / FTTB / FTTC): the chosen pair's range; "new line" or no pair chosen uses the best value
     *    across the returned pairs (per direction), unknown when none returned.
     *  - NTD (FTTP / HFC / Fixed Wireless): the NTDRecord speeds of the chosen port's NTD when Vocus returned them;
     *    a new line / port or no port chosen is unknown (no restriction).
     *
     * @param array<string,mixed>|null $q fromParams() output
     * @return array{download:?int,upload:?int}
     */
    public static function lineCapability(?array $q, bool $newLine = false, ?string $copperPairId = null, ?string $ntdId = null, ?int $port = null): array
    {
        $unknown = ['download' => null, 'upload' => null];
        if (! $q) {
            return $unknown;
        }
        $group = $q['technology_group'] ?? self::group($q['service_type'] ?? null, ! empty($q['copper_pairs']), ! empty($q['ntds']));

        if ($group === self::GROUP_COPPER) {
            $pairs = $q['copper_pairs'] ?? [];
            if (! $newLine && $copperPairId !== null && $copperPairId !== '') {
                foreach ($pairs as $p) {
                    if (($p['copper_pair_id'] ?? null) === $copperPairId) {
                        return self::copperCapability($p);
                    }
                }

                return $unknown;
            }
            $best = $unknown;
            foreach ($pairs as $p) {
                $c = self::copperCapability($p);
                foreach (['download', 'upload'] as $k) {
                    if ($c[$k] !== null && ($best[$k] === null || $c[$k] > $best[$k])) {
                        $best[$k] = $c[$k];
                    }
                }
            }

            return $best;
        }

        if ($group === self::GROUP_NTD && ! $newLine && $port !== null) {
            foreach ($q['ntds'] ?? [] as $ntd) {
                if ((string) ($ntd['ntd_id'] ?? '') !== (string) $ntdId) {
                    continue;
                }
                foreach ($ntd['ports'] ?? [] as $p) {
                    if (($p['port_number'] ?? null) === $port) {
                        return self::ntdCapability($ntd);
                    }
                }
            }
        }

        return $unknown;
    }

    /** @return array{label:string,description:string,source:string}|null */
    public static function serviceClassInfo(?string $class): ?array
    {
        if ($class === null || ! preg_match('/^\d{1,2}$/', $class) || ! isset(self::SERVICE_CLASSES[(int) $class])) {
            return null;
        }
        [$label, $description] = self::SERVICE_CLASSES[(int) $class];

        return ['label' => $label, 'description' => $description, 'source' => 'Vocus Product Catalogue FIBRE, appendix 6.2'];
    }

    /**
     * Data ports of type DATA (or unspecified); by default only those not known to be in use.
     *
     * @param list<array<string,mixed>> $ntds
     * @return list<array<string,mixed>>
     */
    public static function dataPorts(array $ntds, bool $includeInUse = false): array
    {
        $out = [];
        foreach ($ntds as $ntd) {
            foreach ($ntd['ports'] ?? [] as $p) {
                if (self::isSelectablePort($p, $includeInUse)) {
                    $out[] = $p + ['ntd_index' => $ntd['index'] ?? 1];
                }
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $port */
    public static function isSelectablePort(array $port, bool $includeInUse = false): bool
    {
        // A NEW order never picks a port (Vocus auto-selects a free one). A transfer moves the port the service uses
        // now, which is by definition in use, so $includeInUse is true there.
        return in_array($port['port_type'] ?? null, [null, 'DATA'], true) && ($includeInUse || ($port['available'] ?? null) !== false);
    }

    /** @param list<array<string,mixed>> $ntds */
    private static function hasAnyPort(array $ntds): bool
    {
        foreach ($ntds as $n) {
            if (($n['ports'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** Port label: the spec's PortName when given, otherwise "UNI-D n". */
    public static function portLabel(array $port): string
    {
        return $port['port_name'] ?: ($port['port_number'] !== null ? 'UNI-D ' . $port['port_number'] : 'Port');
    }

    // ------------------------------------------------------------------ record parsing

    /**
     * Turn whatever a record param holds (a CDATA string, several of them, or already decoded arrays)
     * into a list of flat string maps.
     *
     * @return list<array<string,string>>
     */
    public static function records(mixed $value, string $element): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_array($value)) {
            if ($value !== [] && ! array_is_list($value)) {
                // A single decoded record (or a decoded wrapper that holds records under its own element name).
                if (isset($value[$element])) {
                    return self::records($value[$element], $element);
                }

                return [self::flatten($value)];
            }
            $out = [];
            foreach ($value as $item) {
                array_push($out, ...self::records($item, $element));
            }

            return $out;
        }
        if (! is_string($value)) {
            return [];
        }

        $xml = trim((string) preg_replace('/<!\[CDATA\[|\]\]>/', '', $value));
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string('<root>' . preg_replace('/^<\?xml[^>]*\?>/', '', $xml) . '</root>', 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($doc === false) {
            return [];
        }

        $out   = [];
        $nodes = $doc->{$element};
        if (count($nodes) === 0) {
            $nodes = [$doc]; // fragment with bare fields and no wrapper element
        }
        foreach ($nodes as $node) {
            $flat = self::flatten(json_decode((string) json_encode($node), true) ?: []);
            if ($flat !== []) {
                $out[] = $flat;
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function flatten(array $record): array
    {
        $out = [];
        foreach ($record as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = trim((string) $v);
            } elseif ($v === [] || $v === null) {
                $out[(string) $k] = '';
            } elseif (is_array($v) && array_is_list($v) && ! array_filter($v, fn ($i) => ! is_scalar($i))) {
                $out[(string) $k] = implode(',', array_map(fn ($i) => trim((string) $i), $v)); // e.g. a repeated TC2Speed
            }
        }

        return $out;
    }

    private static function copperPair(array $r): array
    {
        $status = self::str($r['CopperPairStatus'] ?? null);

        $pair = [
            'copper_pair_id'     => self::str($r['CopperPairID'] ?? null),
            'status'             => $status !== null ? strtoupper($status) : null,
            'nbn_service_status' => self::str($r['NBNServiceStatus'] ?? null),
            'service_class'      => self::str($r['ServiceClass'] ?? null),
            'pots_interconnect'  => self::str($r['POTSInterconnect'] ?? null),
            'pots_match'         => self::bool($r['POTSMatch'] ?? null),
            'upload_speed'       => self::str($r['UploadSpeed'] ?? null),
            'download_speed'     => self::str($r['DownloadSpeed'] ?? null),
            'network_co_exist'   => self::bool($r['NetworkCoExist'] ?? null),
            'extra_charge'       => self::bool($r['ExtraCharge'] ?? null),
            'epid'               => self::str($r['EPID'] ?? null),
            'tc2_speed'          => self::str($r['TC2Speed'] ?? null),
        ];
        $cap = self::copperCapability($pair);

        return $pair + ['max_download_mbps' => $cap['download'], 'max_upload_mbps' => $cap['upload']];
    }

    private static function ntd(array $r): array
    {
        return [
            'ntd_id'            => self::str($r['NTDID'] ?? null),
            'ntd_type'          => self::str($r['NTDType'] ?? null),
            'ntd_version'       => self::str($r['NTDVersion'] ?? null),
            'battery'           => self::bool($r['Battery'] ?? null),
            'battery_monitor'   => self::bool($r['BatteryMonitor'] ?? null),
            'battery_date'      => self::str($r['BatteryDate'] ?? null),
            'upload_speed'      => self::str($r['UploadSpeed'] ?? null),
            'download_speed'    => self::str($r['DownloadSpeed'] ?? null),
            'remaining_upload'  => self::str($r['RemainingUpload'] ?? null),
            'remaining_download' => self::str($r['RemainingDownload'] ?? null),
            'mac'               => self::str($r['MAC'] ?? null),
            'ports'             => [],
        ];
    }

    private static function port(array $r): array
    {
        $number = self::str($r['PortNumber'] ?? null);
        $name   = self::str($r['PortName'] ?? null);
        if ($number === null && $name !== null && preg_match('/(\d+)\s*$/', $name, $m)) {
            $number = $m[1];
        }
        $type = self::str($r['PortType'] ?? null);

        return [
            'ntd_id'      => self::str($r['NTDID'] ?? null),
            'ntd_version' => self::str($r['NTDVersion'] ?? null),
            'port_number' => $number !== null && ctype_digit($number) ? (int) $number : null,
            'port_name'   => $name,
            'port_type'   => $type !== null ? strtoupper($type) : null,
            'available'   => self::available($r['Available'] ?? null),
            'epid'        => self::str($r['EPID'] ?? null),
            'mac'         => self::str($r['MAC'] ?? null),
        ];
    }

    private static function ntdKey(?string $ntdId, int $count): string
    {
        // Records with no NTDID all belong to one unnamed device.
        return $ntdId ?? '_unnamed';
    }

    private static function str(mixed $v): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private static function bool(mixed $v): ?bool
    {
        $s = strtoupper((string) self::str($v));

        return match ($s) {
            'TRUE', 'YES', 'Y', '1' => true,
            'FALSE', 'NO', 'N', '0' => false,
            default => null,
        };
    }

    /** Available = "whether a port is free or not": TRUE/FREE/AVAILABLE free, FALSE/USED/IN USE not free, anything else unknown. */
    private static function available(mixed $v): ?bool
    {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) self::str($v)));

        return match ($s) {
            'TRUE', 'YES', 'Y', '1', 'FREE', 'AVAILABLE' => true,
            'FALSE', 'NO', 'N', '0', 'USED', 'INUSE', 'UNAVAILABLE', 'OCCUPIED' => false,
            default => null,
        };
    }
}
