<?php

namespace App\Services\Vocus;

/**
 * Turns the line / port / install choices on the order or transfer form into FIBRE::Create params, checking them
 * against the qualification result when one is available. Everything here follows Product Catalogue FIBRE 4.1:
 *
 *  - COPPER (FTTB, FTTN, FTTC): CopperPairID is compulsory; send the pair's id, or the literal NEW to order a new
 *    pair with NBN Co. FTTB / FTTN NEW orders also need VoicebandContinuity (TRUE / FALSE; FALSE when the pair is
 *    NEW), CarrierID (the FNN, only with TRUE) and CentralSplitter (TRUE / FALSE). A transfer sends CopperPairID only.
 *  - NTD (FTTP, WIRELESS, HFC): a NEW order has no port param ("UNI-D Port: auto selected, a free port is required").
 *    FTTP may send NTDID to choose among several NTDs; HFC always sends NTDID (an NTD id, or NEW for a new NTD with
 *    NBN professional install and NBNCPEPlanID HFCNTDNBNPROFINSTALL). A TRANSFER / OUTSTANDING order must send the
 *    UNI-D port to move as DataPortNumber (integer 1 to 4) for FTTP and WIRELESS; HFC sends NTDID.
 *  - NBNCPEPlanID (appendix 6.4) selects an NTD / NCD install option; only the professional-install values that need
 *    no CPEDirectoryID are offered.
 */
final class VocusLineSelection
{
    /**
     * @param array<string,mixed>|null $qualification VocusQualification::fromParams() output, or null when unknown
     * @param array<string,mixed> $input line_choice, copper_pair_id, ntd_id, ntd_index, uni_port, port_name,
     *                                   voiceband_continuity, carrier_id, central_splitter, nbn_cpe_plan_id
     * @return array{params:array<string,string>,record:array<string,?string>,errors:array<string,string>,new_line:bool,group:string,tech:?string}
     */
    public static function resolve(?array $qualification, string $serviceType, array $input, bool $transfer, string $scope = 'RESELLER-CONNECT'): array
    {
        $group  = VocusQualification::group($serviceType, ! empty($qualification['copper_pairs']), ! empty($qualification['ntds']));
        $tech   = VocusOptions::createServiceType($serviceType);
        $choice = ($input['line_choice'] ?? '') === 'new' ? 'new' : (($input['line_choice'] ?? '') === 'existing' ? 'existing' : '');
        $out    = ['params' => [], 'record' => ['copper_pair_id' => null, 'ntd_id' => null, 'uni_port' => null], 'errors' => [], 'new_line' => false, 'group' => $group, 'tech' => $tech];

        if ($group === VocusQualification::GROUP_OTHER) {
            return $out;
        }
        if ($choice === 'new' && $transfer) {
            $out['errors']['line_choice'] = 'A transfer moves an existing line or port. Choose one from the list.';

            return $out;
        }

        $out = $group === VocusQualification::GROUP_COPPER
            ? self::copper($out, $qualification, $input, $transfer, $choice, $tech)
            : self::ntd($out, $qualification, $input, $transfer, $choice, $tech ?? 'FTTP');

        return self::nbnCpePlan($out, $input, $transfer, $tech, $scope);
    }

    /**
     * Max Mbps the chosen line can carry (null = unknown, no restriction). Call after resolve() found no errors.
     * Copper: the chosen pair, else the best across returned pairs (also for "new line"). NTD: the chosen port's NTD
     * speeds when Vocus returned them, else unknown.
     *
     * @param array<string,mixed>|null $qualification
     * @param array<string,mixed> $input line_choice, copper_pair_id, ntd_id, uni_port
     * @return array{download:?int,upload:?int}
     */
    public static function capability(?array $qualification, array $input): array
    {
        $new  = ($input['line_choice'] ?? '') === 'new';
        $port = trim((string) ($input['uni_port'] ?? ''));

        return VocusQualification::lineCapability(
            $qualification,
            $new,
            trim((string) ($input['copper_pair_id'] ?? '')) ?: null,
            trim((string) ($input['ntd_id'] ?? '')) ?: null,
            ctype_digit($port) ? (int) $port : null,
        );
    }

