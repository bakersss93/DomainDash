{{--
  Extra Create params that depend on the technology (Product Catalogue FIBRE 4.1):
  FTTB / FTTN NEW: VoicebandContinuity, CarrierID (FNN) and CentralSplitter (all compulsory / conditional);
  FTTP / HFC / FTTC: optional NBNCPEPlanID NBN professional install option (appendix 6.4).
  Needs $transfer, $techType, $nbnCpePlans, $lbl, $note, $req.
--}}
@php
    $tech   = $techType ?? (\App\Services\Vocus\VocusOptions::createServiceType((string) old('service_type', $service_type ?? '')) ?? '');
    $copperVoice = ! $transfer && in_array($tech, ['FTTB', 'FTTN'], true);
    $cpePlans    = ($nbnCpePlans[$tech] ?? []);
    $oldVc       = old('voiceband_continuity', '');
    $cpeDefault  = \App\Services\Vocus\VocusOptions::orderDefaults()['nbn_cpe'][$tech] ?? '';
@endphp
@if($copperVoice)
    <div id="lx-voice" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
        <div id="lx-vc-wrap">
            <label for="f-vc" style="{{ $lbl }}">Keep the existing phone service? (VoicebandContinuity) {!! $req !!}</label>
            <select id="f-vc" name="voiceband_continuity" class="dd-input" style="width:100%;">
                <option value="">Select...</option>
                <option value="TRUE" @selected($oldVc === 'TRUE')>Yes, continue the PSTN service</option>
                <option value="FALSE" @selected($oldVc === 'FALSE')>No, deactivate the PSTN service</option>
            </select>
            <p style="{{ $note }}">Choosing No deactivates the customer's PSTN service when NBN is delivered. This cannot be reversed. You must tell the customer first.</p>
        </div>
        <div id="lx-carrier-wrap" hidden>
            <label for="f-carrier" style="{{ $lbl }}">Phone number to keep (CarrierID, FNN) {!! $req !!}</label>
            <input id="f-carrier" type="text" name="carrier_id" value="{{ old('carrier_id') }}" class="dd-input" style="width:100%;font-family:monospace;" inputmode="numeric" maxlength="15" pattern="\d{2,15}">
            <p style="{{ $note }}">Digits only, for example 0299998888.</p>
        </div>
        <div>
            <label for="f-splitter" style="{{ $lbl }}">Central splitter (CentralSplitter) {!! $req !!}</label>
            <select id="f-splitter" name="central_splitter" class="dd-input" style="width:100%;">
                <option value="">Select...</option>
                <option value="TRUE" @selected(old('central_splitter') === 'TRUE')>Yes</option>
                <option value="FALSE" @selected(old('central_splitter') === 'FALSE')>No</option>
            </select>
        </div>
    </div>
    <p id="lx-new-note" style="{{ $note }}" hidden>A new copper pair always sends VoicebandContinuity FALSE.</p>
@endif
@if($cpePlans !== [] && (! $transfer || $tech === 'FTTC'))
    <div style="margin-top:12px;">
        <label for="f-nbncpe" style="{{ $lbl }}">NBN installation option (NBNCPEPlanID)</label>
        <select id="f-nbncpe" name="nbn_cpe_plan_id" class="dd-input" style="width:100%;">
            <option value="">{{ $tech === 'HFC' ? 'None (a new NTD is sent as HFCNTDNBNPROFINSTALL)' : 'None' }}</option>
            @foreach($cpePlans as $value => $cpe)
                @php $ships = ($cpe[2] ?? false) === true; @endphp
                <option value="{{ $value }}" @selected(old('nbn_cpe_plan_id', $cpeDefault) === $value) @disabled($ships)>{{ $cpe[0] }} ({{ $value }})@if($ships) - needs a shipping address, not supported yet @endif</option>
            @endforeach
        </select>
        <p style="{{ $note }}">Only {{ $tech }} options are listed. @if($tech === 'FTTP')All FTTP NTD installs are NBN professional install only. @endif Charges might apply. Replacement options need the NTD to replace selected in the line list. The default is set in Settings, Vocus, Ordering.</p>
    </div>
@endif
@if($copperVoice)
<script>
(function () {
    var vc = document.getElementById('f-vc'), wrap = document.getElementById('lx-carrier-wrap'), car = document.getElementById('f-carrier'),
        vcWrap = document.getElementById('lx-vc-wrap'), note = document.getElementById('lx-new-note'), choice = document.getElementById('lc-choice');
    function sync() {
        var isNew = choice && choice.value === 'new';
        vcWrap.hidden = isNew; note.hidden = !isNew;
        if (isNew) { vc.value = ''; }
        wrap.hidden = isNew || vc.value !== 'TRUE';
        car.disabled = wrap.hidden;
    }
    vc.addEventListener('change', sync);
    document.addEventListener('dd:line-render', sync);
    sync();
})();
</script>
@endif
