@extends('layouts.app')

@section('content')
<div class="ui-page" id="monitoring-page">
    <x-ui.page-header title="Monitoring" subtitle="Who changed what, what DomainDash exchanged with your integrations, and how background jobs ran.">
        <x-slot name="actions">
            <x-ui.button variant="secondary" size="sm" type="button" id="mon-settings-toggle" aria-expanded="false" aria-controls="mon-settings">Logging settings</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success" :dismissible="true">{{ session('status') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" title="Settings not saved">{{ $errors->first() }}</x-ui.alert>
    @endif

    <section id="mon-settings" class="mon-settings" hidden>
        <x-ui.card title="Logging settings" description="What is recorded and for how long. Secrets are never stored, whatever the level.">
            <form method="POST" action="{{ route('admin.audit.retention') }}" class="mon-settings__grid">
                @csrf
                <x-ui.field label="Keep activity (days)" name="retention_days" type="number" :value="$monitoring['retention']['activity']" min="1" max="3650" required />
                <x-ui.field label="Keep outbound requests (days)" name="retention_days_outbound" type="number" :value="$monitoring['retention']['outbound']" min="1" max="3650" />
                <x-ui.field label="Keep inbound requests (days)" name="retention_days_inbound" type="number" :value="$monitoring['retention']['inbound']" min="1" max="3650" />
                <x-ui.field label="Keep job history (days)" name="retention_days_jobs" type="number" :value="$monitoring['retention']['jobs']" min="1" max="3650" />

                <x-ui.field label="Outbound request logging" name="outbound_level" as="select" help="Errors only keeps the table small; All requests is for troubleshooting.">
                    <option value="errors" @selected($monitoring['outbound_level'] === 'errors')>Errors only</option>
                    <option value="all" @selected($monitoring['outbound_level'] === 'all')>All requests</option>
                </x-ui.field>
                <x-ui.field label="Inbound request logging" name="inbound_level" as="select">
                    <option value="all" @selected($monitoring['inbound_level'] === 'all')>All requests</option>
                    <option value="errors" @selected($monitoring['inbound_level'] === 'errors')>Errors only</option>
                </x-ui.field>

                <div class="mon-settings__checks">
                    <input type="hidden" name="mask_pii" value="0">
                    <label class="mon-check"><input type="checkbox" name="mask_pii" value="1" @checked($monitoring['mask_pii'])> Mask customer e-mail and phone values in stored request bodies</label>
                    <input type="hidden" name="verbose" value="0">
                    <label class="mon-check"><input type="checkbox" name="verbose" value="1" @checked($monitoring['verbose'])> Verbose debug logging (page views, routine job and command events, queue heartbeat)</label>
                    <label class="mon-check"><input type="checkbox" name="prune_now" value="1"> Apply retention now</label>
                </div>

                <div class="mon-settings__foot">
                    <x-ui.button type="submit" variant="primary">Save settings</x-ui.button>
                    <p class="ui-help">
                        Activity table about {{ number_format($dbBytes / 1024, 1) }} KB, integration requests about {{ number_format($requestsBytes / 1024, 1) }} KB,
                        Laravel log file {{ number_format($logFileBytes / 1024, 1) }} KB.
                    </p>
                </div>
            </form>
        </x-ui.card>
    </section>

    <div class="mon-tabs" role="tablist" aria-label="Monitoring sections">
        @foreach ($tabs as $key => $def)
            <button type="button" role="tab" class="mon-tab" id="mon-tab-{{ $key }}" data-mon-tab="{{ $key }}" aria-controls="mon-panel" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" tabindex="{{ $tab === $key ? '0' : '-1' }}">{{ $def['label'] }}</button>
        @endforeach
    </div>

    <x-ui.card id="mon-panel" role="tabpanel" aria-labelledby="mon-tab-{{ $tab }}" :flush="true">
        <div class="mon-toolbar">
            <p id="mon-hint" class="mon-hint"></p>
            <div class="mon-filters">
                <input type="search" id="mon-q" class="ui-input mon-q" placeholder="Search" aria-label="Search this log" autocomplete="off">
                <select id="mon-provider" class="ui-input" aria-label="Filter" hidden></select>
                <select id="mon-status" class="ui-input" aria-label="Status filter"></select>
                <label class="mon-date">From <input type="date" id="mon-from" class="ui-input" aria-label="From date"></label>
                <label class="mon-date">To <input type="date" id="mon-to" class="ui-input" aria-label="To date"></label>
                <label class="mon-check" id="mon-debug-wrap" hidden><input type="checkbox" id="mon-debug"> Include debug events</label>
            </div>
            <div class="mon-pager" aria-label="Pagination">
                <span id="mon-range" class="mon-range" aria-live="polite">0 of 0</span>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" id="mon-prev" aria-label="Previous page">&lsaquo;</button>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" id="mon-next" aria-label="Next page">&rsaquo;</button>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" id="mon-refresh" aria-label="Refresh" title="Refresh"><x-ui.icon name="refresh" :size="16" /></button>
            </div>
        </div>

        <x-ui.table :compact="true">
            <thead><tr id="mon-head"></tr></thead>
            <tbody id="mon-body"><tr><td class="mon-msg">Loading&hellip;</td></tr></tbody>
        </x-ui.table>
        <noscript><p class="mon-msg">JavaScript is required to browse the logs.</p></noscript>
    </x-ui.card>
