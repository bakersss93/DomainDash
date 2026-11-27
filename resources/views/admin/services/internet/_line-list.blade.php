{{--
  Lines (copper pairs) or NTD ports from a qualification result. Server-rendered and escaped by Blade.
  $selectable true: radios (used in the order / transfer modal); false: a read-only list.
--}}
@php

    $group    = $q['technology_group'] ?? \App\Services\Vocus\VocusQualification::GROUP_OTHER;
    $badge    = 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;';
    $card     = 'display:flex;gap:10px;align-items:flex-start;padding:10px 12px;margin-bottom:8px;border:1px solid var(--dd-border);border-radius:8px;background:var(--dd-surface);color:var(--dd-text);font-size:13px;';
    $disabled = 'opacity:.6;background:var(--dd-surface-soft);';
    $yn       = fn ($v) => $v === true ? 'Yes' : ($v === false ? 'No' : null);
@endphp

@if($group === \App\Services\Vocus\VocusQualification::GROUP_COPPER)
    @forelse($q['copper_pairs'] as $p)
        @php $id = $p['copper_pair_id']; $cap = \App\Services\Vocus\VocusQualification::copperCapability($p); @endphp
        <label style="{{ $card }}cursor:{{ $selectable && $id ? 'pointer' : 'default' }};">
            @if($selectable)
                <input type="radio" name="line_pick" form="line-pick-none" data-copper="{{ $id }}" data-max-down="{{ $cap['download'] }}" data-max-up="{{ $cap['upload'] }}" @disabled(! $id) @checked($choice === 'existing' && $cPair === $id)>
            @endif
            <span style="flex:1;">
                <strong style="font-family:monospace;">{{ $id ?: 'No CopperPairID returned' }}</strong>
                @if($p['status'])<span style="{{ $badge }}background:var(--dd-status-muted-bg);color:var(--dd-status-muted-fg);margin-left:6px;">{{ $p['status'] }}</span>@endif
                @if($p['nbn_service_status'])<span style="{{ $badge }}background:var(--dd-status-info-bg);color:var(--dd-status-info-fg);margin-left:4px;">{{ $p['nbn_service_status'] }}</span>@endif
                @if($p['extra_charge'])<span style="{{ $badge }}background:var(--dd-status-warning-bg);color:var(--dd-status-warning-fg);margin-left:4px;">Extra charge</span>@endif
                <span style="display:block;color:var(--dd-text-soft);font-size:12px;margin-top:3px;">
                    @if($p['service_class'])Class {{ $p['service_class'] }} · @endif
                    @if($p['download_speed'])↓ {{ $p['download_speed'] }} Mbps @endif
                    @if($p['upload_speed'])/ ↑ {{ $p['upload_speed'] }} Mbps @endif
                    @if($p['pots_interconnect'] && $p['pots_interconnect'] !== 'N/A')· POTS {{ $p['pots_interconnect'] }}@endif
                </span>
                @if($cap['download'] !== null || $cap['upload'] !== null)
                    <span style="display:block;font-size:12px;margin-top:3px;font-weight:600;">Max line speed: ↓ {{ $cap['download'] ?? '?' }} / ↑ {{ $cap['upload'] ?? '?' }} Mbps</span>
                @endif
                @if($p['network_co_exist'])
                    <span style="display:block;font-size:12px;margin-top:3px;color:var(--dd-status-warning-fg);">Network co-exist: not every FNN has been cut over to the node, minimum speed is limited to 12M.</span>
                @endif
                @if($p['status'] === 'N/A')
                    <span style="display:block;font-size:12px;margin-top:3px;color:var(--dd-text-soft);">N/A: the copper pair has been transitioned to NBN Co.</span>
                @endif
            </span>
        </label>
    @empty
        <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 8px;">Vocus returned no existing copper pairs for this address.</p>
    @endforelse
