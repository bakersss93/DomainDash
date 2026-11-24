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
 * INFERRED (not stated in the PDFs): that each record occurrence is its own Param, or that one CDATA may hold
 * several records (both are accepted); the Available value spellings (TRUE/FALSE/YES/NO/FREE/IN USE); the
 * technology grouping (FTTN/FTTB/FTTC use a copper pair; FTTP/HFC/Fixed Wireless use an NTD); the service class
 * meanings below, which are NBN service class conventions and not Vocus documentation.
 */
final class VocusQualification
{
    public const GROUP_COPPER = 'COPPER';
    public const GROUP_NTD    = 'NTD';
    public const GROUP_OTHER  = 'OTHER';

    /** NBN service class conventions (not from the Vocus spec): [from, to, label]. */
    private const CLASS_RANGES = [
        [0, 3, 'FTTP'],
        [4, 6, 'Fixed Wireless'],
        [7, 9, 'Satellite'],
        [10, 14, 'FTTN / FTTB'],
        [20, 24, 'HFC'],
        [30, 34, 'FTTC'],
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
            'technology_group'          => $group,
            'copper_pairs'              => $copper,
            'ntds'                      => $ntds,
            // NTD technology with nothing returned: an NTD (and probably an appointment) will be needed.
            'needs_ntd_install'         => $group === self::GROUP_NTD && self::dataPorts($ntds) === [] && ! self::hasAnyPort($ntds),
        ];
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
        if (preg_match('/FTTP|HFC|FIXEDWIRELESS|^FW$|WIRELESS/', $t)) {
            return self::GROUP_NTD;
        }

        return self::GROUP_OTHER;
    }

    /** @return array{label:string,source:string}|null */
    public static function serviceClassInfo(?string $class): ?array
    {
        if ($class === null || ! preg_match('/^\d{1,2}$/', $class)) {
            return null;
        }
        $n = (int) $class;
        foreach (self::CLASS_RANGES as [$from, $to, $label]) {
            if ($n >= $from && $n <= $to) {
                return ['label' => $label, 'source' => 'NBN service class conventions (not from the Vocus specification)'];
            }
        }

        return null;
    }

    /**
     * Data ports a customer can be put on: type DATA (or unspecified) and not known to be in use.
     *
     * @param list<array<string,mixed>> $ntds
     * @return list<array<string,mixed>>
     */
    public static function dataPorts(array $ntds): array
    {
        $out = [];
        foreach ($ntds as $ntd) {
            foreach ($ntd['ports'] ?? [] as $p) {
                if (self::isSelectablePort($p)) {
                    $out[] = $p + ['ntd_index' => $ntd['index'] ?? 1];
                }
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $port */
    public static function isSelectablePort(array $port): bool
    {
        return in_array($port['port_type'] ?? null, [null, 'DATA'], true) && ($port['available'] ?? null) !== false;
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
            }
        }

        return $out;
    }

    private static function copperPair(array $r): array
    {
        $status = self::str($r['CopperPairStatus'] ?? null);

        return [
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
        ];
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