</div>

<x-ui.modal id="mon-detail" title="Details" size="lg">
    <div class="mon-detail">
        <p id="mon-detail-meta" class="mon-meta"></p>
        <div id="mon-detail-tabs" class="mon-tabs mon-tabs--inner" role="tablist" aria-label="Entry sections"></div>
        <div id="mon-detail-body" class="mon-detail__body" role="tabpanel" tabindex="0"></div>
        <p id="mon-detail-error" class="ui-error" role="alert" hidden></p>
    </div>
    <x-slot name="footer">
        <a id="mon-export" class="ui-btn ui-btn--secondary" href="#" download>Export to JSON</a>
        <button type="button" id="mon-delete" class="ui-btn ui-btn--danger" hidden>Delete</button>
        <button type="button" class="ui-btn ui-btn--ghost" data-modal-close>Close</button>
    </x-slot>
</x-ui.modal>

<span id="mon-ico-success" class="mon-ico mon-ico--success" hidden><x-ui.icon name="check-circle" :size="20" /></span>
<span id="mon-ico-failed" class="mon-ico mon-ico--failed" hidden><x-ui.icon name="x-circle" :size="20" /></span>

<script type="application/json" id="mon-config">@json([
    'tab' => $tab,
    'tabs' => $tabs,
    'urls' => [
        'data' => route('admin.audit.data', ['tab' => '__TAB__']),
        'show' => route('admin.audit.show', ['tab' => '__TAB__', 'id' => '__ID__']),
        'export' => route('admin.audit.export', ['tab' => '__TAB__', 'id' => '__ID__']),
        'destroy' => route('admin.audit.destroy', ['tab' => '__TAB__', 'id' => '__ID__']),
    ],
])</script>

