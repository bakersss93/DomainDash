<?php

namespace App\Services\Vocus;

/**
 * Turns the line / port chosen on the order or transfer form into Create params, checking it against the
 * qualification result when one is available.
 *
 * DOCUMENTED: COPPER technologies send CopperPairID (Examples 4.1.4 and 4.2.4).
 * UNVERIFIED: the NTD port param (config services.vocus.order_port_param, default DataPortID in the format
 * "{ntd_index}-UNID-{port}", from the Data Types example "1-UNID-2") and the "new line" param
 * (services.vocus.new_line_param, none by default). Neither is described for Create in the PDFs.
 */
final class VocusLineSelection
{
    /**
     * @param array<string,mixed>|null $qualification VocusQualification::fromParams() output, or null when unknown
     * @param array<string,mixed> $input line_choice, copper_pair_id, ntd_id, ntd_index, uni_port, port_name
     * @return array{params:array<string,string>,record:array<string,?string>,errors:array<string,string>,new_line:bool,group:string}
     */
    public static function resolve(?array $qualification, string $serviceType, array $input, bool $transfer): array
    {
        $group  = VocusQualification::group($serviceType, ! empty($qualification['copper_pairs']), ! empty($qualification['ntds']));
        $choice = ($input['line_choice'] ?? '') === 'new' ? 'new' : (($input['line_choice'] ?? '') === 'existing' ? 'existing' : '');
        $out    = ['params' => [], 'record' => ['copper_pair_id' => null, 'ntd_id' => null, 'uni_port' => null], 'errors' => [], 'new_line' => false, 'group' => $group];

        if ($group === VocusQualification::GROUP_OTHER) {
            return $out; // no device selection for other technologies (nothing documented)
        }

        if ($choice === 'new') {
            if ($transfer) {
                $out['errors']['line_choice'] = 'A transfer moves an existing line or port. Choose one from the list.';

                return $out;
            }
            $out['new_line'] = true;
            if ($param = trim((string) config('services.vocus.new_line_param'))) {
                $out['params'][$param] = (string) config('services.vocus.new_line_value', 'TRUE');
            }

            return $out;
        }

        return $group === VocusQualification::GROUP_COPPER
            ? self::copper($out, $qualification, $input, $transfer)
            : self::ntd($out, $qualification, $input, $transfer);
    }

    private static function copper(array $out, ?array $q, array $in, bool $transfer): array
    {
        $id    = trim((string) ($in['copper_pair_id'] ?? ''));
        $known = array_values(array_filter(array_column($q['copper_pairs'] ?? [], 'copper_pair_id')));

        if ($id === '') {
            // Existing behaviour: the pair is mandatory for a transfer; for a new order it is required only when
            // the qualification listed pairs (otherwise the order is for a new line).
            if ($transfer) {
                $out['errors']['copper_pair_id'] = 'Choose the copper pair to move.';
            } elseif ($known !== []) {
                $out['errors']['copper_pair_id'] = 'Select a line from the qualification result, or choose "Order a new line".';
            }

            return $out;
        }
        if ($known !== [] && ! in_array($id, $known, true)) {
            $out['errors']['copper_pair_id'] = 'That copper pair was not part of the qualification result for this address.';

            return $out;
        }

        $out['params']['CopperPairID']        = $id;
        $out['record']['copper_pair_id']      = $id;

        return $out;
    }

    private static function ntd(array $out, ?array $q, array $in, bool $transfer): array
    {
        $ntds    = $q['ntds'] ?? [];
        $number  = trim((string) ($in['uni_port'] ?? ''));
        $ntdId   = trim((string) ($in['ntd_id'] ?? ''));
        $offered = VocusQualification::dataPorts($ntds);

        if ($number === '') {
            if ($offered !== []) {
                $out['errors']['uni_port'] = 'Select a free data port, or choose "Order a new line / port".';
            } elseif ($transfer) {
                $out['errors']['uni_port'] = 'Choose the NTD port to move.';
            }

            return $out;
        }

        $index = (int) ($in['ntd_index'] ?? 0);
        $name  = trim((string) ($in['port_name'] ?? '')) ?: null;
        if ($ntds !== []) {
            $match = null;
            foreach ($offered as $p) {
                if ((string) $p['port_number'] === $number && ((string) ($p['ntd_id'] ?? '')) === $ntdId) {
                    $match = $p;
                    break;
                }
            }
            if (! $match) {
                $out['errors']['uni_port'] = 'That port is in use or was not part of the qualification result. Choose a free data port.';

                return $out;
            }
            $index = (int) $match['ntd_index'];
            $name  = $match['port_name'];
        }
        $index = max(1, $index);

        $out['record']['ntd_id']   = $ntdId !== '' ? $ntdId : null;
        $out['record']['uni_port'] = 'UNI-D-' . $number;

        if (config('services.vocus.send_port_param', true) && ($param = trim((string) config('services.vocus.order_port_param')))) {
            $out['params'][$param] = strtr((string) config('services.vocus.order_port_format', '{ntd_index}-UNID-{port}'), [
                '{ntd_index}' => (string) $index, '{port}' => $number, '{port_name}' => (string) $name, '{ntd_id}' => $ntdId,
            ]);
        }

        return $out;
    }
}
