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
    <div style="background:var(--dd-surface-soft);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Step 1 — Find Address</h3>

        <div style="margin-bottom:12px;">
            <label style="display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:var(--dd-text);">Enter address</label>
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

        <div id="structured-form" style="display:none;border-top:1px solid var(--dd-border);padding-top:14px;">
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
            <p style="font-size:13px;font-weight:500;color:var(--dd-text);margin:0 0 8px;">Select your address:</p>
            <div id="address-list" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>
    </div>

    {{-- Alternative: qualify straight from an NBN LOC ID (the DirectoryID for NBN products); skips the address search --}}
    <div style="background:var(--dd-surface-soft);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 6px;">Or search by NBN LOC ID</h3>
        <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 10px;">If you already know the location ID (LOC followed by 12 digits), qualify it directly without an address search.</p>
        <form id="loc-form" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;" novalidate>
            <div style="flex:1;min-width:14rem;">
                <label for="loc-id" class="sr-only">NBN LOC ID</label>
                <input id="loc-id" type="text" maxlength="15" placeholder="e.g. LOC001122334455" autocomplete="off" spellcheck="false"
                       style="width:100%;font-family:monospace;text-transform:uppercase;" aria-describedby="loc-error">
                <p id="loc-error" role="alert" style="display:none;font-size:12px;color:var(--dd-danger-text);margin:4px 0 0;"></p>
            </div>
            <button type="submit" class="btn-accent" style="padding:9px 18px;white-space:nowrap;">Qualify LOC ID</button>
        </form>
    </div>

    {{-- Step 2: Qualification result --}}
    <div id="qualify-section" style="display:none;background:var(--dd-surface-soft);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Step 2 — Qualification Result</h3>
        <div id="qualify-loading" style="display:none;color:var(--dd-text-soft);font-size:13px;">Checking serviceability with Vocus…</div>
        <div id="qualify-error" style="display:none;padding:10px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-status-danger-fg) 30%, transparent);border-radius:6px;color:var(--dd-danger-text);font-size:13px;"></div>
        <div id="qualify-result" style="display:none;"></div>
    </div>
</div>
</div>

<script>
let selectedAddress = null;
let lookupMeta = { postcode: '', suburb: '', state: '' };

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
        lookupMeta = { postcode: data.postcode || '', suburb: data.suburb || '', state: data.state || '' };

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
            btn.style = 'text-align:left;padding:10px 14px;border:1px solid var(--dd-border-strong);border-radius:6px;background:var(--dd-surface);color:var(--dd-text);font-size:13px;cursor:pointer;transition:border-color 0.15s;';
            const strong = document.createElement('strong');
            strong.style = 'font-size:12px;color:var(--dd-text-soft);display:block;';
            strong.textContent = addr.directory_id;
            btn.appendChild(strong);
            btn.appendChild(document.createTextNode(String(addr.address_long ?? '')));
            btn.onmouseenter = () => btn.style.borderColor = 'var(--accent)';
            btn.onmouseleave = () => btn.style.borderColor = 'var(--dd-border-strong)';
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
        btn.style.background = 'var(--dd-surface)';
        btn.style.borderColor = 'var(--dd-border-strong)';
    });
    event.currentTarget.style.background = 'var(--dd-accent-soft)';
    event.currentTarget.style.borderColor = 'var(--accent)';

    runQualify({ directory_id: addr.directory_id, address_long: addr.address_long });
}

function runQualify(target) {
    const section = document.getElementById('qualify-section');
    section.style.display = 'block';
    document.getElementById('qualify-loading').style.display = 'block';
    document.getElementById('qualify-error').style.display = 'none';
    document.getElementById('qualify-result').style.display = 'none';

    const body = new URLSearchParams({ _token: '{{ csrf_token() }}' });
    Object.keys(target).forEach(k => body.set(k, target[k] || ''));

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

        renderQualifyResult(data, data.directory_id || target.directory_id || target.loc_id, data.address_long || target.address_long || '', !!target.loc_id);
    })
    .catch(() => {
        document.getElementById('qualify-loading').style.display = 'none';
        const el = document.getElementById('qualify-error');
        el.textContent = 'Qualification request failed.';
        el.style.display = 'block';
    });
}

// Qualify straight from an NBN LOC ID (LOC + 12 digits, case-insensitive). The server validates the same pattern.
document.getElementById('loc-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const input = document.getElementById('loc-id');
    const err = document.getElementById('loc-error');
    const loc = input.value.trim().toUpperCase();
    input.value = loc;
    if (!/^LOC\d{12}$/.test(loc)) {
        err.textContent = 'An NBN LOC ID is "LOC" followed by 12 digits, for example LOC001122334455.';
        err.style.display = 'block';
        return;
    }
    err.style.display = 'none';
    clearResults();
    lookupMeta = { postcode: '', suburb: '', state: '' };
    runQualify({ loc_id: loc });
});

// DOM helpers: server strings only ever go through textContent.
function h(tag, style, text) {
    const n = document.createElement(tag);
    if (style) n.style.cssText = style;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
}