    /**
     * Why a plan cannot run on a line with this capability, or null when it can (unknown speeds never block).
     *
     * @param array{download:?int,upload:?int} $cap
     */
    public static function planProblem(?int $planDown, ?int $planUp, array $cap, string $planName): ?string
    {
        $tooFast = ($planDown !== null && $cap['download'] !== null && $planDown > $cap['download'])
            || ($planUp !== null && $cap['upload'] !== null && $planUp > $cap['upload']);
        if (! $tooFast) {
            return null;
        }

        return sprintf(
            'This line supports up to %s Mbps, but plan "%s" needs %s Mbps. Choose a plan the line can support.',
            ($cap['download'] ?? '?') . '/' . ($cap['upload'] ?? '?'),
            $planName,
            ($planDown ?? '?') . '/' . ($planUp ?? '?')
        );
    }

    private static function copper(array $out, ?array $q, array $in, bool $transfer, string $choice, ?string $tech): array
    {
        $id    = trim((string) ($in['copper_pair_id'] ?? ''));
        $known = array_values(array_filter(array_column($q['copper_pairs'] ?? [], 'copper_pair_id')));
        $isNew = $choice === 'new';

        if ($isNew) {
            $out['new_line']                 = true;
            $out['params']['CopperPairID']   = 'NEW'; // "Send NEW to order new Copper Pair with NBNco"
        } elseif ($id === '') {
            $out['errors']['copper_pair_id'] = $transfer
                ? 'Choose the copper pair to move.'
                : 'Select a line from the qualification result, or choose "Order a new line".';

            return $out;
        } elseif ($known !== [] && ! in_array($id, $known, true)) {
            $out['errors']['copper_pair_id'] = 'That copper pair was not part of the qualification result for this address.';

            return $out;
        } else {
            $out['params']['CopperPairID']   = $id;
            $out['record']['copper_pair_id'] = $id;
        }

        if ($transfer || ! in_array($tech, ['FTTB', 'FTTN'], true)) {
            return $out;
        }

        // FTTB / FTTN NEW orders: VoicebandContinuity (1..1) and CentralSplitter (1..1); CarrierID with continuity.
        $splitter = strtoupper(trim((string) ($in['central_splitter'] ?? '')));
        if (! in_array($splitter, ['TRUE', 'FALSE'], true)) {
            $out['errors']['central_splitter'] = 'Choose whether a central splitter is required.';
        } else {
            $out['params']['CentralSplitter'] = $splitter;
        }

        if ($isNew) {
            $out['params']['VoicebandContinuity'] = 'FALSE'; // "Send FALSE when ordering new Copper Pair"

            return $out;
        }
        $vc = strtoupper(trim((string) ($in['voiceband_continuity'] ?? '')));
        if (! in_array($vc, ['TRUE', 'FALSE'], true)) {
            $out['errors']['voiceband_continuity'] = 'Choose whether to keep the existing phone (PSTN) service. If you do not, it is deactivated and this cannot be reversed.';

            return $out;
        }
        $out['params']['VoicebandContinuity'] = $vc;
        if ($vc === 'TRUE') {
            $carrier = trim((string) ($in['carrier_id'] ?? ''));
            if (! preg_match(VocusOptions::CARRIER_ID_REGEX, $carrier)) {
                $out['errors']['carrier_id'] = 'Enter the phone number (FNN) to keep, digits only, 2 to 15 characters.';
            } else {
                $out['params']['CarrierID'] = $carrier;
            }
        }

        return $out;
    }

