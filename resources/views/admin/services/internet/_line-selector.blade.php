{{--
  Line / port selection for the order and transfer forms.
  Needs: $qualification (VocusQualification output or null), $qualification_token, $service_class, $copper_pair_id,
  $transfer (bool), $lbl, $note, $req, and the form's old() input.

  Product Catalogue FIBRE 4.1: COPPER sends CopperPairID (an id, or NEW). NTD: a NEW order has no port param (Vocus
  auto-selects a free UNI-D port; FTTP may send NTDID, HFC sends NTDID or NEW); a transfer sends DataPortNumber 1-4
  (FTTP, Wireless) or NTDID (HFC).
--}}
@php

    $q        = $qualification;
    $group    = $q['technology_group'] ?? null;
    $pairs    = $q['copper_pairs'] ?? [];
    $ntds     = $q['ntds'] ?? [];
    $techType = \App\Services\Vocus\VocusOptions::createServiceType((string) ($q['service_type'] ?? old('service_type', $service_type ?? ''))) ?? 'FTTP';
    $portParm = trim((string) config('services.vocus.transfer_port_param', 'DataPortNumber')) ?: 'DataPortNumber';
    $wire     = fn ($ntd, $p) => (string) $p['port_number'];

    // Everything selectable, so a single option can be preselected (never guess between several).
    $options = [];
    if ($group === \App\Services\Vocus\VocusQualification::GROUP_COPPER) {
        foreach ($pairs as $p) {
            if ($p['copper_pair_id']) { $options[] = ['copper' => $p['copper_pair_id']]; }
        }
    } elseif ($group === \App\Services\Vocus\VocusQualification::GROUP_NTD) {
        foreach ($ntds as $ntd) {
            foreach ($ntd['ports'] as $p) {
                if (\App\Services\Vocus\VocusQualification::isSelectablePort($p, (bool) $transfer) && $p['port_number'] !== null) { $options[] = ['ntd' => $ntd['ntd_id'], 'port' => $p['port_number']]; }
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
    $bestCap     = \App\Services\Vocus\VocusQualification::lineCapability($q, false);
    $needsChoice = $options !== [];
    $allowNew    = ! $transfer;
    $badge       = 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;';
@endphp

<div style="grid-column:1/-1;">
@if(! $q)
    {{-- No qualification kept (opened without the qualify step, or it expired): manual entry. --}}
    <label for="f-copper" style="{{ $lbl }}">Copper Pair ID @if($transfer){!! $req !!}@endif</label>
    <input id="f-copper" type="text" name="copper_pair_id" value="{{ old('copper_pair_id', $copper_pair_id) }}"
           class="dd-input" style="width:100%;font-family:monospace;" maxlength="15" @if($transfer) required @endif
           @if($copper_pair_id !== '') readonly @endif>
    <p style="{{ $note }}">FTTB / FTTN / FTTC: the copper pair (CopperPairID is compulsory@if(! $transfer); type NEW to order a new pair@endif). Leave blank for other technologies.</p>
    @if($transfer)
        <label for="f-port" style="{{ $lbl }};margin-top:8px;">UNI-D port to move (FTTP / Wireless)</label>
        <input id="f-port" type="number" name="uni_port" min="1" max="4" value="{{ old('uni_port') }}" class="dd-input" style="width:8rem;">
        <label for="f-ntd" style="{{ $lbl }};margin-top:8px;">NTD ID (HFC, or FTTP with several NTDs)</label>
        <input id="f-ntd" type="text" name="ntd_id" value="{{ old('ntd_id') }}" class="dd-input" style="width:100%;font-family:monospace;" maxlength="30">
        <p style="{{ $note }}">Sent as {{ $portParm }} (1 to 4) and NTDID.</p>
    @endif
    <p style="{{ $note }}">Run <a href="{{ route('admin.services.internet.qualify') }}">Qualify Address</a> again to choose from the available lines and ports.</p>
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
            NBN books the technician appointment itself on the first available date when the order needs one. Create takes no appointment
            fields (Product Catalogue FIBRE 4.1); the slot and times come back from Vocus after the order (AppointmentSlot, StartDateTime,
            EndDateTime) and can be changed afterwards with FIBRE::Set APPOINTMENT-UPDATE (not offered in DomainDash yet).
        </x-ui.alert>
    </div>
    @if($techType === 'FTTC')
        <p style="{{ $note }}">FTTC: NCD self install needs a shipping address created with DIR::Create (CPEDirectoryID). That is not implemented, so only the NBN professional install option is offered.</p>
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
        var portParam = @json($portParm), group = @json($group), tech = @json($techType), isTransfer = @json((bool) $transfer),
            required = @json($transfer || $group === \App\Services\Vocus\VocusQualification::GROUP_COPPER || $techType === 'HFC');
        var modal = $('line-modal');
        // Max Mbps of the chosen line (null = unknown). Nothing chosen: the best line returned (copper) or unknown (NTD).
        var best = @json(['down' => $bestCap['download'], 'up' => $bestCap['upload']]);
        function num(v) { var n = parseInt(v, 10); return isNaN(n) ? null : n; }
        function publishCap(r) {
            var cap = r ? { down: num(r.getAttribute('data-max-down')), up: num(r.getAttribute('data-max-up')) } : { down: best.down, up: best.up };
            window.ddLineCap = cap;
            document.dispatchEvent(new CustomEvent('dd:line-capability', { detail: cap }));
        }
        function picked() { return modal.querySelector('input[name="line_pick"]:checked'); }
        function render() {
            var c = $('lc-choice').value, sum = $('lc-summary'), wire = $('lc-wire'), open = $('lc-open');
            wire.textContent = '';
            if (c === 'new') {
                sum.textContent = group === 'COPPER' ? 'Order a new line (new copper pair)' : (tech === 'HFC' ? 'Order a new NTD (professional install)' : 'Order a new line (Vocus selects the NTD and a free port)');
                wire.textContent = group === 'COPPER' ? 'Sent to Vocus as CopperPairID NEW.' : (tech === 'HFC' ? 'Sent to Vocus as NTDID NEW.' : 'No line or port parameter is sent: Vocus auto-selects.');
                open.textContent = 'Change';
            } else if (group === 'COPPER' && $('lc-copper').value) {
                sum.textContent = 'Copper pair ' + $('lc-copper').value;
                wire.textContent = 'Sent to Vocus as CopperPairID.';
                open.textContent = 'Change';
            } else if (group === 'NTD' && ($('lc-port').value || $('lc-ntd').value)) {
                sum.textContent = (isTransfer ? ($('lc-pname').value || ('UNI-D ' + $('lc-port').value)) : 'NTD') + (($('lc-ntd').value) ? ' on NTD ' + $('lc-ntd').value : '');
                if (isTransfer && tech !== 'HFC') { wire.textContent = 'Sent to Vocus as ' + portParam + ' ' + $('lc-port').value + (tech === 'FTTP' && $('lc-ntd').value ? ' and NTDID.' : '.'); }
                else { wire.textContent = 'Sent to Vocus as NTDID' + (isTransfer || tech === 'HFC' || tech === 'FTTP' ? '' : '') + '.'; }
                open.textContent = 'Change';
            } else {
                sum.textContent = required ? 'Nothing selected yet.' : (isTransfer ? 'Nothing selected yet.' : 'Optional: Vocus auto-selects the NTD and a free port.');
                open.textContent = 'Select line / port';
            }
            document.dispatchEvent(new CustomEvent('dd:line-render', { detail: { choice: c } }));
            $('lc-appt').hidden = !(c === 'new' || @json($q['needs_ntd_install'] ?? false));
        }
        var initial = null;
        // Preselect the radio that matches the current hidden values.
        var c0 = $('lc-choice').value;
        Array.prototype.forEach.call(modal.querySelectorAll('input[name="line_pick"]'), function (r) {
            var m = (c0 === 'new' && r.getAttribute('data-choice') === 'new') ||
                (c0 === 'existing' && group === 'COPPER' && r.getAttribute('data-copper') === $('lc-copper').value && r.getAttribute('data-copper') !== '') ||
                (c0 === 'existing' && group === 'NTD' && r.getAttribute('data-port') === $('lc-port').value && r.getAttribute('data-ntd') === $('lc-ntd').value && r.getAttribute('data-port') !== '');
            if (m) { r.checked = true; initial = r; }
        });
        publishCap(initial);
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
            publishCap(p);
            modal.querySelector('[data-modal-close]').click();
        });
        var form = $('lc-open').closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (required && !$('lc-choice').value && !($('lc-ntd').value)) { e.preventDefault(); $('lc-open').click(); }
            });
        }
        render();
    })();
    </script>
@endif
@include('admin.services.internet._line-extras', ['transfer' => $transfer, 'techType' => $techType])
</div>
