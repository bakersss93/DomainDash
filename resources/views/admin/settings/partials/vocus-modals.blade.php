@php
    $__vo = [
        'realms'     => \App\Services\Vocus\VocusOptions::realms(),
        'enabled'    => \App\Services\Vocus\VocusOptions::enabledScopes(),
        'scopes'     => \App\Services\Vocus\VocusOptions::SCOPES,
        'plans'      => \App\Models\VocusPlan::orderBy('name')->get()->map->toFormArray()->values(),
        'urls'       => [
            'realms' => route('admin.settings.vocus.realms'),
            'scopes' => route('admin.settings.vocus.scopes'),
            'plans'  => route('admin.settings.vocus.plans.store'),
            'plan'   => url('/admin/settings/vocus/plans'),
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
        <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 12px;">Plan ID is the PlanID sent to Vocus. Prices are AUD per month. The cost and sell price are copied onto each service when it is ordered; clients only ever see the sell price.</p>
        <div style="overflow-x:auto;margin-bottom:16px;">
            <table class="dd-services-table" style="width:100%;font-size:13px;">
                <thead><tr><th>Plan ID</th><th>Name</th><th>Speed</th><th>Down / Up Mbps</th><th>Scopes</th><th style="text-align:right;">Cost</th><th style="text-align:right;">Sell</th><th>Active</th><th></th></tr></thead>
                <tbody id="vo-plan-rows"></tbody>
            </table>
        </div>
        <form id="vo-plan-form" novalidate style="border-top:1px solid var(--dd-border);padding-top:14px;">
            <h3 id="vo-plan-form-title" style="font-size:14px;font-weight:600;margin:0 0 10px;">Add plan</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
                <div><label for="vo-p-id" style="{{ $__lbl }}">Plan ID</label><input id="vo-p-id" class="dd-input" style="width:100%;font-family:monospace;" maxlength="30" required></div>
                <div><label for="vo-p-name" style="{{ $__lbl }}">Display name</label><input id="vo-p-name" class="dd-input" style="width:100%;" maxlength="100" required></div>
                <div><label for="vo-p-speed" style="{{ $__lbl }}">Speed tier</label><input id="vo-p-speed" class="dd-input" style="width:100%;" maxlength="40" placeholder="e.g. 100/20 Mbps"></div>
                <div><label for="vo-p-dl" style="{{ $__lbl }}">Download Mbps</label><input id="vo-p-dl" class="dd-input" style="width:100%;" inputmode="numeric" placeholder="e.g. 100" aria-describedby="vo-p-speed-hint"></div>
                <div><label for="vo-p-ul" style="{{ $__lbl }}">Upload Mbps</label><input id="vo-p-ul" class="dd-input" style="width:100%;" inputmode="numeric" placeholder="e.g. 20"></div>
                <div><label for="vo-p-cost" style="{{ $__lbl }}">Cost (AUD / month)</label><input id="vo-p-cost" class="dd-input" style="width:100%;" inputmode="decimal" placeholder="0.00" required></div>
                <div><label for="vo-p-sell" style="{{ $__lbl }}">Sell (AUD / month)</label><input id="vo-p-sell" class="dd-input" style="width:100%;" inputmode="decimal" placeholder="0.00" required></div>
            </div>
            <p id="vo-p-speed-hint" style="font-size:12px;color:var(--dd-text-soft);margin:8px 0 0;">Download and upload Mbps (1 to 10000) are used to hide and refuse plans a copper line (FTTN, FTTB, FTTC) cannot carry. Left blank they are filled from a speed tier like "100/20"; a plan with no speed is never restricted.</p>
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
    var plans = data.plans.slice();
    var rows = document.getElementById('vo-plan-rows');
    var planMsg = document.getElementById('vo-plan-msg');
    var editing = null;
    var scopeBox = document.getElementById('vo-p-scopes');
    Object.keys(data.scopes).forEach(function (key) {
        var label = el('label', null, { style: 'display:flex;align-items:center;gap:6px;font-size:13px;' });
        label.appendChild(el('input', null, { type: 'checkbox', value: key }));
        label.appendChild(el('span', key));
        scopeBox.appendChild(label);
    });
    function money(v) { return '$' + Number(v).toFixed(2); }
    function drawPlans() {
        rows.textContent = '';
        if (!plans.length) {
            var tr = el('tr'); var td = el('td', 'No plans yet. Add one below.', { colspan: '9', style: 'text-align:center;padding:16px;color:var(--dd-text-soft);' });
            tr.appendChild(td); rows.appendChild(tr);
        }
        plans.forEach(function (p) {
            var tr = el('tr');
            tr.appendChild(el('td', p.plan_id, { style: 'font-family:monospace;' }));
            tr.appendChild(el('td', p.name));
            tr.appendChild(el('td', p.speed_tier || '-'));
            tr.appendChild(el('td', (p.download_mbps || p.upload_mbps) ? ('\u2193' + (p.download_mbps || '?') + ' / \u2191' + (p.upload_mbps || '?')) : 'not set', (p.download_mbps || p.upload_mbps) ? {} : { style: 'color:var(--dd-text-soft);' }));
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
    function $(id) { return document.getElementById(id); }
    function resetForm() {
        editing = null;
        ['vo-p-id', 'vo-p-name', 'vo-p-speed', 'vo-p-dl', 'vo-p-ul', 'vo-p-cost', 'vo-p-sell'].forEach(function (id) { $(id).value = ''; });
        $('vo-p-active').checked = true;
        Array.prototype.forEach.call(scopeBox.querySelectorAll('input'), function (c) { c.checked = false; });
        $('vo-plan-form-title').textContent = 'Add plan'; $('vo-plan-save').textContent = 'Add plan'; $('vo-plan-cancel').style.display = 'none';
    }
    function startEdit(p) {
        editing = p.id;
        $('vo-p-id').value = p.plan_id; $('vo-p-name').value = p.name; $('vo-p-speed').value = p.speed_tier || '';
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
            download_mbps: $('vo-p-dl').value.trim() || null, upload_mbps: $('vo-p-ul').value.trim() || null,
            cost_price: $('vo-p-cost').value.trim(), sell_price: $('vo-p-sell').value.trim(), active: $('vo-p-active').checked,
            scopes: Array.prototype.map.call(scopeBox.querySelectorAll('input:checked'), function (c) { return c.value; }),
        };
        var req = editing === null ? api('POST', data.urls.plans, body) : api('PUT', data.urls.plan + '/' + editing, body);
        req.then(function (r) {
            if (!r.ok) { say(planMsg, firstError(r.json, r.status)); return; }
            var saved = r.json.plan;
            plans = plans.filter(function (x) { return x.id !== saved.id; }).concat([saved]).sort(function (a, b) { return a.name.localeCompare(b.name); });
            resetForm(); drawPlans(); say(planMsg, 'Plan saved.', true);
        }).catch(function () { say(planMsg, 'Could not reach the server.'); });
    });
    drawPlans();
})();
</script>