<style>
    #monitoring-page [hidden], #mon-detail [hidden], .mon-ico[hidden] { display: none !important; }
    .mon-settings { margin-bottom: 1rem; }
    .mon-settings__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; align-items: start; }
    .mon-settings__checks, .mon-settings__foot { grid-column: 1 / -1; display: grid; gap: .5rem; }
    .mon-settings__foot { grid-template-columns: auto 1fr; align-items: center; gap: 1rem; }
    .mon-check { display: flex; gap: .5rem; align-items: center; font-size: 13px; color: var(--dd-text); }
    .mon-tabs { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid var(--dd-border); margin-bottom: 1rem; overflow-x: auto; }
    .mon-tabs--inner { margin: 0 0 .75rem; }
    .mon-tab { appearance: none; background: none; border: 0; border-bottom: 2px solid transparent; padding: .6rem .9rem; font: inherit; font-size: 14px; font-weight: 500; color: var(--dd-text-soft); cursor: pointer; white-space: nowrap; display: inline-flex; gap: .4rem; align-items: center; }
    .mon-tab:hover { color: var(--dd-text); }
    .mon-tab[aria-selected="true"] { color: var(--dd-accent-text); border-bottom-color: var(--dd-accent); }
    .mon-tab:focus-visible, .mon-row:focus-visible { outline: 2px solid var(--dd-accent); outline-offset: -2px; }
    .mon-toolbar { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; padding: .75rem 1rem; border-bottom: 1px solid var(--dd-border); }
    .mon-hint { flex: 1 1 100%; margin: 0; font-size: 13px; color: var(--dd-text-soft); }
    .mon-filters { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; flex: 1 1 360px; }
    .mon-filters .ui-input { width: auto; min-width: 0; }
    .mon-q { flex: 1 1 200px; min-width: 160px !important; }
    .mon-date { display: inline-flex; gap: .3rem; align-items: center; font-size: 12px; color: var(--dd-text-soft); }
    .mon-pager { display: flex; gap: .4rem; align-items: center; margin-left: auto; }
    .mon-range { font-size: 13px; color: var(--dd-text-soft); font-variant-numeric: tabular-nums; min-width: 6.5rem; text-align: right; }
    .mon-row { cursor: pointer; }
    .mon-row:hover td { background: var(--dd-surface-muted); }
    .mon-cell-wrap { white-space: normal; word-break: break-word; max-width: 38ch; }
    .mon-sub { display: block; font-size: 12px; color: var(--dd-text-soft); }
    .mon-msg { padding: 1.5rem 1rem; text-align: center; color: var(--dd-text-soft); }
    .mon-msg button { margin-left: .5rem; }
    .mon-meta { margin: 0 0 .75rem; font-size: 13px; color: var(--dd-text-soft); }
    .mon-detail__body { display: grid; gap: .75rem; max-height: 60vh; overflow: auto; }
    .mon-block { display: grid; grid-template-columns: 140px 1fr; gap: .5rem 1rem; align-items: start; font-size: 14px; }
    .mon-label { color: var(--dd-text-soft); font-size: 13px; }
    .mon-value { min-width: 0; word-break: break-word; color: var(--dd-text); }
    .mon-code { margin: 0; padding: .6rem .75rem; background: var(--dd-surface-muted); border: 1px solid var(--dd-border); border-radius: 6px; font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space: pre-wrap; word-break: break-word; max-height: 320px; overflow: auto; color: var(--dd-text); }
    .mon-kv { width: 100%; border-collapse: collapse; font-size: 13px; }
    .mon-kv th, .mon-kv td { text-align: left; padding: 3px 8px 3px 0; vertical-align: top; word-break: break-word; border-bottom: 1px solid var(--dd-border); }
    .mon-kv th { color: var(--dd-text-soft); font-weight: 500; width: 34%; }
    .mon-old { color: var(--dd-danger-text); }
    .mon-new { color: var(--dd-success); }
    .mon-title-ico { display: inline-flex; vertical-align: -4px; margin-right: .4rem; }
    .mon-ico--success { color: var(--dd-success); }
    .mon-ico--failed { color: var(--dd-danger-text); }
    @media (max-width: 640px) {
        .mon-block { grid-template-columns: 1fr; }
        .mon-pager { margin-left: 0; }
        .mon-settings__foot { grid-template-columns: 1fr; }
    }
</style>

