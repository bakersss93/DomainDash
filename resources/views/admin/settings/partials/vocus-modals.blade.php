@php
    $__vo = [
        'realms'     => \App\Services\Vocus\VocusOptions::realms(),
        'enabled'    => \App\Services\Vocus\VocusOptions::enabledScopes(),
        'scopes'     => \App\Services\Vocus\VocusOptions::SCOPES,
        'plans'      => \App\Models\VocusPlan::orderBy('name')->get()->map->toFormArray()->values(),
        'mapping'    => \App\Services\Vocus\VocusOptions::planMapping(),
        'mappings'   => \App\Services\Vocus\VocusOptions::PLAN_MAPPINGS,
        'technologies' => \App\Services\Vocus\VocusOptions::PLAN_TECHNOLOGIES,
        'tiers'      => array_map(fn ($t) => ['download' => $t[0] ?? null, 'upload' => $t[1] ?? null], \App\Services\Vocus\VocusOptions::namedTiers()),
        'urls'       => [
            'realms' => route('admin.settings.vocus.realms'),
            'scopes' => route('admin.settings.vocus.scopes'),
            'plans'  => route('admin.settings.vocus.plans.store'),
            'plan'   => url('/admin/settings/vocus/plans'),
            'mapping' => route('admin.settings.vocus.plan-mapping'),
            'tiers'  => route('admin.settings.vocus.named-tiers'),
            'importPreview' => route('admin.settings.vocus.plans.import.preview'),
            'importApply'   => route('admin.settings.vocus.plans.import.apply'),
        ],
    ];
    $__lbl = 'display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:var(--dd-text-soft);';
@endphp
<script type="application/json" id="vocus-ordering-data">@json($__vo)</script>

{{-- PPPoE realms --}}
<x-ui.modal id="vocus-realms-modal" title="PPPoE realms">
    <div style="padding:16px 20px;">
        <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 12px;">Realms offered as the "NBN Domain" on order and transfer forms. The username sent to Vocus is <code>username@realm</code>.</p>
        <ul id="vo-realm-list" style="list-style:none;margin:0 0 12px;padding:0;display:flex;flex-direction:column;gap:6px;"></ul>
        <div style="display:flex;gap:8px;">
            <div style="flex:1;">
                <label for="vo-realm-input" style="{{ $__lbl }}">Add realm</label>
                <input id="vo-realm-input" type="text" class="dd-input" style="width:100%;" placeholder="e.g. jargonconnect.com.au" maxlength="61" autocomplete="off" spellcheck="false">
            </div>
            <button type="button" id="vo-realm-add" class="ui-btn ui-btn--secondary ui-btn--sm" style="align-self:flex-end;">Add</button>
        </div>
        <p id="vo-realm-msg" role="alert" style="font-size:12px;margin:10px 0 0;min-height:1em;"></p>
    </div>
    <x-slot:footer>
        <button type="button" class="ui-btn ui-btn--ghost" data-modal-close>Close</button>
        <button type="button" id="vo-realm-save" class="ui-btn ui-btn--primary">Save realms</button>
    </x-slot:footer>
</x-ui.modal>

{{-- Service scopes --}}
<x-ui.modal id="vocus-scopes-modal" title="Service scopes">
    <div style="padding:16px 20px;">
        <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 12px;">Only enabled scopes appear on the order and transfer forms. At least one must stay enabled.</p>
        <div id="vo-scope-list" style="display:flex;flex-direction:column;gap:8px;"></div>
        <p id="vo-scope-msg" role="alert" style="font-size:12px;margin:10px 0 0;min-height:1em;"></p>
    </div>
    <x-slot:footer>
        <button type="button" class="ui-btn ui-btn--ghost" data-modal-close>Close</button>
        <button type="button" id="vo-scope-save" class="ui-btn ui-btn--primary">Save scopes</button>
    </x-slot:footer>
</x-ui.modal>