@elseif($group === \App\Services\Vocus\VocusQualification::GROUP_NTD)
    @forelse($q['ntds'] as $ntd)
        <div style="margin-bottom:12px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:6px;color:var(--dd-text);">
                NTD {{ $ntd['ntd_id'] ?: '(id not returned)' }}
                <span style="font-weight:400;color:var(--dd-text-soft);">
                    @if($ntd['ntd_type']) · {{ $ntd['ntd_type'] }}@endif
                    @if($ntd['ntd_version']) · v{{ $ntd['ntd_version'] }}@endif
                    @if($yn($ntd['battery']) !== null) · Battery: {{ $yn($ntd['battery']) }}@endif
                    @if($ntd['battery_date']) ({{ $ntd['battery_date'] }})@endif
                    @if($ntd['download_speed'] || $ntd['upload_speed']) · ↓ {{ $ntd['download_speed'] ?: '?' }} / ↑ {{ $ntd['upload_speed'] ?: '?' }} Mbps @endif
                    @if($ntd['remaining_download'] || $ntd['remaining_upload']) · remaining ↓ {{ $ntd['remaining_download'] ?: '?' }} / ↑ {{ $ntd['remaining_upload'] ?: '?' }}@endif
                    @if($ntd['mac']) · MAC {{ $ntd['mac'] }}@endif
                </span>
            </div>
            @forelse($ntd['ports'] as $p)
                @php
                    // A transfer moves the port the service uses now (in use); a new order never picks a port.
                    $free  = \App\Services\Vocus\VocusQualification::isSelectablePort($p, (bool) $transfer) && $p['port_number'] !== null;
                    $inUse = $p['available'] === false;
                    $voice = $p['port_type'] === 'VOICE';
                    $cap   = \App\Services\Vocus\VocusQualification::ntdCapability($ntd);
                @endphp
                <label style="{{ $card }}{{ ($selectable && ! $free) || (! $selectable && $inUse) ? $disabled : '' }}cursor:{{ $selectable && $free ? 'pointer' : 'default' }};">
                    @if($selectable)
                        <input type="radio" name="line_pick" form="line-pick-none"
                               data-ntd="{{ $ntd['ntd_id'] }}" data-max-down="{{ $cap['download'] }}" data-max-up="{{ $cap['upload'] }}" data-index="{{ $ntd['index'] }}" data-port="{{ $p['port_number'] }}" data-pname="{{ $p['port_name'] }}"
                               data-wire="{{ $free ? $p['port_number'] : '' }}" @disabled(! $free) @checked($choice === 'existing' && (string) $cPort === (string) $p['port_number'] && (string) $cNtd === (string) $ntd['ntd_id'])>
                    @endif
                    <span style="flex:1;">
                        <strong>{{ \App\Services\Vocus\VocusQualification::portLabel($p) }}</strong>
                        <span style="color:var(--dd-text-soft);font-size:12px;margin-left:6px;">{{ $p['port_type'] ?: 'DATA' }}@if($p['port_number'] !== null) · port {{ $p['port_number'] }}@endif</span>
                        @if($inUse)
                            <span style="{{ $badge }}background:var(--dd-status-danger-bg);color:var(--dd-status-danger-fg);margin-left:6px;">In use</span>
                        @elseif($p['available'] === true)
                            <span style="{{ $badge }}background:var(--dd-status-success-bg);color:var(--dd-status-success-fg);margin-left:6px;">Free</span>
                        @else
                            <span style="{{ $badge }}background:var(--dd-status-muted-bg);color:var(--dd-status-muted-fg);margin-left:6px;">Availability unknown</span>
                        @endif
                        @if($voice && $selectable)<span style="font-size:12px;color:var(--dd-text-soft);margin-left:6px;">Voice port: not selectable for a data service</span>@endif
                        @if($selectable && ! $transfer && $free)<span style="display:block;font-size:12px;color:var(--dd-text-soft);margin-top:2px;">Choosing this selects the NTD. Vocus picks the free UNI-D port itself.</span>@endif
                        @if($p['epid'])<span style="display:block;font-size:12px;color:var(--dd-text-soft);margin-top:2px;">EPID {{ $p['epid'] }}</span>@endif
                    </span>
                </label>
            @empty
                <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 6px;">No port records were returned for this NTD.</p>
            @endforelse
        </div>
    @empty
        <x-ui.alert variant="info">No NTD or ports were returned. NBN will install an NTD at this address and books an installation appointment on the first available date.</x-ui.alert>
    @endforelse
@else
    <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 8px;">{{ $q['service_type'] ?: 'This technology' }} has no copper pair or NTD port to choose.</p>
@endif

@if($selectable && $allowNew && $group !== \App\Services\Vocus\VocusQualification::GROUP_OTHER)
    <label style="{{ $card }}cursor:pointer;border-style:dashed;">
        @php $newCap = \App\Services\Vocus\VocusQualification::lineCapability($q, true); $isHfc = str_contains(strtoupper((string) ($q['service_type'] ?? '')), 'HFC'); @endphp
        <input type="radio" name="line_pick" form="line-pick-none" data-choice="new" data-max-down="{{ $newCap['download'] }}" data-max-up="{{ $newCap['upload'] }}" @checked($choice === 'new')>
        <span><strong>{{ $group === \App\Services\Vocus\VocusQualification::GROUP_NTD ? ($isHfc ? 'Order a new NTD' : 'Order a new line') : 'Order a new line' }}</strong>
            <span style="display:block;color:var(--dd-text-soft);font-size:12px;margin-top:3px;">
                @if($group === \App\Services\Vocus\VocusQualification::GROUP_COPPER)
                    A new copper pair is ordered from NBN Co (sent as CopperPairID NEW; the phone service is not continued). An installation appointment is booked on the first available date.
                    @if($newCap['download'] !== null || $newCap['upload'] !== null)Plans are limited to the best line returned (up to {{ $newCap['download'] ?? '?' }}/{{ $newCap['upload'] ?? '?' }} Mbps).@endif
                @elseif($isHfc)
                    A new NTD with NBN professional install (sent as NTDID NEW).
                @else
                    A new connection: Vocus selects the NTD and a free UNI-D port, and NBN books any installation appointment on the first available date.
                @endif
            </span></span>
    </label>
@endif