const PILL = 'display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;margin-left:6px;';
function pill(text, kind) {
    return h('span', PILL + 'background:var(--dd-status-' + kind + '-bg);color:var(--dd-status-' + kind + '-fg);', text);
}
function card(disabled) {
    return 'padding:10px 12px;margin-bottom:8px;border:1px solid var(--dd-border);border-radius:8px;font-size:13px;color:var(--dd-text);background:'
        + (disabled ? 'var(--dd-surface-soft);opacity:.65;' : 'var(--dd-surface);');
}
function yesNo(v) { return v === true ? 'Yes' : (v === false ? 'No' : null); }

function renderCopper(pairs) {
    const box = h('div');
    if (!pairs.length) {
        box.appendChild(h('p', 'font-size:13px;color:var(--dd-text-soft);margin:0;', 'Vocus returned no existing copper pairs for this address (a new line would be ordered).'));
        return box;
    }
    pairs.forEach(p => {
        const c = h('div', card(false));
        const top = h('div');
        top.appendChild(h('strong', 'font-family:monospace;', p.copper_pair_id || 'No CopperPairID returned'));
        if (p.status) top.appendChild(pill(p.status, 'muted'));
        if (p.nbn_service_status) top.appendChild(pill(p.nbn_service_status, 'info'));
        if (p.extra_charge) top.appendChild(pill('Extra charge', 'warning'));
        c.appendChild(top);
        const bits = [];
        if (p.service_class) bits.push('Class ' + p.service_class);
        if (p.download_speed) bits.push('Download ' + p.download_speed + ' Mbps');
        if (p.upload_speed) bits.push('Upload ' + p.upload_speed + ' Mbps');
        if (p.pots_interconnect && p.pots_interconnect !== 'N/A') bits.push('POTS ' + p.pots_interconnect);
        if (bits.length) c.appendChild(h('div', 'font-size:12px;color:var(--dd-text-soft);margin-top:3px;', bits.join(' · ')));
        if (p.max_download_mbps || p.max_upload_mbps) c.appendChild(h('div', 'font-size:12px;font-weight:600;margin-top:3px;', 'Max line speed: Download ' + (p.max_download_mbps || '?') + ' / Upload ' + (p.max_upload_mbps || '?') + ' Mbps (plans faster than this cannot be ordered on this line)'));
        if (p.network_co_exist) c.appendChild(h('div', 'font-size:12px;color:var(--dd-status-warning-fg);margin-top:3px;', 'Network co-exist: not every FNN has been cut over to the node, minimum speed is limited to 12M.'));
        if (p.status === 'N/A') c.appendChild(h('div', 'font-size:12px;color:var(--dd-text-soft);margin-top:3px;', 'N/A: the copper pair has been transitioned to NBN Co.'));
        box.appendChild(c);
    });
    return box;
}

function renderNtds(ntds) {
    const box = h('div');
    if (!ntds.length) {
        box.appendChild(h('p', 'font-size:13px;color:var(--dd-text-soft);margin:0;', 'No NTD or ports were returned. NBN will install an NTD at this address, and an installation appointment may be required.'));
        return box;
    }
    ntds.forEach(n => {
        const wrap = h('div', 'margin-bottom:12px;');
        const bits = [];
        if (n.ntd_type) bits.push(n.ntd_type);
        if (n.ntd_version) bits.push('v' + n.ntd_version);
        if (yesNo(n.battery) !== null) bits.push('Battery: ' + yesNo(n.battery) + (n.battery_date ? ' (' + n.battery_date + ')' : ''));
        if (n.download_speed || n.upload_speed) bits.push('Down ' + (n.download_speed || '?') + ' / Up ' + (n.upload_speed || '?') + ' Mbps');
        if (n.remaining_download || n.remaining_upload) bits.push('Remaining down ' + (n.remaining_download || '?') + ' / up ' + (n.remaining_upload || '?'));
        if (n.mac) bits.push('MAC ' + n.mac);
        const head = h('div', 'font-size:13px;font-weight:600;margin-bottom:6px;color:var(--dd-text);', 'NTD ' + (n.ntd_id || '(id not returned)'));
        if (bits.length) head.appendChild(h('span', 'font-weight:400;color:var(--dd-text-soft);', ' · ' + bits.join(' · ')));
        wrap.appendChild(head);

        const grid = h('div', 'display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;');
        (n.ports || []).forEach(p => {
            const inUse = p.available === false;
            const c = h('div', card(inUse) + 'margin-bottom:0;');
            c.appendChild(h('strong', '', p.port_name || (p.port_number !== null ? 'UNI-D ' + p.port_number : 'Port')));
            c.appendChild(h('span', 'font-size:12px;color:var(--dd-text-soft);margin-left:6px;', p.port_type || 'DATA'));
            const st = h('div', 'margin-top:4px;');
            if (inUse) st.appendChild(pill('In use', 'danger'));
            else if (p.available === true) st.appendChild(pill('Free', 'success'));
            else st.appendChild(pill('Availability unknown', 'muted'));
            c.appendChild(st);
            if (p.epid) c.appendChild(h('div', 'font-size:12px;color:var(--dd-text-soft);margin-top:3px;', 'EPID ' + p.epid));
            grid.appendChild(c);
        });
        if (!(n.ports || []).length) wrap.appendChild(h('p', 'font-size:12px;color:var(--dd-text-soft);margin:0;', 'No port records were returned for this NTD.'));
        else wrap.appendChild(grid);
        box.appendChild(wrap);
    });
    return box;
}

