@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card" style="max-width:700px;">
    <a href="{{ route('admin.services.internet') }}" style="font-size:13px;color:#6b7280;text-decoration:none;">← Internet Services</a>
    <h1 class="dd-page-title" style="font-size:1.35rem;margin:8px 0 4px;">Qualify Service Address</h1>
    <p style="font-size:14px;color:#6b7280;margin:0 0 24px;">Check whether an address is eligible for NBN and view available technologies and speeds.</p>

    {{-- Step 1: Address search --}}
    <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Step 1 — Find Address</h3>

        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:#374151;">Enter address</label>
            <div style="display:flex;gap:8px;">
                <input id="simple-address"
                       type="text"
                       placeholder="e.g. 2/99 Example St, Melbourne VIC 3000"
                       style="flex:1;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"
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
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Unit / Level</label>
                    <input id="f-unit" type="text" placeholder="e.g. 2" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Street Number</label>
                    <input id="f-number" type="text" placeholder="e.g. 99" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Street Name</label>
                    <input id="f-street" type="text" placeholder="e.g. Example" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Street Type</label>
                    <select id="f-type" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                        <option value="">Select…</option>
                        @foreach(['ST','AVE','RD','DR','CL','CT','PL','TCE','WAY','HWY','BLVD','LN','GR','CCT','CRES'] as $t)
                            <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Suburb</label>
                    <input id="f-suburb" type="text" placeholder="e.g. Melbourne" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">State</label>
                        <select id="f-state" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                            <option value="">State</option>
                            @foreach(['NSW','VIC','QLD','SA','WA','TAS','NT','ACT'] as $s)
                                <option>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Postcode</label>
                        <input id="f-postcode" type="text" placeholder="0000" maxlength="4" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:13px;">
                    </div>
                </div>
            </div>
            <button onclick="searchStructured()" class="btn-accent" style="padding:8px 16px;font-size:13px;">Search Address</button>
        </div>

        {{-- Address results --}}
        <div id="address-loading" style="display:none;padding:12px;text-align:center;color:#9ca3af;font-size:13px;">Searching Vocus address directory…</div>
        <div id="address-error" style="display:none;padding:10px;background:#fff1f2;border:1px solid #fecaca;border-radius:6px;color:#b91c1c;font-size:13px;margin-top:10px;"></div>
        <div id="address-results" style="display:none;margin-top:12px;">
            <p style="font-size:13px;font-weight:500;color:#374151;margin:0 0 8px;">Select your address:</p>
            <div id="address-list" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>
    </div>

    {{-- Step 2: Qualification result --}}
    <div id="qualify-section" style="display:none;background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Step 2 — Qualification Result</h3>
        <div id="qualify-loading" style="display:none;color:#9ca3af;font-size:13px;">Checking serviceability with Vocus…</div>
        <div id="qualify-error" style="display:none;padding:10px;background:#fff1f2;border:1px solid #fecaca;border-radius:6px;color:#b91c1c;font-size:13px;"></div>
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
        street_number: document.getElementById('f-number').value,
        street_name:   document.getElementById('f-street').value,
        street_type:   document.getElementById('f-type').value,
        suburb:        document.getElementById('f-suburb').value,
        state:         document.getElementById('f-state').value,
        postcode:      document.getElementById('f-postcode').value,
        unit_number:   document.getElementById('f-unit').value,
    });
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
    .then(r => r.json())
    .then(data => {
        document.getElementById('address-loading').style.display = 'none';

        if (data.error) {
            const el = document.getElementById('address-error');
            el.textContent = data.error;
            el.style.display = 'block';
            return;
        }

        const list = document.getElementById('address-list');
        list.innerHTML = '';

        if (!data.addresses || data.addresses.length === 0) {
            const el = document.getElementById('address-error');
            el.textContent = 'No addresses found. Try the manual form or check your spelling.';
            el.style.display = 'block';
            return;
        }

        data.addresses.forEach(addr => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.style = 'text-align:left;padding:10px 14px;border:1px solid #d1d5db;border-radius:6px;background:white;font-size:13px;cursor:pointer;transition:border-color 0.15s;';
            btn.innerHTML = `<strong style="font-size:12px;color:#6b7280;display:block;">${addr.directory_id}</strong>${addr.address_long}`;
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
    });

    fetch('{{ route('admin.services.internet.qualify.check') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('qualify-loading').style.display = 'none';

        if (data.error) {
            const el = document.getElementById('qualify-error');
            el.textContent = data.error;
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
                <strong>${pairId}</strong>
                ${speeds ? `<span style="color:#6b7280;margin-left:8px;">↓ ${speeds} Mbps / ↑ ${upSpeeds} Mbps</span>` : ''}
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
            ${data.service_type ? `<span style="padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;background:#dbeafe;color:#1e40af;">${data.service_type}</span>` : ''}
            ${data.zone ? `<span style="font-size:13px;color:#6b7280;">Zone: ${data.zone}</span>` : ''}
            ${data.service_class ? `<span style="font-size:13px;color:#6b7280;">Class ${data.service_class}</span>` : ''}
        </div>
        ${copperPairs}
        ${orderButtons}
    `;
    el.style.display = 'block';
}
</script>
@endsection
