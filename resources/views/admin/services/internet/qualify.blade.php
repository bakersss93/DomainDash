@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card" style="max-width:700px;">
    <a href="{{ route('admin.services.internet') }}" style="font-size:13px;color:var(--dd-text-soft);text-decoration:none;">← Internet Services</a>
    <h1 class="dd-page-title">Qualify Service Address</h1>
    <p style="font-size:14px;color:var(--dd-text-soft);margin:0 0 24px;">Check whether an address is eligible for NBN and view available technologies and speeds.</p>

    @if(!empty($vocusProblem))
        <x-ui.alert variant="danger" title="Vocus is not ready" style="margin-bottom:16px;">{{ $vocusProblem }}
            <a href="{{ route('admin.settings') }}">Open settings</a>
        </x-ui.alert>
    @endif

    {{-- Step 1: Address search --}}
    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Step 1 — Find Address</h3>

        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:#374151;">Enter address</label>
            <div style="display:flex;gap:8px;">
                <input id="simple-address"
                       type="text"
                       placeholder="e.g. 2/99 Example St, Melbourne VIC 3000"
                       style="flex:1;"
                       oninput="clearResults()">
                <button onclick="searchAddress()" class="btn-accent" style="padding:9px 18px;white-space:nowrap;">
                    Search
                </button>
            </div>
        </div>

        {{-- Toggle for structured form --}}
        <div style="margin-bottom:12px;">
            <button type="button" onclick="toggleStructured()" style="font-size:12px;color:var(--accent);background:none;border:none;cursor:pointer;padding:0;">
                Can't find it? Enter address details manually ↓
            </button>
        </div>

        <div id="structured-form" style="display:none;border-top:1px solid #e5e7eb;padding-top:14px;">
            <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 10px;">Values follow the Vocus street address standard (street name up to 25 characters, suburb up to 40).</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <label for="f-unit-type" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Unit type</label>
                        <select id="f-unit-type" style="width:100%;">
                            <option value="">None</option>
                            @foreach($unitTypes as $abbr => $name)
                                <option value="{{ $abbr }}">{{ $abbr }} - {{ ucwords(strtolower($name)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-unit" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Unit number</label>
                        <input id="f-unit" type="text" placeholder="e.g. 2 or 2A" maxlength="6" style="width:100%;">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <label for="f-level-type" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Level type</label>
                        <select id="f-level-type" style="width:100%;">
                            <option value="">None</option>
                            @foreach($levelTypes as $abbr => $name)
                                <option value="{{ $abbr }}">{{ $abbr }} - {{ ucwords(strtolower($name)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-level" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Level number</label>
                        <input id="f-level" type="text" inputmode="numeric" maxlength="4" placeholder="e.g. 3" style="width:100%;">
                    </div>
                </div>
                <div>
                    <label for="f-number" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Street number</label>
                    <input id="f-number" type="text" placeholder="e.g. 99 or 99A" maxlength="8" style="width:100%;">
                </div>
                <div>
                    <label for="f-street" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Street name</label>
                    <input id="f-street" type="text" placeholder="e.g. Example" maxlength="25" style="width:100%;">
                </div>
                <div>
                    <label for="f-type" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Street type</label>
                    <select id="f-type" style="width:100%;">
                        <option value="">Select…</option>
                        @foreach($streetTypes as $abbr => $name)
                            <option value="{{ $abbr }}">{{ $abbr }} - {{ ucwords(strtolower($name)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-suburb" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Suburb</label>
                    <input id="f-suburb" type="text" placeholder="e.g. Melbourne" maxlength="40" style="width:100%;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <label for="f-state" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">State</label>
                        <select id="f-state" style="width:100%;">
                            <option value="">State</option>
                            @foreach($states as $code => $name)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-postcode" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">Postcode</label>
                        <input id="f-postcode" type="text" inputmode="numeric" placeholder="0000" maxlength="6" style="width:100%;">
                    </div>
                </div>
            </div>
            <button onclick="searchStructured()" class="btn-accent" style="padding:8px 16px;font-size:13px;">Search Address</button>
        </div>

        {{-- Address results --}}
        <div id="address-loading" style="display:none;padding:12px;text-align:center;color:var(--dd-text-soft);font-size:13px;">Searching Vocus address directory…</div>
        <div id="address-error" style="display:none;padding:10px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-status-danger-fg) 30%, transparent);border-radius:6px;color:var(--dd-danger-text);font-size:13px;margin-top:10px;"></div>
        <div id="address-results" style="display:none;margin-top:12px;">
            <p style="font-size:13px;font-weight:500;color:#374151;margin:0 0 8px;">Select your address:</p>
            <div id="address-list" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>
    </div>

    {{-- Layer 2 (Network-Connect / NCL2): AVC and AggCVC IDs are sent with the qualification (Examples 4.2.2) --}}
    <details style="margin-bottom:20px;">
        <summary style="cursor:pointer;font-size:13px;color:var(--dd-text-soft);">Layer 2 / Network-Connect (NCL2): AVC and CVC IDs</summary>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
            <div>
                <label for="q-avc" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">AVC ID</label>
                <input id="q-avc" type="text" maxlength="30" placeholder="e.g. AVC000340636851" style="width:100%;font-family:monospace;">
            </div>
            <div>
                <label for="q-cvc" style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);">CVC (AggCVC) ID</label>
                <input id="q-cvc" type="text" maxlength="30" placeholder="e.g. AggCVC000000999" style="width:100%;font-family:monospace;">
            </div>
        </div>
        <p style="font-size:12px;color:var(--dd-text-soft);margin:6px 0 0;">Leave blank for Layer 3 / Reseller-Connect. Enter these before selecting an address; they are passed on to the order and transfer forms.</p>
    </details>

    {{-- Step 2: Qualification result --}}
    <div id="qualify-section" style="display:none;background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Step 2 — Qualification Result</h3>
        <div id="qualify-loading" style="display:none;color:var(--dd-text-soft);font-size:13px;">Checking serviceability with Vocus…</div>
        <div id="qualify-error" style="display:none;padding:10px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-status-danger-fg) 30%, transparent);border-radius:6px;color:var(--dd-danger-text);font-size:13px;"></div>
        <div id="qualify-result" style="display:none;"></div>
    </div>
</div>
</div>

<script>
let selectedAddress = null;

function clearResults() {
    document.getElementById('address-results').style.display = 'none';
    document.getElementById('address-error').style.display = 'none';
    document.getElementById('qualify-section').style.display = 'none';
    selectedAddress = null;
}

function toggleStructured() {
    const el = document.getElementById('structured-form');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function searchAddress() {
    const addr = document.getElementById('simple-address').value.trim();
    if (!addr) return;
    doLookup({ address: addr });
}

function searchStructured() {
    doLookup({
        street_number: document.getElementById('f-number').value.trim(),
        street_name:   document.getElementById('f-street').value.trim(),
        street_type:   document.getElementById('f-type').value,
        suburb:        document.getElementById('f-suburb').value.trim(),
        state:         document.getElementById('f-state').value,
        postcode:      document.getElementById('f-postcode').value.trim(),
        unit_number:   document.getElementById('f-unit').value.trim(),
        unit_type:     document.getElementById('f-unit-type').value,
        level_type:    document.getElementById('f-level-type').value,
        level_number:  document.getElementById('f-level').value.trim(),
    });
}

// Laravel validation failures come back as {message, errors}; Vocus / config failures as {error}.
function errorText(data, fallback) {
    if (data && data.error) return data.error;
    if (data && data.errors) { const first = Object.values(data.errors).flat()[0]; if (first) return first; }
    return (data && data.message) || fallback;
}

function doLookup(params) {
    document.getElementById('address-loading').style.display = 'block';
    document.getElementById('address-results').style.display = 'none';
    document.getElementById('address-error').style.display = 'none';
    document.getElementById('qualify-section').style.display = 'none';

    const body = new URLSearchParams({ ...params, _token: '{{ csrf_token() }}' });

    fetch('{{ route('admin.services.internet.qualify.lookup') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString(),
    })
    .then(r => r.json().catch(() => ({ error: 'Unexpected response from the server (HTTP ' + r.status + ').' })))
    .then(data => {
        document.getElementById('address-loading').style.display = 'none';

        if (data.error || data.errors || (data.message && !data.addresses)) {
            const el = document.getElementById('address-error');
            el.textContent = errorText(data, 'The address search failed.');
            el.style.display = 'block';
            return;
        }

        const list = document.getElementById('address-list');
        list.innerHTML = '';

        if (!data.addresses || data.addresses.length === 0) {
            const el = document.getElementById('address-error');
            el.textContent = data.message || 'No addresses found. Try the manual form or check your spelling.';
            el.style.display = 'block';
            return;
        }

        data.addresses.forEach(addr => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.style = 'text-align:left;padding:10px 14px;border:1px solid #d1d5db;border-radius:6px;background:white;font-size:13px;cursor:pointer;transition:border-color 0.15s;';
            const strong = document.createElement('strong');
            strong.style = 'font-size:12px;color:var(--dd-text-soft);display:block;';
            strong.textContent = addr.directory_id;
            btn.appendChild(strong);
            btn.appendChild(document.createTextNode(String(addr.address_long ?? '')));
            btn.onmouseenter = () => btn.style.borderColor = 'var(--accent)';
            btn.onmouseleave = () => btn.style.borderColor = '#d1d5db';
            btn.onclick = () => selectAddress(addr);
            list.appendChild(btn);
        });

        document.getElementById('address-results').style.display = 'block';
    })
    .catch(() => {
        document.getElementById('address-loading').style.display = 'none';
        const el = document.getElementById('address-error');
        el.textContent = 'Request failed. Check that Vocus API credentials are configured in Settings.';
        el.style.display = 'block';
    });
}

function selectAddress(addr) {
    selectedAddress = addr;

    // Highlight selected
    document.querySelectorAll('#address-list button').forEach(btn => {
        btn.style.background = 'white';
        btn.style.borderColor = '#d1d5db';
    });
    event.currentTarget.style.background = '#eff6ff';
    event.currentTarget.style.borderColor = 'var(--accent)';

    runQualify(addr.directory_id, addr.address_long);
}

function runQualify(directoryId, addressLong) {
    const section = document.getElementById('qualify-section');
    section.style.display = 'block';
    document.getElementById('qualify-loading').style.display = 'block';
    document.getElementById('qualify-error').style.display = 'none';
    document.getElementById('qualify-result').style.display = 'none';

    const body = new URLSearchParams({
        _token: '{{ csrf_token() }}',
        directory_id: directoryId,
        address_long: addressLong,
        avc_id: document.getElementById('q-avc').value.trim(),
        cvc_id: document.getElementById('q-cvc').value.trim(),
    });

    fetch('{{ route('admin.services.internet.qualify.check') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString(),
    })
    .then(r => r.json().catch(() => ({ error: 'Unexpected response from the server (HTTP ' + r.status + ').' })))
    .then(data => {
        document.getElementById('qualify-loading').style.display = 'none';

        if (data.error || data.errors || (data.message && data.result === undefined)) {
            const el = document.getElementById('qualify-error');
            el.textContent = errorText(data, 'Qualification failed.');
            el.style.display = 'block';
            return;
        }

        renderQualifyResult(data, directoryId, addressLong);
    })
    .catch(() => {
        document.getElementById('qualify-loading').style.display = 'none';
        const el = document.getElementById('qualify-error');
        el.textContent = 'Qualification request failed.';
        el.style.display = 'block';
    });
}

function qEsc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function renderQualifyResult(data, directoryId, addressLong) {
    const el = document.getElementById('qualify-result');
    const passed = data.result === 'PASS';

    let copperPairs = '';
    if (data.copper_pairs && data.copper_pairs.length > 0) {
        data.copper_pairs.forEach(pair => {
            const speeds = pair.DownloadSpeed || pair.downloadSpeed || '';
            const upSpeeds = pair.UploadSpeed || pair.uploadSpeed || '';
            const pairId = pair.CopperPairID || pair.copperPairId || '';
            copperPairs += `<div style="margin-top:8px;padding:10px;background:white;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;">
                <strong>${qEsc(pairId)}</strong>
                ${speeds ? `<span style="color:var(--dd-text-soft);margin-left:8px;">↓ ${qEsc(speeds)} Mbps / ↑ ${qEsc(upSpeeds)} Mbps</span>` : ''}
            </div>`;
        });
    }

    let orderButtons = '';
    if (passed) {
        const params = new URLSearchParams({
            directory_id: directoryId,
            address_long: addressLong,
            service_type: data.service_type || '',
            copper_pair_id: (data.copper_pairs && data.copper_pairs[0]) ? (data.copper_pairs[0].CopperPairID || data.copper_pairs[0].copperPairId || '') : '',
            avc_id: document.getElementById('q-avc').value.trim(),
            cvc_id: document.getElementById('q-cvc').value.trim(),
        });
        orderButtons = `<div style="display:flex;gap:8px;margin-top:16px;">
            <a href="{{ route('admin.services.internet.order') }}?${params.toString()}"
               style="padding:8px 18px;border-radius:6px;background:var(--accent);color:white;text-decoration:none;font-size:13px;font-weight:500;">
               New Order →
            </a>
            <a href="{{ route('admin.services.internet.transfer') }}?${params.toString()}"
               style="padding:8px 18px;border-radius:6px;background:white;border:1px solid #d1d5db;color:#374151;text-decoration:none;font-size:13px;font-weight:500;">
               Transfer Service →
            </a>
        </div>`;
    }

    el.innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
            <span style="padding:4px 14px;border-radius:20px;font-size:14px;font-weight:700;background:${passed ? '#dcfce7' : '#fee2e2'};color:${passed ? '#15803d' : '#b91c1c'};">
                ${passed ? '✓ PASS' : '✗ FAIL'}
            </span>
            ${data.service_type ? `<span style="padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;background:var(--dd-status-info-bg);color:var(--dd-status-info-fg);">${qEsc(data.service_type)}</span>` : ''}
            ${data.zone ? `<span style="font-size:13px;color:var(--dd-text-soft);">Zone: ${qEsc(data.zone)}</span>` : ''}
            ${data.service_class ? `<span style="font-size:13px;color:var(--dd-text-soft);">Class ${qEsc(data.service_class)}</span>` : ''}
        </div>
        ${copperPairs}
        ${orderButtons}
    `;
    el.style.display = 'block';
}
</script>
@endsection