<script>
(function () {
    'use strict';
    var cfg = JSON.parse(document.getElementById('mon-config').textContent);
    var COLUMNS = {
        activity: [['When', 'when'], ['User', 'user'], ['Module', 'module'], ['Description', 'description'], ['Reference', 'reference'], ['Status', 'status']],
        outbound: [['ID', 'id'], ['When', 'when'], ['Provider', 'provider'], ['Description', 'request'], ['Reference', 'reference'], ['Status', 'http'], ['Time', 'duration']],
        inbound: [['ID', 'id'], ['When', 'when'], ['API key', 'actor'], ['Request', 'request'], ['Status', 'http'], ['IP', 'ip']],
        errors: [['When', 'when'], ['Module', 'module'], ['Entity', 'entity'], ['Reference', 'reference'], ['Error', 'error']],
        jobs: [['ID', 'id'], ['Started', 'when'], ['Provider', 'provider'], ['Scope', 'scope'], ['Client', 'reference'], ['Items', 'counts'], ['Duration', 'duration_s'], ['Status', 'job']]
    };
    var state = {};
    Object.keys(cfg.tabs).forEach(function (t) { state[t] = { page: 1, q: '', status: '', provider: '', from: '', to: '', debug: false, last: null }; });
    var tab = cfg.tab;
    var detail = { tab: null, id: null, deletable: false, armed: null };
    var searchTimer = null;
    var seq = 0;

    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function fill(url, tabKey, id) {
        return url.replace('__TAB__', encodeURIComponent(tabKey)).replace('__ID__', id === undefined ? '' : encodeURIComponent(id));
    }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function fmtTime(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return isNaN(d.getTime()) ? String(iso) : d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    function badge(text, variant) {
        var b = el('span', 'ui-badge ui-badge--dot ui-badge--' + variant, text);
        return b;
    }
    function httpBadge(row) {
        var code = row.status_code;
        if (code === null || code === undefined || code === '') return badge('No response', 'danger');
        return badge(String(code), code >= 500 ? 'danger' : (code >= 400 ? 'warning' : 'success'));
    }
    function statusBadge(row) {
        if (row.status === 'failed') return badge('Failed', 'danger');
        if (row.status === 'active') return badge('Running', 'info');
        return badge('Success', 'success');
    }
    var JOB_VARIANT = { queued: 'neutral', running: 'info', ok: 'success', partial: 'warning', failed: 'danger', skipped: 'neutral' };

    function cell(kind, row) {
        var td = el('td');
        switch (kind) {
            case 'when': td.textContent = fmtTime(row.when); td.style.whiteSpace = 'nowrap'; break;
            case 'status': td.appendChild(statusBadge(row)); break;
            case 'http': td.appendChild(httpBadge(row)); break;
            case 'job': td.appendChild(badge(row.run_status.charAt(0).toUpperCase() + row.run_status.slice(1), JOB_VARIANT[row.run_status] || 'neutral')); break;
            case 'duration': td.textContent = row.duration_ms === null || row.duration_ms === undefined ? '' : row.duration_ms + ' ms'; td.style.whiteSpace = 'nowrap'; break;
            case 'duration_s': td.textContent = row.duration === null || row.duration === undefined ? '' : row.duration + ' s'; td.style.whiteSpace = 'nowrap'; break;
            case 'request':
                td.className = 'mon-cell-wrap';
                td.textContent = (row.method ? row.method + ' ' : '') + (row.description || '');
                break;
            case 'description':
            case 'error':
            case 'reference':
            case 'counts':
                td.className = 'mon-cell-wrap';
                td.textContent = row[kind] === null || row[kind] === undefined ? '' : String(row[kind]);
                break;
            default: td.textContent = row[kind] === null || row[kind] === undefined ? '' : String(row[kind]);
        }
        return td;
    }

    function renderHead() {
        var tr = $('mon-head');
        tr.textContent = '';
        COLUMNS[tab].forEach(function (c) { var th = el('th', null, c[0]); th.scope = 'col'; tr.appendChild(th); });
    }

    function message(text, retry) {
        var body = $('mon-body');
        body.textContent = '';
        var tr = el('tr'), td = el('td', 'mon-msg', text);
        td.colSpan = COLUMNS[tab].length;
        if (retry) {
            var b = el('button', 'ui-btn ui-btn--secondary ui-btn--sm', 'Retry');
            b.type = 'button';
            b.addEventListener('click', load);
            td.appendChild(b);
        }
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function renderRows(data) {
        var body = $('mon-body');
        body.textContent = '';
        if (!data.rows.length) { message('No entries match.'); return; }
        data.rows.forEach(function (row) {
            var tr = el('tr', 'mon-row');
            tr.tabIndex = 0;
            tr.setAttribute('role', 'button');
            tr.dataset.id = row.id;
            COLUMNS[tab].forEach(function (c) { tr.appendChild(cell(c[1], row)); });
            body.appendChild(tr);
        });
    }

    function renderPager(data) {
        $('mon-range').textContent = data.total === 0 ? '0 of 0' : data.from + '–' + data.to + ' of ' + data.total;
        $('mon-prev').disabled = data.page <= 1;
        $('mon-next').disabled = data.page >= data.last_page;
    }

    function params() {
        var s = state[tab], p = new URLSearchParams();
        p.set('page', s.page);
        ['q', 'status', 'provider', 'from', 'to'].forEach(function (k) { if (s[k]) p.set(k, s[k]); });
        if (s.debug) p.set('debug', '1');
        return p.toString();
    }

    function load() {
        var mine = ++seq, s = state[tab];
        $('mon-body').setAttribute('aria-busy', 'true');
        message('Loading…');
        fetch(fill(cfg.urls.data, tab) + '?' + params(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                if (mine !== seq) return;
                s.last = data;
                if (data.page > data.last_page && data.total > 0) { s.page = data.last_page; load(); return; }
                renderRows(data);
                renderPager(data);
            })
            .catch(function (err) {
                if (mine !== seq) return;
                message('Could not load this log (' + err.message + ').', true);
                $('mon-range').textContent = '0 of 0';
            })
            .then(function () { if (mine === seq) $('mon-body').removeAttribute('aria-busy'); });
    }

    function applyTab(next, push) {
        tab = next;
        var def = cfg.tabs[tab], s = state[tab];
        document.querySelectorAll('[data-mon-tab]').forEach(function (b) {
            var on = b.dataset.monTab === tab;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            b.tabIndex = on ? 0 : -1;
        });
        $('mon-panel').setAttribute('aria-labelledby', 'mon-tab-' + tab);
        $('mon-hint').textContent = def.hint || '';

        var prov = $('mon-provider');
        prov.textContent = '';
        var provKeys = Object.keys(def.providers || {});
        prov.hidden = provKeys.length === 0;
        if (provKeys.length) {
            prov.setAttribute('aria-label', def.providerLabel || 'Filter');
            var all = el('option', null, 'All ' + String(def.providerLabel || 'providers').toLowerCase() + 's');
            all.value = '';
            prov.appendChild(all);
            provKeys.forEach(function (k) { var o = el('option', null, def.providers[k]); o.value = k; prov.appendChild(o); });
            prov.value = s.provider;
        }
        var st = $('mon-status');
        st.textContent = '';
        var stKeys = Object.keys(def.statuses || {});
        st.hidden = stKeys.length === 0;
        if (stKeys.length) {
            var allS = el('option', null, 'Any status');
            allS.value = '';
            st.appendChild(allS);
            stKeys.forEach(function (k) { var o = el('option', null, def.statuses[k]); o.value = k; st.appendChild(o); });
            st.value = s.status;
        }
        $('mon-q').value = s.q;
        $('mon-from').value = s.from;
        $('mon-to').value = s.to;
        $('mon-debug-wrap').hidden = tab !== 'activity';
        $('mon-debug').checked = s.debug;

        renderHead();
        if (push) {
            try { var u = new URL(window.location.href); u.searchParams.set('tab', tab); window.history.replaceState(null, '', u.toString()); } catch (e) { /* ignore */ }
        }
        load();
    }

    // ---- toolbar wiring ----
    document.querySelectorAll('[data-mon-tab]').forEach(function (b) {
        b.addEventListener('click', function () { applyTab(b.dataset.monTab, true); });
        b.addEventListener('keydown', function (e) {
            var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-mon-tab]'));
            var i = tabs.indexOf(b), n = null;
            if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
            if (e.key === 'ArrowLeft') n = tabs[(i - 1 + tabs.length) % tabs.length];
            if (n) { e.preventDefault(); n.focus(); applyTab(n.dataset.monTab, true); }
        });
    });
    function bindFilter(id, key, delay) {
        var node = $(id);
        var handler = function () {
            var s = state[tab];
            s[key] = node.type === 'checkbox' ? node.checked : node.value;
            s.page = 1;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, delay);
        };
        node.addEventListener(node.type === 'search' ? 'input' : 'change', handler);
    }
    bindFilter('mon-q', 'q', 300);
    bindFilter('mon-provider', 'provider', 0);
    bindFilter('mon-status', 'status', 0);
    bindFilter('mon-from', 'from', 0);
    bindFilter('mon-to', 'to', 0);
    bindFilter('mon-debug', 'debug', 0);
    $('mon-prev').addEventListener('click', function () { if (state[tab].page > 1) { state[tab].page--; load(); } });
    $('mon-next').addEventListener('click', function () { var d = state[tab].last; if (d && state[tab].page < d.last_page) { state[tab].page++; load(); } });
    $('mon-refresh').addEventListener('click', load);
    $('mon-settings-toggle').addEventListener('click', function () {
        var box = $('mon-settings'), open = box.hidden;
        box.hidden = !open;
        this.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // ---- detail modal ----
    function renderBlock(block) {
        var wrap = el('div', 'mon-block');
        wrap.appendChild(el('div', 'mon-label', block.label));
        var val = el('div', 'mon-value');
        var v = block.value;
        if (block.type === 'time') {
            val.textContent = fmtTime(v);
            if (v) val.title = String(v);
        } else if (block.type === 'code') {
            if (v === null || v === undefined || v === '') { val.textContent = '—'; } else { val.appendChild(el('pre', 'mon-code', String(v))); }
        } else if (block.type === 'headers') {
            var keys = Object.keys(v || {});
            if (!keys.length) { val.textContent = '—'; } else {
                var t = el('table', 'mon-kv'), tb = el('tbody');
                keys.forEach(function (k) { var r = el('tr'); r.appendChild(el('th', null, k)); r.appendChild(el('td', null, String(v[k]))); tb.appendChild(r); });
                t.appendChild(tb);
                val.appendChild(t);
            }
        } else if (block.type === 'diff') {
            if (!v || !v.length) { val.textContent = 'No field-level changes were captured for this entry.'; } else {
                var dt = el('table', 'mon-kv'), head = el('thead'), hr = el('tr');
                ['Field', 'Old value', 'New value'].forEach(function (h) { hr.appendChild(el('th', null, h)); });
                head.appendChild(hr); dt.appendChild(head);
                var dbd = el('tbody');
                v.forEach(function (d) {
                    var r = el('tr');
                    r.appendChild(el('td', null, d.field));
                    r.appendChild(el('td', 'mon-old', d.old === '' ? '—' : d.old));
                    r.appendChild(el('td', 'mon-new', d.new === '' ? '—' : d.new));
                    dbd.appendChild(r);
                });
                dt.appendChild(dbd);
                val.appendChild(dt);
            }
        } else if (block.type === 'lines') {
            if (!v || !v.length) { val.textContent = 'None recorded (outbound logging is set to errors only).'; } else { val.appendChild(el('pre', 'mon-code', v.join('\n'))); }
        } else {
            val.textContent = v === null || v === undefined || v === '' ? '—' : String(v);
        }
        wrap.appendChild(val);
        return wrap;
    }

    function showTab(data, index) {
        var body = $('mon-detail-body');
        body.textContent = '';
        data.tabs[index].blocks.forEach(function (b) { body.appendChild(renderBlock(b)); });
        $('mon-detail-tabs').querySelectorAll('[role=tab]').forEach(function (b, i) { b.setAttribute('aria-selected', i === index ? 'true' : 'false'); b.tabIndex = i === index ? 0 : -1; });
    }

    function openDetail(row) {
        var id = row.dataset.id;
        var modal = $('mon-detail');
        detail = { tab: tab, id: id, deletable: false, armed: null };
        $('mon-detail-error').hidden = true;
        $('mon-detail-meta').textContent = '';
        $('mon-detail-tabs').textContent = '';
        $('mon-detail-body').textContent = 'Loading…';
        $('mon-detail-title').textContent = 'Loading…';
        $('mon-export').hidden = true;
        $('mon-delete').hidden = true;
        modal.style.display = 'flex';
        modal.__returnFocus = row;
        modal.querySelector('[role="dialog"]').focus();

        fetch(fill(cfg.urls.show, tab, id), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                var title = $('mon-detail-title');
                title.textContent = '';
                if (data.status === 'success' || data.status === 'failed') {
                    var ico = $('mon-ico-' + data.status).cloneNode(true);
                    ico.removeAttribute('id'); ico.hidden = false; ico.classList.add('mon-title-ico');
                    ico.setAttribute('role', 'img'); ico.setAttribute('aria-label', data.status === 'success' ? 'Succeeded' : 'Failed');
                    title.appendChild(ico);
                }
                title.appendChild(document.createTextNode(data.title));
                $('mon-detail-meta').textContent = (data.meta || []).map(function (m) { return m.label + ': ' + m.value; }).join('  ·  ');

                var tabsEl = $('mon-detail-tabs');
                data.tabs.forEach(function (t, i) {
                    var b = el('button', 'mon-tab', t.label);
                    b.type = 'button'; b.setAttribute('role', 'tab');
                    if (t.badge) { b.appendChild(badge(t.badge.text, t.badge.variant)); }
                    b.addEventListener('click', function () { showTab(data, i); });
                    tabsEl.appendChild(b);
                });
                showTab(data, 0);
                var exp = $('mon-export');
                exp.href = fill(cfg.urls.export, detail.tab, id);
                exp.hidden = false;
                detail.deletable = !!data.deletable;
                var del = $('mon-delete');
                del.hidden = !detail.deletable;
                del.textContent = 'Delete';
            })
            .catch(function (err) {
                $('mon-detail-title').textContent = 'Could not load this entry';
                $('mon-detail-body').textContent = '';
                var e = $('mon-detail-error'); e.textContent = err.message === 'HTTP 404' ? 'This entry no longer exists.' : 'Request failed (' + err.message + ').'; e.hidden = false;
            });
    }

    $('mon-body').addEventListener('click', function (e) { var r = e.target.closest('.mon-row'); if (r) openDetail(r); });
    $('mon-body').addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { var r = e.target.closest('.mon-row'); if (r) { e.preventDefault(); openDetail(r); } } });

    $('mon-delete').addEventListener('click', function () {
        var btn = this;
        if (!detail.armed) {
            btn.textContent = 'Click again to confirm';
            detail.armed = setTimeout(function () { detail.armed = null; btn.textContent = 'Delete'; }, 4000);
            return;
        }
        clearTimeout(detail.armed); detail.armed = null;
        btn.disabled = true;
        fetch(fill(cfg.urls.destroy, detail.tab, detail.id), { method: 'DELETE', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, credentials: 'same-origin' })
            .then(function (res) { return res.json().catch(function () { return {}; }).then(function (d) { if (!res.ok) throw new Error(d.message || 'HTTP ' + res.status); }); })
            .then(function () { $('mon-detail').style.display = 'none'; load(); })
            .catch(function (err) { var e = $('mon-detail-error'); e.textContent = 'Delete failed: ' + err.message; e.hidden = false; btn.textContent = 'Delete'; })
            .then(function () { btn.disabled = false; });
    });

    applyTab(tab, false);
})();
</script>
@endsection
