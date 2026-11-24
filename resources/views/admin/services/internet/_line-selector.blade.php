{{--
  Line / port selection for the order and transfer forms.
  Needs: $qualification (VocusQualification output or null), $qualification_token, $service_class, $copper_pair_id,
  $transfer (bool), $lbl, $note, $req, and the form's old() input.

  COPPER: CopperPairID is the documented Create param (Examples 4.1.4 / 4.2.4).
  NTD: the port Create param is NOT documented; see config services.vocus.order_port_param (unverified).
--}}
@php

    $q        = $qualification;
    $group    = $q['technology_group'] ?? null;
    $pairs    = $q['copper_pairs'] ?? [];
    $ntds     = $q['ntds'] ?? [];
    $sendPort = (bool) config('services.vocus.send_port_param', true) && trim((string) config('services.vocus.order_port_param')) !== '';
    $portParm = trim((string) config('services.vocus.order_port_param'));
    $portFmt  = (string) config('services.vocus.order_port_format', '{ntd_index}-UNID-{port}');
    $wire     = fn ($ntd, $p) => strtr($portFmt, ['{ntd_index}' => $ntd['index'], '{port}' => $p['port_number'], '{port_name}' => (string) $p['port_name'], '{ntd_id}' => (string) $ntd['ntd_id']]);

    // Everything selectable, so a single option can be preselected (never guess between several).
    $options = [];
    if ($group === \App\Services\Vocus\VocusQualification::GROUP_COPPER) {
        foreach ($pairs as $p) {
            if ($p['copper_pair_id']) { $options[] = ['copper' => $p['copper_pair_id']]; }
        }
    } elseif ($group === \App\Services\Vocus\VocusQualification::GROUP_NTD) {
        foreach ($ntds as $ntd) {
            foreach ($ntd['ports'] as $p) {
                if (\App\Services\Vocus\VocusQualification::isSelectablePort($p) && $p['port_number'] !== null) { $options[] = ['ntd' => $ntd['ntd_id'], 'port' => $p['port_number']]; }
            }
        }
    }
    $choice  = old('line_choice', '');
    $cPair   = old('copper_pair_id', $copper_pair_id ?? '');
    $cNtd    = old('ntd_id', '');
    $cPort   = old('uni_port', '');
    if ($choice === '' && $cPair === '' && $cPort === '' && count($options) === 1) {
        $only  = $options[0];
        $choice = 'existing';
        $cPair = $only['copper'] ?? '';
        $cNtd  = $only['ntd'] ?? '';
        $cPort = $only['port'] ?? '';
    }
    $cIndex = old('ntd_index', '');
    $cName  = old('port_name', '');
    if ($cPort !== '' && $cIndex === '') {
        foreach ($ntds as $ntd) {
            foreach ($ntd['ports'] as $p) {
                if ((string) $p['port_number'] === (string) $cPort && (string) $ntd['ntd_id'] === (string) $cNtd) { $cIndex = $ntd['index']; $cName = $p['port_name']; }
            }
        }
    }
    $needsChoice = $options !== [];
    $allowNew    = ! $transfer;
    $badge       = 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;';
@endphp

<div style="grid-column:1/-1;">
@if(! $q)
    {{-- No qualification kept (opened without the qualify step, or it expired): manual copper pair entry as before. --}}
    <label for="f-copper" style="{{ $lbl }}">Copper Pair ID @if($transfer){!! $req !!}@endif</label>
    <input id="f-copper" type="text" name="copper_pair_id" value="{{ old('copper_pair_id', $copper_pair_id) }}"
           class="dd-input" style="width:100%;font-family:monospace;" maxlength="15" @if($transfer) required @endif
           @if($copper_pair_id !== '') readonly @endif>
    <p style="{{ $note }}">@if($transfer)Required: the copper pair to move.@else Filled from qualify for copper technologies; blank otherwise.@endif
        Run <a href="{{ route('admin.services.internet.qualify') }}">Qualify Address</a> again to choose from the available lines and ports.</p>
@elseif($group === \App\Services\Vocus\VocusQualification::GROUP_OTHER)
    <span style="{{ $lbl }}">Line / port</span>
    <x-ui.alert variant="info">{{ $q['service_type'] ?: 'This technology' }} has no copper pair or NTD port to choose. The order is placed without a line or port selection.</x-ui.alert>
