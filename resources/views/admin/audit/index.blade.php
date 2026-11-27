@extends('layouts.app')

@section('content')
<div class="ui-page" id="monitoring-page">
    <x-ui.page-header title="Audit Logs" subtitle="Who changed what, what DomainDash exchanged with your integrations, and how background jobs ran.">
        <x-slot name="actions">
            <x-ui.button variant="secondary" size="sm" :href="$vocusUrl">Vocus transactions</x-ui.button>
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
                <x-ui.field :label="'Keep security events (days, minimum ' . config('monitoring.security_retention_floor_days', 365) . ')'" name="retention_days_security" type="number" :value="$monitoring['retention']['security']" :min="config('monitoring.security_retention_floor_days', 365)" max="3650" />

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

    <form id="mon-find" class="mon-find" role="search" autocomplete="off">
        <label for="mon-find-q" class="mon-find__label">Find</label>
        <input type="search" id="mon-find-q" class="ui-input mon-find__input" placeholder="Transaction ID, service ID or domain name" maxlength="120">
        <x-ui.button type="submit" variant="primary" size="sm">Find</x-ui.button>
        <p id="mon-find-result" class="mon-find__result" aria-live="polite"></p>
    </form>

    <div class="mon-tabs" role="tablist" aria-label="Audit Logs sections">
        @foreach ($tabs as $key => $def)
            <button type="button" role="tab" class="mon-tab" id="mon-tab-{{ $key }}" data-mon-tab="{{ $key }}" aria-controls="mon-panel" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" tabindex="{{ $tab === $key ? '0' : '-1' }}">{{ $def['label'] }}</button>
        @endforeach
        <a class="mon-tab mon-tab--link" href="{{ $vocusUrl }}">Vocus transactions</a>
    </div>

    <x-ui.card id="mon-panel" role="tabpanel" aria-labelledby="mon-tab-{{ $tab }}" :flush="true">
        <div class="mon-toolbar">
            <p id="mon-hint" class="mon-hint"></p>
            <div class="mon-filters">
                <input type="search" id="mon-q" class="ui-input mon-q" placeholder="Search description, reference, transaction ID or URL" aria-label="Search this log" autocomplete="off">
                <div id="mon-toggles" class="mon-toggles"></div>
            </div>
            <div class="mon-pager" aria-label="Pagination">
                <span id="mon-range" class="mon-range" aria-live="polite">0 of 0</span>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" id="mon-prev" aria-label="Previous page">&lsaquo;</button>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" id="mon-next" aria-label="Next page">&rsaquo;</button>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" id="mon-refresh" aria-label="Refresh" title="Refresh"><x-ui.icon name="refresh" :size="16" /></button>
            </div>
            <div id="mon-chips" class="mon-chips" aria-label="Filters"></div>
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
<span id="mon-ico-chevron" hidden><x-ui.icon name="chevron-right" :size="14" /></span>
<span id="mon-ico-failed" class="mon-ico mon-ico--failed" hidden><x-ui.icon name="x-circle" :size="20" /></span>