{{-- Plans --}}
<x-ui.modal id="vocus-plans-modal" title="Vocus plans" size="lg">
    <div style="padding:16px 20px;">
        <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 12px;">One row per Vocus Plan ID (the PlanID sent to Vocus). Prices are AUD per month and can differ by technology. The cost and sell price are copied onto each service when it is ordered; clients only ever see the sell price.</p>

        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;padding:12px;border:1px solid var(--dd-border);border-radius:8px;">
            <div style="min-width:200px;">
                <label for="vo-mapping" style="{{ $__lbl }}">Account mapping</label>
                <select id="vo-mapping" class="dd-input" style="width:100%;" aria-describedby="vo-mapping-hint"></select>
            </div>
            <p id="vo-mapping-hint" style="flex:1;min-width:220px;font-size:12px;color:var(--dd-text-soft);margin:0;">Order and transfer forms offer only plans on this mapping (plans with no mapping are always offered). Saves when changed.</p>
            <button type="button" id="vo-import-open" class="ui-btn ui-btn--secondary ui-btn--sm">Import Vocus plan sheet</button>
        </div>
        <p id="vo-mapping-msg" role="alert" style="font-size:12px;margin:0 0 8px;min-height:1em;"></p>

        <section id="vo-import" hidden aria-labelledby="vo-import-title" style="margin-bottom:16px;padding:12px;border:1px solid var(--dd-border);border-radius:8px;">
            <h3 id="vo-import-title" style="font-size:14px;font-weight:600;margin:0 0 6px;">Import Vocus plan sheet</h3>
            <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 10px;">Upload the Vocus "RC Bundles PlanID" sheet (.xlsx or .csv, up to 2 MB). Only the left table is read (PlanID, ProductID, Technology, Mapping, LineSpeed). Existing plans only get technology, mapping and speed updated; their name, prices and active flag are never changed. New plans are added inactive with prices of $0.00 for you to price and activate.</p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div><label for="vo-import-file" style="{{ $__lbl }}">Sheet</label><input id="vo-import-file" type="file" accept=".xlsx,.csv" class="dd-input"></div>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;"><input id="vo-import-only" type="checkbox"> <span id="vo-import-only-label">Only import Default Map rows</span></label>
                <button type="button" id="vo-import-preview" class="ui-btn ui-btn--secondary ui-btn--sm">Preview</button>
            </div>
            <div id="vo-import-result" hidden style="margin-top:12px;">
                <p id="vo-import-counts" style="font-size:13px;font-weight:500;margin:0 0 8px;"></p>
                <div style="overflow-x:auto;"><table class="dd-services-table" style="width:100%;font-size:12px;"><thead><tr><th>Change</th><th>Plan ID</th><th>Technology</th><th>Mapping</th><th>Line speed</th><th>Mbps</th></tr></thead><tbody id="vo-import-sample"></tbody></table></div>
                <ul id="vo-import-warnings" style="font-size:12px;color:var(--dd-text-soft);margin:8px 0 0;padding-left:18px;"></ul>
                <div style="display:flex;gap:8px;margin-top:10px;">
                    <button type="button" id="vo-import-apply" class="ui-btn ui-btn--primary ui-btn--sm">Apply import</button>
                    <button type="button" id="vo-import-cancel" class="ui-btn ui-btn--ghost ui-btn--sm">Cancel</button>
                </div>
            </div>
            <p id="vo-import-msg" role="alert" style="font-size:12px;margin:10px 0 0;min-height:1em;"></p>
        </section>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;">
            <div style="flex:1;min-width:160px;"><label for="vo-f-q" style="{{ $__lbl }}">Search</label><input id="vo-f-q" type="search" class="dd-input" style="width:100%;" placeholder="Plan ID or name" autocomplete="off"></div>
            <div><label for="vo-f-tech" style="{{ $__lbl }}">Technology</label><select id="vo-f-tech" class="dd-input"></select></div>
            <div><label for="vo-f-map" style="{{ $__lbl }}">Mapping</label><select id="vo-f-map" class="dd-input"></select></div>
            <p id="vo-f-count" style="font-size:12px;color:var(--dd-text-soft);margin:0 0 8px;" aria-live="polite"></p>
        </div>
        <div style="overflow:auto;max-height:340px;margin-bottom:16px;">
            <table class="dd-services-table" style="width:100%;font-size:13px;">
                <thead><tr id="vo-plan-head"></tr></thead>
                <tbody id="vo-plan-rows"></tbody>
            </table>
        </div>
        <form id="vo-plan-form" novalidate style="border-top:1px solid var(--dd-border);padding-top:14px;">
            <h3 id="vo-plan-form-title" style="font-size:14px;font-weight:600;margin:0 0 10px;">Add plan</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
                <div><label for="vo-p-id" style="{{ $__lbl }}">Plan ID</label><input id="vo-p-id" class="dd-input" style="width:100%;font-family:monospace;" maxlength="30" required></div>
                <div><label for="vo-p-name" style="{{ $__lbl }}">Display name</label><input id="vo-p-name" class="dd-input" style="width:100%;" maxlength="100" required></div>
                <div><label for="vo-p-tech" style="{{ $__lbl }}">Technology</label><select id="vo-p-tech" class="dd-input" style="width:100%;"></select></div>
                <div><label for="vo-p-map" style="{{ $__lbl }}">Mapping</label><select id="vo-p-map" class="dd-input" style="width:100%;"></select></div>
                <div><label for="vo-p-speed" style="{{ $__lbl }}">Speed tier</label><input id="vo-p-speed" class="dd-input" style="width:100%;" maxlength="40" placeholder="e.g. 100/20 or Home Fast"></div>
                <div><label for="vo-p-dl" style="{{ $__lbl }}">Download Mbps</label><input id="vo-p-dl" class="dd-input" style="width:100%;" inputmode="numeric" placeholder="e.g. 100" aria-describedby="vo-p-speed-hint"></div>
                <div><label for="vo-p-ul" style="{{ $__lbl }}">Upload Mbps</label><input id="vo-p-ul" class="dd-input" style="width:100%;" inputmode="numeric" placeholder="e.g. 20"></div>
                <div><label for="vo-p-cost" style="{{ $__lbl }}">Cost (AUD / month)</label><input id="vo-p-cost" class="dd-input" style="width:100%;" inputmode="decimal" placeholder="0.00" required></div>
                <div><label for="vo-p-sell" style="{{ $__lbl }}">Sell (AUD / month)</label><input id="vo-p-sell" class="dd-input" style="width:100%;" inputmode="decimal" placeholder="0.00" required></div>
            </div>
            <p id="vo-p-speed-hint" style="font-size:12px;color:var(--dd-text-soft);margin:8px 0 0;">Technology and mapping filter which plans the order forms offer (blank = offered for every technology / mapping). Download and upload Mbps (1 to 10000) are used to hide and refuse plans a copper line (FTTN, FTTB, FTTC) cannot carry. Left blank they are filled from a speed tier like "100/20" or a named tier (below); a plan with no speed is never restricted.</p>
            <fieldset style="border:0;margin:12px 0 0;padding:0;">
                <legend style="{{ $__lbl }}">Applies to scopes (none ticked = all scopes)</legend>
                <div id="vo-p-scopes" style="display:flex;gap:16px;flex-wrap:wrap;"></div>
            </fieldset>
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;"><input id="vo-p-active" type="checkbox" checked> Active (offered on order forms)</label>
            <p id="vo-plan-msg" role="alert" style="font-size:12px;margin:10px 0 0;min-height:1em;"></p>
            <div style="display:flex;gap:8px;margin-top:8px;">
                <button type="submit" id="vo-plan-save" class="ui-btn ui-btn--primary ui-btn--sm">Add plan</button>
                <button type="button" id="vo-plan-cancel" class="ui-btn ui-btn--ghost ui-btn--sm" style="display:none;">Cancel edit</button>
            </div>
        </form>

        <details style="border-top:1px solid var(--dd-border);margin-top:16px;padding-top:12px;">
            <summary style="font-size:14px;font-weight:600;cursor:pointer;">Named tier speeds</summary>
            <p style="font-size:12px;color:var(--dd-text-soft);margin:8px 0;">Vocus names some tiers instead of giving a speed (Home Fast ...). These defaults follow NBN's published tiers and can be edited. Leave both blank when the speed is not known. Applied to the sheet import and to plans saved with a named speed tier and no Mbps.</p>
            <div id="vo-tiers" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;"></div>
            <p id="vo-tier-msg" role="alert" style="font-size:12px;margin:10px 0 0;min-height:1em;"></p>
            <button type="button" id="vo-tier-save" class="ui-btn ui-btn--secondary ui-btn--sm" style="margin-top:6px;">Save tier speeds</button>
        </details>
    </div>
    <x-slot:footer>
        <button type="button" class="ui-btn ui-btn--ghost" data-modal-close>Close</button>
    </x-slot:footer>