@else
    <input type="hidden" name="qualification_token" value="{{ $qualification_token }}">
    <input type="hidden" name="service_class" value="{{ $service_class }}">
    <input type="hidden" name="line_choice" id="lc-choice" value="{{ $choice }}">
    <input type="hidden" name="copper_pair_id" id="lc-copper" value="{{ $cPair }}">
    <input type="hidden" name="ntd_id" id="lc-ntd" value="{{ $cNtd }}">
    <input type="hidden" name="ntd_index" id="lc-index" value="{{ $cIndex }}">
    <input type="hidden" name="uni_port" id="lc-port" value="{{ $cPort }}">
    <input type="hidden" name="port_name" id="lc-pname" value="{{ $cName }}">

    <span style="{{ $lbl }}">{{ $group === \App\Services\Vocus\VocusQualification::GROUP_COPPER ? 'Copper line' : 'NTD port' }} {!! ($needsChoice || $transfer) ? $req : '' !!}</span>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:var(--dd-surface);border:1px solid var(--dd-border);border-radius:8px;padding:10px 12px;">
        <div id="lc-summary" style="flex:1;min-width:12rem;font-size:13px;color:var(--dd-text);" aria-live="polite"></div>
        <button type="button" id="lc-open" class="btn-accent" style="padding:7px 14px;font-size:13px;" data-modal-open="line-modal">Select line / port</button>
    </div>
    <p id="lc-wire" style="{{ $note }}"></p>

    <div id="lc-appt" hidden style="margin-top:8px;">
        <x-ui.alert variant="info" title="New installation">
            @if($group === \App\Services\Vocus\VocusQualification::GROUP_NTD)NBN will need to install an NTD or connect a new port at this address, so an installation appointment may be required.@else A new copper line is a new connection, so an installation appointment may be required.@endif
            The Vocus documents do not list appointment fields on the order request; the appointment slot and times come back from Vocus after the order (AppointmentSlot, StartDateTime, EndDateTime) and appear on the service page.
        </x-ui.alert>
    </div>
    @if(($q['service_type'] ?? '') !== '' && stripos((string) $q['service_type'], 'FTTC') !== false)
        <p style="{{ $note }}">FTTC: for class 33 Vocus can ship an NCD for end-user self install (Examples 4.1.3 / 4.1.4, CPEDirectoryID / CPEPlanId / NBNCPEPlanId). This form does not send those yet.</p>
    @endif

    <x-ui.modal id="line-modal" title="Select line / port" size="lg">
        <div style="padding:4px 2px;max-height:60vh;overflow:auto;">
            @include('admin.services.internet._line-list', ['q' => $q, 'selectable' => true, 'transfer' => $transfer, 'wire' => $wire, 'choice' => $choice, 'cPair' => $cPair, 'cNtd' => $cNtd, 'cPort' => $cPort, 'allowNew' => $allowNew])
        </div>
        <x-slot:footer>
            <button type="button" class="btn-accent" id="lc-apply" style="padding:8px 16px;font-size:13px;">Use this selection</button>
            <button type="button" data-modal-close style="padding:8px 16px;font-size:13px;background:none;border:1px solid var(--dd-border-strong);border-radius:6px;color:var(--dd-text);cursor:pointer;">Cancel</button>
        </x-slot:footer>
    </x-ui.modal>

    <script>
    (function () {
        var $ = function (id) { return document.getElementById(id); };
        var sendPort = @json($sendPort), portParam = @json($portParm), group = @json($group), required = @json($needsChoice || $transfer);
        var modal = $('line-modal');
        function picked() { return modal.querySelector('input[name="line_pick"]:checked'); }
        function render() {
            var c = $('lc-choice').value, sum = $('lc-summary'), wire = $('lc-wire'), open = $('lc-open');
            wire.textContent = '';
            if (c === 'new') {
                sum.textContent = group === 'COPPER' ? 'Order a new line (no existing copper pair)' : 'Order a new line / port (NTD install or new port)';
                open.textContent = 'Change';
            } else if (group === 'COPPER' && $('lc-copper').value) {
                sum.textContent = 'Copper pair ' + $('lc-copper').value;
                wire.textContent = 'Sent to Vocus as CopperPairID.';
                open.textContent = 'Change';
            } else if (group === 'NTD' && $('lc-port').value) {
                var p = picked();
                sum.textContent = ($('lc-pname').value || ('UNI-D ' + $('lc-port').value)) + (($('lc-ntd').value) ? ' on NTD ' + $('lc-ntd').value : '');
                var w = p ? p.getAttribute('data-wire') : '';
                wire.textContent = sendPort && w ? 'Sent to Vocus as ' + portParam + ' ' + w + ' (port parameter not documented by Vocus: unverified).' : 'The port choice is stored here but not sent to Vocus (port parameter disabled).';
                open.textContent = 'Change';
            } else {
                sum.textContent = required ? 'Nothing selected yet.' : 'No line or port needed: Vocus returned none for this address.';
                open.textContent = 'Select line / port';
            }
            $('lc-appt').hidden = !(c === 'new' || @json($q['needs_ntd_install'] ?? false));
        }
        // Preselect the radio that matches the current hidden values.
        var c0 = $('lc-choice').value;
        Array.prototype.forEach.call(modal.querySelectorAll('input[name="line_pick"]'), function (r) {
            var m = (c0 === 'new' && r.getAttribute('data-choice') === 'new') ||
                (c0 === 'existing' && group === 'COPPER' && r.getAttribute('data-copper') === $('lc-copper').value && r.getAttribute('data-copper') !== '') ||
                (c0 === 'existing' && group === 'NTD' && r.getAttribute('data-port') === $('lc-port').value && r.getAttribute('data-ntd') === $('lc-ntd').value && r.getAttribute('data-port') !== '');
            if (m) { r.checked = true; }
        });
        $('lc-apply').addEventListener('click', function () {
            var p = picked();
            if (!p) { return; }
            var isNew = p.getAttribute('data-choice') === 'new';
            $('lc-choice').value = isNew ? 'new' : 'existing';
            $('lc-copper').value = isNew ? '' : (p.getAttribute('data-copper') || '');
            $('lc-ntd').value = isNew ? '' : (p.getAttribute('data-ntd') || '');
            $('lc-index').value = isNew ? '' : (p.getAttribute('data-index') || '');
            $('lc-port').value = isNew ? '' : (p.getAttribute('data-port') || '');
            $('lc-pname').value = isNew ? '' : (p.getAttribute('data-pname') || '');
            render();
            modal.querySelector('[data-modal-close]').click();
        });
        var form = $('lc-open').closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (required && !$('lc-choice').value) { e.preventDefault(); $('lc-open').click(); }
            });
        }
        render();
    })();
    </script>
@endif
</div>