@php $monConfig = [
    'tab' => $tab,
    'tabs' => $tabs,
    'urls' => [
        'data' => route('admin.audit.data', ['tab' => '__TAB__']),
        'show' => route('admin.audit.show', ['tab' => '__TAB__', 'id' => '__ID__']),
        'export' => route('admin.audit.export', ['tab' => '__TAB__', 'id' => '__ID__']),
        'destroy' => route('admin.audit.destroy', ['tab' => '__TAB__', 'id' => '__ID__']),
        'find' => route('admin.audit.find'),
    ],
]; @endphp
<script type="application/json" id="mon-config">{!! json_encode($monConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

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
    .mon-find { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: 1rem; padding: .75rem 1rem; background: var(--dd-surface); border: 1px solid var(--dd-border); border-radius: var(--dd-radius-lg); }
    .mon-find__label { font-size: 13px; font-weight: 600; color: var(--dd-text-soft); }
    .mon-find__input { flex: 1 1 280px; min-width: 0; }
    .mon-find__result { flex: 1 1 100%; margin: 0; font-size: 13px; color: var(--dd-text-soft); }
    .mon-find__result:empty { display: none; }
    .mon-find__result button { appearance: none; background: none; border: 0; padding: 0 .25rem; font: inherit; font-weight: 600; color: var(--dd-accent-text); cursor: pointer; text-decoration: underline; }
    .mon-tab--link { text-decoration: none; margin-left: auto; }
    .mon-toggles { display: flex; flex-wrap: wrap; gap: .25rem 1rem; align-items: center; }
    .mon-chips { flex: 1 1 100%; display: flex; flex-wrap: wrap; gap: .4rem 1.25rem; align-items: center; }
    .mon-chipgroup { display: inline-flex; flex-wrap: wrap; gap: .3rem; align-items: center; }
    .mon-chipgroup__label { font-size: 12px; color: var(--dd-text-soft); margin-right: .15rem; }
    .mon-chip { appearance: none; border: 1px solid var(--dd-border); background: var(--dd-surface); color: var(--dd-text-soft); font: inherit; font-size: 12px; font-weight: 500; padding: .2rem .65rem; border-radius: var(--dd-radius-pill); cursor: pointer; }
    .mon-chip:hover { background: var(--dd-surface-muted); color: var(--dd-text); }
    .mon-chip[aria-pressed="true"] { background: var(--dd-accent-soft); border-color: var(--dd-accent); color: var(--dd-accent-text); }
    .mon-chip:focus-visible { outline: 2px solid var(--dd-focus); outline-offset: 1px; }
    .mon-date { display: inline-flex; gap: .3rem; align-items: center; font-size: 12px; color: var(--dd-text-soft); }
    .mon-date .ui-input { width: auto; padding-block: .15rem; font-size: 12px; }
    .mon-line { display: flex; align-items: center; gap: .4rem; min-width: 0; }
    .mon-line__text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; max-width: 46ch; }
    .mon-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
    .mon-expand { appearance: none; background: none; border: 0; padding: 0 .1rem; cursor: pointer; color: var(--dd-text-soft); line-height: 1; border-radius: var(--dd-radius-sm); }
    .mon-expand:focus-visible { outline: 2px solid var(--dd-focus); }
    .mon-expand[aria-expanded="true"] { transform: rotate(90deg); }
    .mon-child td { background: var(--dd-surface-soft); }
    .mon-child td:first-child { padding-left: 1.5rem; }
    .mon-pager { display: flex; gap: .4rem; align-items: center; margin-left: auto; }
    .mon-range { font-size: 13px; color: var(--dd-text-soft); font-variant-numeric: tabular-nums; min-width: 6.5rem; text-align: right; }
    .mon-row { cursor: pointer; }
    .mon-row:hover td { background: var(--dd-surface-muted); }
    .mon-cell-wrap { white-space: normal; word-break: break-word; max-width: 38ch; }
    .mon-sub--env { text-transform: capitalize; }
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
        outbound: [['When', 'when'], ['Provider', 'provider'], ['Request', 'request'], ['Reference', 'ref'], ['Transaction', 'txn'], ['Status', 'http'], ['Time', 'duration']],
        inbound: [['When', 'when'], ['API key', 'actor'], ['Request', 'request'], ['Reference', 'ref'], ['Status', 'http'], ['IP', 'ip']],
        errors: [['When', 'when'], ['Module', 'module'], ['Reference', 'ref'], ['Transaction', 'txn'], ['Error', 'error']],
        jobs: [['ID', 'id'], ['Started', 'when'], ['Provider', 'provider'], ['Scope', 'scope'], ['Client', 'reference'], ['Items', 'counts'], ['Duration', 'duration_s'], ['Status', 'job']]
    };
    var PRESETS = [['', 'Any time'], ['today', 'Today'], ['7d', '7 days'], ['30d', '30 days']];
    var state = {};
    Object.keys(cfg.tabs).forEach(function (t) { state[t] = { page: 1, q: '', status: '', provider: '', entity: '', from: '', to: '', debug: false, system: false, group: true, last: null }; });
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
            case 'when':
                td.textContent = fmtTime(row.when); td.style.whiteSpace = 'nowrap';
                if (row.first_when) { td.appendChild(el('span', 'mon-sub', 'since ' + fmtTime(row.first_when))); }
                break;
            case 'status': td.appendChild(statusBadge(row)); break;
            case 'http': td.appendChild(httpBadge(row)); break;
            case 'job': td.appendChild(badge(row.run_status.charAt(0).toUpperCase() + row.run_status.slice(1), JOB_VARIANT[row.run_status] || 'neutral')); break;
            case 'duration': td.textContent = row.duration_ms === null || row.duration_ms === undefined ? '' : row.duration_ms + ' ms'; td.style.whiteSpace = 'nowrap'; break;
            case 'duration_s': td.textContent = row.duration === null || row.duration === undefined ? '' : row.duration + ' s'; td.style.whiteSpace = 'nowrap'; break;
            case 'request':
                var line = el('div', 'mon-line');
                if (row.count > 1 && row.fp) {
                    var ex = el('button', 'mon-expand');
                    ex.type = 'button'; ex.setAttribute('aria-expanded', 'false'); ex.setAttribute('aria-label', 'Show the ' + row.count + ' requests in this group');
                    ex.appendChild(document.getElementById('mon-ico-chevron').cloneNode(true).firstElementChild);
                    ex.dataset.expand = row.id;
                    line.appendChild(ex);
                }
                var txt = el('span', 'mon-line__text', (row.method && row.method !== 'SOAP' ? row.method + ' ' : '') + (row.description || ''));
                txt.title = txt.textContent;
                line.appendChild(txt);
                if (row.count > 1) { var c = badge('\u00d7' + row.count, 'neutral'); c.classList.remove('ui-badge--dot'); c.title = row.count + ' identical requests'; line.appendChild(c); }
                td.appendChild(line);
                break;
            case 'ref':
                td.className = 'mon-cell-wrap';
                td.textContent = (row.entity ? row.entity + ': ' : '') + (row.reference || '');
                if (row.environment) { td.appendChild(el('span', 'mon-sub mon-sub--env', row.environment)); }
                break;
            case 'txn':
                td.className = 'mon-mono';
                td.textContent = row.transaction_id || '';
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

    function rowEl(row, child) {
        var tr = el('tr', 'mon-row' + (child ? ' mon-child' : ''));
        tr.tabIndex = 0;
        tr.setAttribute('role', 'button');
        tr.dataset.id = row.id;
        COLUMNS[tab].forEach(function (c) { tr.appendChild(cell(c[1], row)); });
        return tr;
    }

    function renderRows(data) {
        var body = $('mon-body');
        body.textContent = '';
        if (!data.rows.length) { message('No entries match.'); return; }
        data.rows.forEach(function (row) {
            var tr = rowEl(row, false);
            if (row.fp) { tr.__fp = row.fp; }
            body.appendChild(tr);
        });
    }

    function toggleGroup(btn) {
        var tr = btn.closest('tr'), open = btn.getAttribute('aria-expanded') === 'true';
        if (open) {
            btn.setAttribute('aria-expanded', 'false');
            while (tr.nextSibling && tr.nextSibling.classList && tr.nextSibling.classList.contains('mon-child')) { tr.parentNode.removeChild(tr.nextSibling); }
            return;
        }
        btn.setAttribute('aria-expanded', 'true');
        var s = state[tab], p = new URLSearchParams();
        p.set('page', 1); p.set('fp', JSON.stringify(tr.__fp || {}));
        fetch(fill(cfg.urls.data, tab) + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                var anchor = tr;
                data.rows.forEach(function (r) { var c = rowEl(r, true); anchor.parentNode.insertBefore(c, anchor.nextSibling); anchor = c; });
                if (data.total > data.rows.length) {
                    var more = el('tr', 'mon-child'), td = el('td', 'mon-msg', 'Showing the latest ' + data.rows.length + ' of ' + data.total + '. Narrow the date range to see the rest.');
                    td.colSpan = COLUMNS[tab].length; more.appendChild(td); anchor.parentNode.insertBefore(more, anchor.nextSibling);
                }
            })
            .catch(function () { btn.setAttribute('aria-expanded', 'false'); });
    }

    function renderPager(data) {
        $('mon-range').textContent = data.total === 0 ? '0 of 0' : data.from + '–' + data.to + ' of ' + data.total;
        $('mon-prev').disabled = data.page <= 1;
        $('mon-next').disabled = data.page >= data.last_page;
    }

    function params() {
        var s = state[tab], p = new URLSearchParams();
        p.set('page', s.page);
        ['q', 'status', 'provider', 'entity', 'from', 'to'].forEach(function (k) { if (s[k]) p.set(k, s[k]); });
        if (s.debug) p.set('debug', '1');
        if (s.system) p.set('system', '1');
        if (!s.group) p.set('group', '0');
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

    function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function presetRange(key) {
        var now = new Date(), to = ymd(now);
        if (key === 'today') return [to, to];
        if (key === '7d' || key === '30d') { var f = new Date(now); f.setDate(f.getDate() - (key === '7d' ? 6 : 29)); return [ymd(f), to]; }
        return ['', ''];
    }
    function presetActive(key) {
        var s = state[tab], r = presetRange(key);
        return s.from === r[0] && s.to === r[1];
    }

    function chipGroup(label, options, current, onPick) {
        var g = el('div', 'mon-chipgroup');
        g.setAttribute('role', 'group'); g.setAttribute('aria-label', label);
        g.appendChild(el('span', 'mon-chipgroup__label', label));
        options.forEach(function (o) {
            var b = el('button', 'mon-chip', o[1]);
            b.type = 'button'; b.setAttribute('aria-pressed', current(o[0]) ? 'true' : 'false');
            b.addEventListener('click', function () { onPick(o[0]); renderChips(); state[tab].page = 1; load(); });
            g.appendChild(b);
        });
        return g;
    }

    function renderChips() {
        var def = cfg.tabs[tab], s = state[tab], box = $('mon-chips');
        box.textContent = '';
        function listOf(map, allLabel) {
            var out = [['', allLabel]];
            Object.keys(map || {}).forEach(function (k) { out.push([k, map[k]]); });
            return out;
        }
        if (Object.keys(def.providers || {}).length > 0) {
            box.appendChild(chipGroup(def.providerLabel || 'Provider', listOf(def.providers, 'All'), function (k) { return s.provider === k; }, function (k) { s.provider = k; }));
        }
        if (Object.keys(def.entities || {}).length > 0) {
            box.appendChild(chipGroup('Entity', listOf(def.entities, 'All'), function (k) { return s.entity === k; }, function (k) { s.entity = k; }));
        }
        if (Object.keys(def.statuses || {}).length > 0) {
            box.appendChild(chipGroup('Status', listOf(def.statuses, 'Any'), function (k) { return s.status === k; }, function (k) { s.status = k; }));
        }
        box.appendChild(chipGroup('Date', PRESETS, function (k) { return presetActive(k); }, function (k) { var r = presetRange(k); s.from = r[0]; s.to = r[1]; }));
        var from = el('label', 'mon-date', 'From '), fi = el('input', 'ui-input'); fi.type = 'date'; fi.value = s.from; fi.setAttribute('aria-label', 'From date');
        var to = el('label', 'mon-date', 'To '), ti = el('input', 'ui-input'); ti.type = 'date'; ti.value = s.to; ti.setAttribute('aria-label', 'To date');
        [[fi, 'from'], [ti, 'to']].forEach(function (pair) {
            pair[0].addEventListener('change', function () { s[pair[1]] = pair[0].value; s.page = 1; renderChips(); load(); });
        });
        from.appendChild(fi); to.appendChild(ti);
        box.appendChild(from); box.appendChild(to);
    }

    function renderToggles() {
        var def = cfg.tabs[tab], s = state[tab], box = $('mon-toggles');
        box.textContent = '';
        var items = [];
        if (def.toggle) items.push([def.toggle.key, def.toggle.label]);
        if (tab === 'activity') items.push(['debug', 'Include debug events']);
        if (def.group) items.push(['group', 'Group repeated requests']);
        items.forEach(function (it) {
            var label = el('label', 'mon-check'), cb = el('input');
            cb.type = 'checkbox'; cb.checked = !!s[it[0]];
            cb.addEventListener('change', function () { s[it[0]] = cb.checked; s.page = 1; load(); });
            label.appendChild(cb); label.appendChild(document.createTextNode(' ' + it[1]));
            box.appendChild(label);
        });
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
        $('mon-q').value = s.q;
        renderToggles();
        renderChips();
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
    $('mon-q').addEventListener('input', function () {
        state[tab].q = this.value; state[tab].page = 1;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(load, 300);
    });
    $('mon-body').addEventListener('click', function (e) {
        var ex = e.target.closest('[data-expand]');
        if (ex) { e.stopPropagation(); toggleGroup(ex); }
    }, true);

    // Quick find: which logs mention a transaction id / service id / domain, then jump to the best one.
    $('mon-find').addEventListener('submit', function (e) {
        e.preventDefault();
        var q = $('mon-find-q').value.trim(), out = $('mon-find-result');
        out.textContent = '';
        if (!q) return;
        out.textContent = 'Searching…';
        fetch(cfg.urls.find + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (d) {
                out.textContent = '';
                var hits = Object.keys(d.counts).filter(function (k) { return d.counts[k] > 0; });
                if (!hits.length) { out.textContent = 'Nothing found for "' + q + '".'; return; }
                out.appendChild(document.createTextNode('"' + q + '" appears in:'));
                hits.forEach(function (k) {
                    var b = el('button', null, cfg.tabs[k].label + ' (' + d.counts[k] + ')');
                    b.type = 'button';
                    b.addEventListener('click', function () { var s = state[k]; s.q = q; s.page = 1; s.status = ''; s.provider = ''; s.entity = ''; s.from = ''; s.to = ''; s.system = true; applyTab(k, true); });
                    out.appendChild(b);
                });
                var best = state[d.best]; best.q = q; best.page = 1; best.status = ''; best.provider = ''; best.entity = ''; best.from = ''; best.to = ''; best.system = true;
                applyTab(d.best, true);
            })
            .catch(function (err) { out.textContent = 'Search failed (' + err.message + ').'; });
    });
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