</x-ui.modal>

<script>
(function () {
    var data = JSON.parse(document.getElementById('vocus-ordering-data').textContent);
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function el(tag, text, attrs) {
        var n = document.createElement(tag);
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        return n;
    }
    function say(node, text, ok) {
        node.textContent = text || '';
        node.style.color = ok ? 'var(--dd-status-success-fg)' : 'var(--dd-danger-text)';
    }
    function api(method, url, body) {
        return fetch(url, {
            method: method,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body === undefined ? undefined : JSON.stringify(body),
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j }; });
        });
    }
    function firstError(j, status) {
        if (j && j.errors) { var f = Object.keys(j.errors).map(function (k) { return j.errors[k]; }).reduce(function (a, b) { return a.concat(b); }, [])[0]; if (f) { return f; } }
        return (j && (j.message || j.error)) || ('Request failed (HTTP ' + status + ').');
    }

    // ---- realms
    var realms = data.realms.slice();
    var realmList = document.getElementById('vo-realm-list');
    var realmMsg = document.getElementById('vo-realm-msg');
    function drawRealms() {
        realmList.textContent = '';
        if (!realms.length) { realmList.appendChild(el('li', 'No realms yet.', { style: 'font-size:13px;color:var(--dd-text-soft);' })); }
        realms.forEach(function (r, i) {
            var li = el('li', null, { style: 'display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 10px;border:1px solid var(--dd-border);border-radius:6px;' });
            li.appendChild(el('code', r));
            var rm = el('button', 'Remove', { type: 'button', 'class': 'ui-btn ui-btn--ghost ui-btn--sm', 'aria-label': 'Remove realm ' + r });
            rm.addEventListener('click', function () { realms.splice(i, 1); drawRealms(); });
            li.appendChild(rm);
            realmList.appendChild(li);
        });
    }
    document.getElementById('vo-realm-add').addEventListener('click', function () {
        var input = document.getElementById('vo-realm-input');
        var v = input.value.trim().toLowerCase();
        if (!/^(?=.{3,61}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$/.test(v)) { say(realmMsg, 'Enter a hostname such as example.com.au.'); return; }
        if (realms.indexOf(v) !== -1) { say(realmMsg, 'That realm is already in the list.'); return; }
        realms.push(v); input.value = ''; say(realmMsg, ''); drawRealms();
    });
    document.getElementById('vo-realm-input').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('vo-realm-add').click(); } });
    document.getElementById('vo-realm-save').addEventListener('click', function () {
        api('PUT', data.urls.realms, { realms: realms }).then(function (r) {
            if (r.ok) { realms = r.json.realms; drawRealms(); say(realmMsg, 'Realms saved.', true); } else { say(realmMsg, firstError(r.json, r.status)); }
        }).catch(function () { say(realmMsg, 'Could not reach the server.'); });
    });
    drawRealms();

    // ---- scopes
    var scopeList = document.getElementById('vo-scope-list');
    var scopeMsg = document.getElementById('vo-scope-msg');
    Object.keys(data.scopes).forEach(function (key) {
        var label = el('label', null, { style: 'display:flex;align-items:center;gap:8px;font-size:14px;' });
        var cb = el('input', null, { type: 'checkbox', value: key, 'data-scope': key });
        cb.checked = data.enabled.indexOf(key) !== -1;
        label.appendChild(cb);
        label.appendChild(el('span', key + ' - ' + data.scopes[key]));
        scopeList.appendChild(label);
    });
    document.getElementById('vo-scope-save').addEventListener('click', function () {
        var chosen = Array.prototype.map.call(scopeList.querySelectorAll('input:checked'), function (c) { return c.value; });
        if (!chosen.length) { say(scopeMsg, 'Enable at least one service scope.'); return; }
        api('PUT', data.urls.scopes, { scopes: chosen }).then(function (r) {
            say(scopeMsg, r.ok ? 'Scopes saved.' : firstError(r.json, r.status), r.ok);
        }).catch(function () { say(scopeMsg, 'Could not reach the server.'); });
    });

    // ---- plans
    function $(id) { return document.getElementById(id); }
    var plans = data.plans.slice();
    var rows = $('vo-plan-rows');
    var planMsg = $('vo-plan-msg');
    var editing = null;
    var scopeBox = $('vo-p-scopes');
    var techNames = data.technologies;
    var mapLabel = function (k) { return k ? (data.mappings[k] || k) : '-'; };
    var sort = { key: 'name', dir: 1 };
    Object.keys(data.scopes).forEach(function (key) {
        var label = el('label', null, { style: 'display:flex;align-items:center;gap:6px;font-size:13px;' });
        label.appendChild(el('input', null, { type: 'checkbox', value: key }));
        label.appendChild(el('span', key));
        scopeBox.appendChild(label);
    });
    function fillSelect(sel, items, blankLabel) {
        sel.textContent = '';
        sel.appendChild(el('option', blankLabel, { value: '' }));
        items.forEach(function (it) { sel.appendChild(el('option', it[1], { value: it[0] })); });
    }
    var techItems = techNames.map(function (t) { return [t, t]; });
    var mapItems = Object.keys(data.mappings).map(function (k) { return [k, data.mappings[k]]; });
    fillSelect($('vo-f-tech'), techItems, 'All technologies');
    fillSelect($('vo-f-map'), mapItems, 'All mappings');
    fillSelect($('vo-p-tech'), techItems, 'Any technology');
    fillSelect($('vo-p-map'), mapItems, 'Any mapping');

    // account mapping
    var mappingSel = $('vo-mapping'), mappingMsg = $('vo-mapping-msg');
    mapItems.forEach(function (it) { mappingSel.appendChild(el('option', it[1], { value: it[0] })); });
    mappingSel.value = data.mapping;
    function importLabel() { $('vo-import-only-label').textContent = 'Only import ' + data.mappings[data.mapping] + ' rows (the account mapping)'; }
    importLabel();
    mappingSel.addEventListener('change', function () {
        api('PUT', data.urls.mapping, { plan_mapping: mappingSel.value }).then(function (r) {
            if (r.ok) { data.mapping = r.json.plan_mapping; importLabel(); say(mappingMsg, 'Account mapping saved: ' + data.mappings[data.mapping] + '.', true); }
            else { mappingSel.value = data.mapping; say(mappingMsg, firstError(r.json, r.status)); }
        }).catch(function () { mappingSel.value = data.mapping; say(mappingMsg, 'Could not reach the server.'); });
    });

    function money(v) { return '$' + Number(v).toFixed(2); }
    var columns = [
        ['plan_id', 'Plan ID'], ['name', 'Name'], ['technology', 'Technology'], ['mapping', 'Mapping'], ['speed_tier', 'Speed'],
        ['download_mbps', 'Down / Up Mbps'], ['scopes', 'Scopes'], ['cost_price', 'Cost'], ['sell_price', 'Sell'], ['active', 'Active'],
    ];
    function sortValue(p, key) {
        if (key === 'scopes') { return p.scopes.join(','); }
        if (key === 'cost_price' || key === 'sell_price') { return Number(p[key]); }
        if (key === 'download_mbps') { return p.download_mbps || 0; }
        if (key === 'active') { return p.active ? 1 : 0; }
        return (p[key] || '').toString().toLowerCase();
    }
    function drawHead() {
        var head = $('vo-plan-head');
        head.textContent = '';
        columns.forEach(function (c) {
            var th = el('th', null, { scope: 'col', 'aria-sort': sort.key === c[0] ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none' });
            if (c[0] === 'cost_price' || c[0] === 'sell_price') { th.style.textAlign = 'right'; }
            var b = el('button', c[1] + (sort.key === c[0] ? (sort.dir === 1 ? ' ▲' : ' ▼') : ''), { type: 'button', 'class': 'ui-btn ui-btn--ghost ui-btn--sm', style: 'font-weight:inherit;padding:0 2px;' });
            b.addEventListener('click', function () { sort = { key: c[0], dir: sort.key === c[0] ? -sort.dir : 1 }; drawPlans(); });
            th.appendChild(b); head.appendChild(th);
        });
        head.appendChild(el('th', null, { 'aria-label': 'Actions' }));
    }
    function visiblePlans() {
        var q = $('vo-f-q').value.trim().toLowerCase(), t = $('vo-f-tech').value, m = $('vo-f-map').value;
        return plans.filter(function (p) {
            return (!t || p.technology === t) && (!m || p.mapping === m)
                && (!q || p.plan_id.toLowerCase().indexOf(q) !== -1 || p.name.toLowerCase().indexOf(q) !== -1);
        }).sort(function (a, b) {
            var x = sortValue(a, sort.key), y = sortValue(b, sort.key);
            return (x < y ? -1 : x > y ? 1 : a.plan_id.localeCompare(b.plan_id)) * sort.dir;
        });
    }
    function drawPlans() {
        drawHead();
        rows.textContent = '';
        var list = visiblePlans();
        $('vo-f-count').textContent = list.length + ' of ' + plans.length + ' plans';
        if (!list.length) {
            var tr = el('tr'); var td = el('td', plans.length ? 'No plans match the filters.' : 'No plans yet. Add one below or import the Vocus sheet.', { colspan: '11', style: 'text-align:center;padding:16px;color:var(--dd-text-soft);' });
            tr.appendChild(td); rows.appendChild(tr);
        }
        list.forEach(function (p) {
            var tr = el('tr');
            tr.appendChild(el('td', p.plan_id, { style: 'font-family:monospace;' }));
            tr.appendChild(el('td', p.name));
            tr.appendChild(el('td', p.technology || 'Any'));
            tr.appendChild(el('td', mapLabel(p.mapping)));
            tr.appendChild(el('td', p.speed_tier || '-'));
            tr.appendChild(el('td', (p.download_mbps || p.upload_mbps) ? ('↓' + (p.download_mbps || '?') + ' / ↑' + (p.upload_mbps || '?')) : 'not set', (p.download_mbps || p.upload_mbps) ? {} : { style: 'color:var(--dd-text-soft);' }));
            tr.appendChild(el('td', p.scopes.length ? p.scopes.join(', ') : 'All'));
            tr.appendChild(el('td', money(p.cost_price), { style: 'text-align:right;' }));
            tr.appendChild(el('td', money(p.sell_price), { style: 'text-align:right;' }));
            tr.appendChild(el('td', p.active ? 'Yes' : 'No'));
            var actions = el('td', null, { style: 'white-space:nowrap;' });
            var ed = el('button', 'Edit', { type: 'button', 'class': 'ui-btn ui-btn--ghost ui-btn--sm', 'aria-label': 'Edit plan ' + p.plan_id });
            ed.addEventListener('click', function () { startEdit(p); });
            var del = el('button', 'Delete', { type: 'button', 'class': 'ui-btn ui-btn--ghost ui-btn--sm', 'aria-label': 'Delete plan ' + p.plan_id });
            del.addEventListener('click', function () {
                if (!window.confirm('Delete plan ' + p.plan_id + '? Existing services keep their recorded plan and prices.')) { return; }
                api('DELETE', data.urls.plan + '/' + p.id).then(function (r) {
                    if (r.ok) { plans = plans.filter(function (x) { return x.id !== p.id; }); if (editing === p.id) { resetForm(); } drawPlans(); say(planMsg, 'Plan deleted.', true); }
                    else { say(planMsg, firstError(r.json, r.status)); }
                }).catch(function () { say(planMsg, 'Could not reach the server.'); });
            });
            actions.appendChild(ed); actions.appendChild(del); tr.appendChild(actions);
            rows.appendChild(tr);
        });
    }
    ['vo-f-q', 'vo-f-tech', 'vo-f-map'].forEach(function (id) { $(id).addEventListener(id === 'vo-f-q' ? 'input' : 'change', drawPlans); });
    function resetForm() {
        editing = null;
        ['vo-p-id', 'vo-p-name', 'vo-p-speed', 'vo-p-dl', 'vo-p-ul', 'vo-p-cost', 'vo-p-sell'].forEach(function (id) { $(id).value = ''; });
        $('vo-p-tech').value = ''; $('vo-p-map').value = '';
        $('vo-p-active').checked = true;
        Array.prototype.forEach.call(scopeBox.querySelectorAll('input'), function (c) { c.checked = false; });
        $('vo-plan-form-title').textContent = 'Add plan'; $('vo-plan-save').textContent = 'Add plan'; $('vo-plan-cancel').style.display = 'none';
    }
    function startEdit(p) {
        editing = p.id;
        $('vo-p-id').value = p.plan_id; $('vo-p-name').value = p.name; $('vo-p-speed').value = p.speed_tier || '';
        $('vo-p-tech').value = p.technology || ''; $('vo-p-map').value = p.mapping || '';
        $('vo-p-dl').value = p.download_mbps || ''; $('vo-p-ul').value = p.upload_mbps || '';
        $('vo-p-cost').value = p.cost_price; $('vo-p-sell').value = p.sell_price; $('vo-p-active').checked = !!p.active;
        Array.prototype.forEach.call(scopeBox.querySelectorAll('input'), function (c) { c.checked = p.scopes.indexOf(c.value) !== -1; });
        $('vo-plan-form-title').textContent = 'Edit plan ' + p.plan_id; $('vo-plan-save').textContent = 'Save changes'; $('vo-plan-cancel').style.display = '';
        $('vo-p-id').focus();
    }
    $('vo-plan-cancel').addEventListener('click', function () { resetForm(); say(planMsg, ''); });
    $('vo-plan-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var body = {
            plan_id: $('vo-p-id').value.trim(), name: $('vo-p-name').value.trim(), speed_tier: $('vo-p-speed').value.trim(),
            technology: $('vo-p-tech').value || null, mapping: $('vo-p-map').value || null,
            download_mbps: $('vo-p-dl').value.trim() || null, upload_mbps: $('vo-p-ul').value.trim() || null,
            cost_price: $('vo-p-cost').value.trim(), sell_price: $('vo-p-sell').value.trim(), active: $('vo-p-active').checked,
            scopes: Array.prototype.map.call(scopeBox.querySelectorAll('input:checked'), function (c) { return c.value; }),
        };
        var req = editing === null ? api('POST', data.urls.plans, body) : api('PUT', data.urls.plan + '/' + editing, body);
        req.then(function (r) {
            if (!r.ok) { say(planMsg, firstError(r.json, r.status)); return; }
            var saved = r.json.plan;
            plans = plans.filter(function (x) { return x.id !== saved.id; }).concat([saved]);
            resetForm(); drawPlans(); say(planMsg, 'Plan saved.', true);
        }).catch(function () { say(planMsg, 'Could not reach the server.'); });
    });
    drawPlans();

    // ---- named tiers
    var tierBox = $('vo-tiers'), tierMsg = $('vo-tier-msg');
    Object.keys(data.tiers).forEach(function (name, i) {
        var t = data.tiers[name];
        var wrap = el('div', null, { style: 'display:flex;align-items:flex-end;gap:6px;' });
        var lab = el('div', null, { style: 'flex:1;min-width:110px;' });
        lab.appendChild(el('span', name, { style: 'display:block;font-size:12px;font-weight:500;color:var(--dd-text-soft);margin-bottom:4px;' }));
        var d = el('input', null, { 'class': 'dd-input', style: 'width:80px;', inputmode: 'numeric', 'data-tier': name, 'data-dir': 'download', 'aria-label': name + ' download Mbps', placeholder: 'down' });
        var u = el('input', null, { 'class': 'dd-input', style: 'width:80px;', inputmode: 'numeric', 'data-tier': name, 'data-dir': 'upload', 'aria-label': name + ' upload Mbps', placeholder: 'up' });
        d.value = t.download || ''; u.value = t.upload || '';
        wrap.appendChild(lab); wrap.appendChild(d); wrap.appendChild(u);
        tierBox.appendChild(wrap);
    });
    $('vo-tier-save').addEventListener('click', function () {
        var body = { tiers: {} };
        Array.prototype.forEach.call(tierBox.querySelectorAll('input'), function (inp) {
            var n = inp.getAttribute('data-tier');
            body.tiers[n] = body.tiers[n] || {};
            body.tiers[n][inp.getAttribute('data-dir')] = inp.value.trim();
        });
        api('PUT', data.urls.tiers, body).then(function (r) {
            say(tierMsg, r.ok ? 'Tier speeds saved.' : firstError(r.json, r.status), r.ok);
        }).catch(function () { say(tierMsg, 'Could not reach the server.'); });
    });

    // ---- sheet import (preview, then apply)
    var imp = { token: null }, impMsg = $('vo-import-msg');
    function impReset() { imp.token = null; $('vo-import-result').hidden = true; $('vo-import-file').value = ''; }
    $('vo-import-open').addEventListener('click', function () {
        var box = $('vo-import'); box.hidden = !box.hidden;
        if (!box.hidden) { $('vo-import-only').checked = data.mapping === 'DEFAULT_MAP'; $('vo-import-file').focus(); }
    });
    $('vo-import-cancel').addEventListener('click', function () { impReset(); say(impMsg, ''); });
    function upload(url, form) {
        return fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: form })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); });
    }
    function showPreview(j) {
        imp.token = j.token;
        var c = j.counts;
        $('vo-import-counts').textContent = j.total_rows + ' rows in the sheet: ' + c['new'] + ' new, ' + c.updated + ' updated, ' + c.unchanged + ' unchanged' + (c.skipped ? ', ' + c.skipped + ' skipped (other mapping)' : '') + '.';
        var body = $('vo-import-sample'); body.textContent = '';
        [['new', 'New'], ['updated', 'Updated']].forEach(function (g) {
            (j.sample[g[0]] || []).forEach(function (r) {
                var tr = el('tr');
                [g[1], r.plan_id, r.technology, mapLabel(r.mapping), r.speed_tier || '-', (r.download || r.upload) ? ((r.download || '?') + ' / ' + (r.upload || '?')) : 'unknown'].forEach(function (v, i) { tr.appendChild(el('td', v, i === 1 ? { style: 'font-family:monospace;' } : {})); });
                body.appendChild(tr);
            });
        });
        if (!body.children.length) { var tr0 = el('tr'); tr0.appendChild(el('td', 'Nothing to add or change.', { colspan: '6', style: 'color:var(--dd-text-soft);' })); body.appendChild(tr0); }
        var w = $('vo-import-warnings'); w.textContent = '';
        (j.warnings || []).slice(0, 10).forEach(function (m) { w.appendChild(el('li', m)); });
        if ((j.warnings || []).length > 10) { w.appendChild(el('li', '... and ' + (j.warnings.length - 10) + ' more.')); }
        $('vo-import-apply').disabled = (c['new'] + c.updated) === 0;
        $('vo-import-result').hidden = false;
    }
    function previewImport() {
        var form = new FormData(), f = $('vo-import-file').files[0];
        if (f) { form.append('file', f); } else if (imp.token) { form.append('token', imp.token); } else { say(impMsg, 'Choose an .xlsx or .csv sheet first.'); return; }
        form.append('only_mapping', $('vo-import-only').checked ? '1' : '0');
        say(impMsg, 'Reading the sheet...', true);
        upload(data.urls.importPreview, form).then(function (r) {
            if (!r.ok) { $('vo-import-result').hidden = true; say(impMsg, firstError(r.json, r.status)); return; }
            say(impMsg, ''); showPreview(r.json);
            $('vo-import-file').value = ''; // later option changes re-preview from the cached rows
        }).catch(function () { say(impMsg, 'Could not reach the server.'); });
    }
    $('vo-import-preview').addEventListener('click', previewImport);
    $('vo-import-only').addEventListener('change', function () { if (imp.token) { previewImport(); } });
    $('vo-import-apply').addEventListener('click', function () {
        if (!imp.token) { return; }
        api('POST', data.urls.importApply, { token: imp.token, only_mapping: $('vo-import-only').checked }).then(function (r) {
            if (!r.ok) { say(impMsg, firstError(r.json, r.status)); return; }
            plans = r.json.plans; drawPlans(); impReset();
            var c = r.json.counts;
            say(impMsg, 'Import applied: ' + c['new'] + ' added (inactive, $0.00: set prices and activate them), ' + c.updated + ' updated, ' + c.unchanged + ' unchanged.', true);
        }).catch(function () { say(impMsg, 'Could not reach the server.'); });
    });
})();
</script>