function renderQualifyResult(data, directoryId, addressLong, fromLoc) {
    const el = document.getElementById('qualify-result');
    el.replaceChildren();
    const q = data.qualification || {};
    const passed = data.result === 'PASS';
    const group = q.technology_group || 'OTHER';

    const head = h('div', 'display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;');
    head.appendChild(h('span', 'padding:4px 14px;border-radius:20px;font-size:14px;font-weight:700;background:' + (passed ? 'var(--dd-status-success-bg)' : 'var(--dd-status-danger-bg)') + ';color:' + (passed ? 'var(--dd-status-success-fg)' : 'var(--dd-status-danger-fg)') + ';', passed ? '✓ PASS' : '✗ FAIL'));
    if (data.service_type) head.appendChild(h('span', 'padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;background:var(--dd-status-info-bg);color:var(--dd-status-info-fg);', data.service_type));
    if (data.zone) head.appendChild(h('span', 'font-size:13px;color:var(--dd-text-soft);', 'Zone: ' + data.zone));
    el.appendChild(head);

    el.appendChild(h('p', 'font-size:13px;color:var(--dd-text-soft);margin:0 0 6px;', directoryId + (addressLong ? ' · ' + addressLong : '')));

    const facts = [];
    if (q.service_class) {
        const info = q.service_class_info;
        facts.push(['Service class', q.service_class + (info ? ' (' + info.label + ')' : '')]);
    }
    if (q.primary_technology) facts.push(['Primary technology', q.primary_technology]);
    if (q.connection_type) facts.push(['Connection type', q.connection_type]);
    if (q.development_charge !== null && q.development_charge !== undefined) facts.push(['Development charge', yesNo(q.development_charge)]);
    if (q.hfc_self_install !== null && q.hfc_self_install !== undefined) facts.push(['HFC self install', yesNo(q.hfc_self_install)]);
    if (q.copper_disconnection_date) facts.push(['Copper disconnection date', q.copper_disconnection_date]);
    if (facts.length) {
        const dl = h('dl', 'display:grid;grid-template-columns:auto 1fr;gap:3px 14px;margin:0 0 10px;font-size:13px;');
        facts.forEach(f => { dl.appendChild(h('dt', 'color:var(--dd-text-soft);', f[0])); dl.appendChild(h('dd', 'margin:0;color:var(--dd-text);', f[1])); });
        el.appendChild(dl);
        if (q.service_class_info) el.appendChild(h('p', 'font-size:11px;color:var(--dd-text-soft);margin:0 0 12px;', 'Class meaning: ' + q.service_class_info.source + '.'));
    }

    if (passed) {
        const title = group === 'COPPER' ? 'Available copper lines (FTTN / FTTB / FTTC)' : (group === 'NTD' ? 'NTD ports (FTTP / HFC / Fixed Wireless)' : 'Line details');
        el.appendChild(h('h4', 'font-size:13px;font-weight:600;margin:12px 0 8px;color:var(--dd-text);', title));
        if (group === 'COPPER') el.appendChild(renderCopper(q.copper_pairs || []));
        else if (group === 'NTD') el.appendChild(renderNtds(q.ntds || []));
        else el.appendChild(h('p', 'font-size:13px;color:var(--dd-text-soft);margin:0;', (data.service_type || 'This technology') + ' has no copper pair or NTD port to choose; it is ordered without a line or port selection.'));
        el.appendChild(h('p', 'font-size:12px;color:var(--dd-text-soft);margin:8px 0 0;', 'You choose the line or port (or a new line) when you place the order.'));

        const params = new URLSearchParams({
            directory_id: directoryId,
            address_long: addressLong,
            service_type: data.service_type || '',
            service_class: q.service_class || '',
            qualification_token: data.qualification_token || '',
            location_reference: fromLoc ? '' : lookupMeta.postcode,
            suburb: fromLoc ? '' : lookupMeta.suburb,
            state: fromLoc ? '' : lookupMeta.state,
        });
        const row = h('div', 'display:flex;gap:8px;margin-top:16px;');
        const a1 = h('a', 'padding:8px 18px;border-radius:6px;background:var(--dd-accent);color:var(--dd-accent-contrast);text-decoration:none;font-size:13px;font-weight:500;', 'New Order →');
        a1.href = @json(route('admin.services.internet.order')) + '?' + params.toString();
        const a2 = h('a', 'padding:8px 18px;border-radius:6px;background:var(--dd-surface);border:1px solid var(--dd-border-strong);color:var(--dd-text);text-decoration:none;font-size:13px;font-weight:500;', 'Transfer Service →');
        a2.href = @json(route('admin.services.internet.transfer')) + '?' + params.toString();
        row.appendChild(a1);
        row.appendChild(a2);
        el.appendChild(row);
    }
    el.style.display = 'block';
}
</script>
@endsection