    private static function ntd(array $out, ?array $q, array $in, bool $transfer, string $choice, string $tech): array
    {
        $ntds    = $q['ntds'] ?? [];
        $ntdId   = trim((string) ($in['ntd_id'] ?? ''));
        $number  = trim((string) ($in['uni_port'] ?? ''));
        $isNew   = $choice === 'new';

        // The NTD id, when chosen, must be one Vocus listed for this address.
        if ($ntdId !== '' && $ntds !== [] && ! in_array($ntdId, array_column($ntds, 'ntd_id'), true)) {
            $out['errors']['ntd_id'] = 'That NTD was not part of the qualification result for this address.';

            return $out;
        }

        if ($transfer) {
            if ($tech === 'HFC') {
                if ($ntdId === '') {
                    $out['errors']['ntd_id'] = 'Choose the NTD that carries the service to transfer.';

                    return $out;
                }
                $out['params']['NTDID'] = $ntdId;
                $out['record']['ntd_id'] = $ntdId;

                return $out;
            }
            // FTTP / WIRELESS: DataPortNumber 1..1, integer 1 to 4 (the in-use port of the service being moved).
            if (! ctype_digit($number) || (int) $number < 1 || (int) $number > 4) {
                $out['errors']['uni_port'] = 'Choose the UNI-D port to move (1 to 4).';

                return $out;
            }
            if ($ntds !== [] && ! self::portListed($ntds, (int) $number, $ntdId)) {
                $out['errors']['uni_port'] = 'That port was not part of the qualification result. Choose a data port from the list.';

                return $out;
            }
            $out['params'][trim((string) config('services.vocus.transfer_port_param', 'DataPortNumber')) ?: 'DataPortNumber'] = (string) (int) $number;
            $out['record']['uni_port'] = 'UNI-D-' . (int) $number;
            if ($ntdId !== '' && $tech === 'FTTP') {
                $out['params']['NTDID']  = $ntdId; // FTTP OUTSTANDING on premises with several NTDs
                $out['record']['ntd_id'] = $ntdId;
            }

            return $out;
        }

        // NEW order.
        if ($tech === 'HFC') {
            if ($isNew || ($ntdId === '' && VocusQualification::dataPorts($ntds) === [] && ! array_filter(array_column($ntds, 'ntd_id')))) {
                $out['new_line']         = true;
                $out['params']['NTDID']  = 'NEW';
                // A chosen option is validated by nbnCpePlan() (technology, scope, no shipping); none chosen = the NBN
                // professional install default.
                if (trim((string) ($in['nbn_cpe_plan_id'] ?? '')) === '') {
                    $out['params']['NBNCPEPlanID'] = 'HFCNTDNBNPROFINSTALL';
                }

                return $out;
            }
            if ($ntdId === '') {
                $out['errors']['ntd_id'] = 'Select the NTD to provision on, or choose "Order a new NTD".';

                return $out;
            }
            $out['params']['NTDID']  = $ntdId;
            $out['record']['ntd_id'] = $ntdId;

            return $out;
        }

        if ($isNew) {
            $out['new_line'] = true;

            return $out; // new AVC: Vocus picks the NTD and a free UNI-D port
        }
        if ($ntdId !== '' && $tech === 'FTTP') {
            $out['params']['NTDID']  = $ntdId; // "On premises with multiple NTD, pass the NTD ID to provision"
            $out['record']['ntd_id'] = $ntdId;
        }

        return $out;
    }

    /** Whether an NTD data port with this number exists in the qualification (any availability: a transfer moves a used port). */
    private static function portListed(array $ntds, int $number, string $ntdId): bool
    {
        foreach ($ntds as $ntd) {
            if ($ntdId !== '' && (string) ($ntd['ntd_id'] ?? '') !== $ntdId) {
                continue;
            }
            foreach ($ntd['ports'] ?? [] as $p) {
                if (($p['port_number'] ?? null) === $number && in_array($p['port_type'] ?? null, [null, 'DATA'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** NBNCPEPlanID (appendix 6.4): validated against the technology, scope and (for -REPLACE) the NTDID. */
    private static function nbnCpePlan(array $out, array $in, bool $transfer, ?string $tech, string $scope): array
    {
        $plan = trim((string) ($in['nbn_cpe_plan_id'] ?? ''));
        if ($plan === '' || isset($out['params']['NBNCPEPlanID'])) {
            return $out;
        }
        $allowed = VocusOptions::NBN_CPE_PLANS[$tech ?? ''][$plan] ?? null;
        if (! $allowed || ! in_array($scope, $allowed[1], true) || ($transfer && $tech !== 'FTTC')) {
            $out['errors']['nbn_cpe_plan_id'] = 'That NBN install option is not available for this technology, scope or order type.';

            return $out;
        }
        if (($allowed[2] ?? false) === true) {
            $out['errors']['nbn_cpe_plan_id'] = 'That install option ships equipment and needs a shipping address (CPEDirectoryID), which is not supported yet. Choose an NBN professional install option.';

            return $out;
        }
        if (VocusOptions::isReplacementCpePlan($plan) && empty($out['params']['NTDID'])) {
            $out['errors']['nbn_cpe_plan_id'] = 'Replacing an NTD needs the NTD to replace. Select it in the line list.';

            return $out;
        }
        $out['params']['NBNCPEPlanID'] = $plan;
        $out['new_line'] = $out['new_line'] || ! $transfer;

        return $out;
    }
}
