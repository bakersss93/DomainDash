/*
 * DDList: shared sortable / filterable / selectable list used by the Halo and
 * IT Glue sync lists in Settings. Vanilla JS, no dependencies. Every server
 * string is inserted with textContent (never innerHTML).
 *
 *   const list = DDList.create({
 *     mount, rows, idKey: 'id', storageKey: 'halo-domains',
 *     columns: [{ label, render(row, list) -> Node|string, className? }],
 *     grid: '1fr 1fr 150px',                // columns after the checkbox
 *     searchKeys: ['name', 'client'],
 *     sorts: [{ value, label, compare(a, b) }], defaultSort: 'status',
 *     statusOf(row) -> { key, label, title?, variant?, rank? },
 *     filters: [{ value, label, match(row, status) }],   // chips, first = All
 *     selectable: true,
 *     rowText(row) -> extra searchable text (optional),
 *   });
 *   list.setRows(rows)            replace data (keeps selection of surviving ids), re-sort
 *   list.selectedIds()/selectedRows()
 *   list.setItemState(id, state)  live per-row override ({key,label,title,variant}); null clears
 *   list.setBusy(bool)            disable selection/sorting controls while a run is active
 *   list.resort()                 re-apply sort/filter (e.g. after a run finished)
 *
 * DDList.bindRun(list, opts) wires a SyncRun to per-row live status.
 */
(function () {
    const PAGE = 500;
    const STYLE_ID = 'dd-list-style';
    const VARIANT = { success: 'success', failed: 'danger', danger: 'danger', unsynced: 'neutral', neutral: 'neutral', queued: 'neutral', syncing: 'info', info: 'info', warning: 'warning', unchanged: 'neutral', synced: 'success' };

    function injectStyle() {
        if (document.getElementById(STYLE_ID)) return;
        const st = document.createElement('style');
        st.id = STYLE_ID;
        st.textContent = [
            '.dd-list__toolbar{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin-bottom:10px}',
            '.dd-list__toolbar input[type=search]{flex:1 1 200px;min-width:160px;padding:7px 10px;border:1px solid var(--border-subtle);border-radius:6px;background:var(--surface-elevated,transparent);color:var(--text)}',
            '.dd-list__toolbar select{padding:7px 8px;border:1px solid var(--border-subtle);border-radius:6px;background:var(--surface-elevated,transparent);color:var(--text)}',
            '.dd-list__chips{display:flex;flex-wrap:wrap;gap:6px}',
            '.dd-list__chip{padding:3px 10px;border-radius:999px;border:1px solid var(--border-subtle);background:transparent;color:var(--text-muted);font-size:12px;cursor:pointer}',
            '.dd-list__chip[aria-pressed=true]{background:var(--dd-accent-soft,#e0f2fe);color:var(--dd-accent-text,#0369a1);border-color:var(--dd-accent,#2563eb)}',
            '.dd-list__bar{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;margin-bottom:10px;font-size:13px;color:var(--text-muted)}',
            '.dd-list__btn{padding:3px 10px;border:1px solid var(--border-subtle);border-radius:6px;background:var(--surface-muted);color:var(--text);cursor:pointer;font-size:12px}',
            '.dd-list__btn:disabled,.dd-list select:disabled,.dd-list input:disabled{opacity:.55;cursor:not-allowed}',
            '.dd-list__head,.dd-list__row{display:grid;gap:12px;align-items:center;padding:10px 12px;border-radius:6px}',
            '.dd-list__head{background:var(--surface-muted);font-weight:600;color:var(--dd-text-soft);font-size:13px;margin-bottom:8px}',
            '.dd-list__row{background:var(--surface-soft,var(--surface-muted));margin-bottom:6px;color:var(--text);font-size:14px;transition:background .2s}',
            '.dd-list__row[data-state=syncing]{outline:1px solid var(--dd-accent,#2563eb)}',
            '.dd-list__row[data-state=failed]{outline:1px solid var(--dd-danger-text,#b42318)}',
            '.dd-list__sub{font-size:12px;color:var(--dd-text-soft);margin-top:2px;word-break:break-word}',
            '.dd-list__err{font-size:12px;color:var(--dd-danger-text,#b42318);margin-top:2px;word-break:break-word}',
            '.dd-list__empty{padding:24px;text-align:center;color:var(--dd-text-soft)}',
            '.dd-list__cb{width:18px;height:18px;cursor:pointer}',
            '.dd-list select.dd-list__select{width:100%;padding:6px;border:1px solid var(--border-subtle);border-radius:6px;background:var(--surface-elevated,transparent);color:var(--text)}',
            '.dd-list__spin{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:dd-list-spin .7s linear infinite;margin-right:4px;vertical-align:-1px}',
            '@keyframes dd-list-spin{to{transform:rotate(360deg)}}',
            '@media (prefers-reduced-motion:reduce){.dd-list__spin{animation:none}}',
        ].join('\n');
        document.head.appendChild(st);
    }

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function store(op, key, value) {
        try {
            if (op === 'get') return window.localStorage.getItem(key);
            window.localStorage.setItem(key, value);
        } catch (e) { /* storage unavailable: fall back to defaults */ }
        return null;
    }

    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
    const text = (v) => String(v === null || v === undefined ? '' : v);
    const time = (v) => { const t = v ? Date.parse(v) : NaN; return isNaN(t) ? null : t; };

    function relative(iso) {
        const t = time(iso);
        if (t === null) return '';
        const s = Math.max(0, Math.round((Date.now() - t) / 1000));
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }

    function chip(status) {
        const c = el('span', 'ui-badge ui-badge--dot ui-badge--' + (status.variant || VARIANT[status.key] || 'neutral'));
        if (status.key === 'syncing') c.appendChild(el('span', 'dd-list__spin'));
        c.appendChild(document.createTextNode(status.label));
        if (status.title) c.title = status.title;
        return c;
    }

    function create(opts) {
        injectStyle();
        const idKey = opts.idKey || 'id';
        const grid = (opts.selectable === false ? '' : '28px ') + (opts.grid || '1fr');
        const sorts = opts.sorts || [];
        const filters = opts.filters || [];
        const storageKey = 'dd-list:' + (opts.storageKey || 'default') + ':sort';

        const state = {
            rows: [],
            byId: new Map(),
            nodes: new Map(),
            selected: new Set(),
            overrides: new Map(),
            query: '',
            filter: filters.length ? filters[0].value : null,
            sort: opts.defaultSort || (sorts[0] && sorts[0].value),
            limit: PAGE,
            busy: false,
            visible: [],
        };
        const saved = store('get', storageKey);
        if (saved && sorts.some((s) => s.value === saved)) state.sort = saved;

        const root = el('div', 'dd-list');
        const toolbar = el('div', 'dd-list__toolbar');
        const search = el('input');
        search.type = 'search';
        search.placeholder = opts.searchPlaceholder || 'Filter by name or client…';
        search.setAttribute('aria-label', 'Filter list');
        toolbar.appendChild(search);

        const sortSel = el('select');
        sortSel.setAttribute('aria-label', 'Sort list');
        sorts.forEach((s) => { const o = el('option', null, s.label); o.value = s.value; sortSel.appendChild(o); });
        sortSel.value = state.sort;
        if (sorts.length > 1) toolbar.appendChild(sortSel);

        let chipsWrap = null;
        const chipBtns = [];
        if (filters.length > 1) {
            chipsWrap = el('div', 'dd-list__chips');
            chipsWrap.setAttribute('role', 'group');
            chipsWrap.setAttribute('aria-label', 'Filter by status');
            filters.forEach((f) => {
                const b = el('button', 'dd-list__chip', f.label);
                b.type = 'button';
                b.dataset.filter = f.value;
                b.setAttribute('aria-pressed', f.value === state.filter ? 'true' : 'false');
                b.addEventListener('click', () => { state.filter = f.value; state.limit = PAGE; apply(); });
                chipBtns.push(b);
                chipsWrap.appendChild(b);
            });
            toolbar.appendChild(chipsWrap);
        }
        root.appendChild(toolbar);

        const bar = el('div', 'dd-list__bar');
        const counter = el('span', null, '');
        counter.setAttribute('aria-live', 'polite');
        bar.appendChild(counter);
        const btnVisible = el('button', 'dd-list__btn', 'Select visible');
        const btnAll = el('button', 'dd-list__btn', 'Select all');
        const btnClear = el('button', 'dd-list__btn', 'Clear');
        [btnVisible, btnAll, btnClear].forEach((b) => { b.type = 'button'; if (opts.selectable !== false) bar.appendChild(b); });
        root.appendChild(bar);

        const head = el('div', 'dd-list__head');
        head.style.gridTemplateColumns = grid;
        let headCb = null;
        if (opts.selectable !== false) {
            headCb = el('input', 'dd-list__cb');
            headCb.type = 'checkbox';
            headCb.setAttribute('aria-label', 'Select all visible rows');
            head.appendChild(headCb);
        }
        (opts.columns || []).forEach((c) => head.appendChild(el('div', c.className || '', c.label)));
        root.appendChild(head);

        const body = el('div', 'dd-list__body');
        root.appendChild(body);
        const more = el('button', 'dd-list__btn', '');
        more.type = 'button';
        more.style.display = 'none';
        more.addEventListener('click', () => { state.limit += PAGE; apply(); });
        root.appendChild(more);

        function statusOf(row) {
            const ov = state.overrides.get(row[idKey]);
            if (ov) return ov;
            return opts.statusOf ? opts.statusOf(row) : { key: 'neutral', label: '' };
        }

        function haystack(row) {
            const parts = (opts.searchKeys || ['name']).map((k) => text(row[k]));
            if (opts.rowText) parts.push(opts.rowText(row));
            if (row.sync_error) parts.push(text(row.sync_error));
            return parts.join(' ').toLowerCase();
        }

        function buildNode(row) {
            const node = el('div', 'dd-list__row');
            node.style.gridTemplateColumns = grid;
            const id = row[idKey];
            node.dataset.id = id;
            if (opts.selectable !== false) {
                const cb = el('input', 'dd-list__cb');
                cb.type = 'checkbox';
                cb.checked = state.selected.has(id);
                cb.setAttribute('aria-label', 'Select ' + text(row.name || id));
                cb.addEventListener('change', () => {
                    if (cb.checked) state.selected.add(id); else state.selected.delete(id);
                    updateCounts();
                });
                cb.disabled = state.busy;
                node.appendChild(cb);
                node._cb = cb;
            }
            node._cells = [];
            (opts.columns || []).forEach((c) => {
                const cell = el('div', c.className || '');
                node.appendChild(cell);
                node._cells.push({ cell, col: c });
            });
            fillNode(node, row);
            return node;
        }

        function fillNode(node, row) {
            const st = statusOf(row);
            node.dataset.state = st.key;
            node._cells.forEach(({ cell, col }) => {
                cell.replaceChildren();
                const out = col.render ? col.render(row, api, st) : text(row[col.key]);
                if (out instanceof Node) cell.appendChild(out); else cell.textContent = text(out);
            });
        }

        function nodeFor(row) {
            const id = row[idKey];
            let n = state.nodes.get(id);
            if (!n) { n = buildNode(row); state.nodes.set(id, n); }
            return n;
        }

        function updateCounts() {
            const total = state.rows.length;
            const shown = state.visible.length;
            const sel = state.selected.size;
            counter.textContent = 'Showing ' + shown + ' of ' + total + (opts.selectable === false ? '' : ' · ' + sel + ' selected');
            if (headCb) {
                const sv = state.visible.filter((r) => state.selected.has(r[idKey])).length;
                headCb.checked = shown > 0 && sv === shown;
                headCb.indeterminate = sv > 0 && sv < shown;
            }
            more.style.display = shown > state.limit ? '' : 'none';
            more.textContent = 'Show more (' + Math.min(PAGE, shown - state.limit) + ' of ' + (shown - state.limit) + ' remaining)';
        }

        function compareFn() {
            const s = sorts.find((x) => x.value === state.sort) || sorts[0];
            return s ? (a, b) => s.compare(a, b, statusOf) || collator.compare(text(a.name), text(b.name)) : null;
        }

        function apply() {
            const q = state.query.trim().toLowerCase();
            const f = filters.find((x) => x.value === state.filter);
            let list = state.rows.filter((row) => {
                if (q && haystack(row).indexOf(q) === -1) return false;
                if (f && f.match && !f.match(row, statusOf(row))) return false;
                return true;
            });
            const cmp = compareFn();
            if (cmp) list = list.sort(cmp);
            state.visible = list;

            chipBtns.forEach((b) => b.setAttribute('aria-pressed', b.dataset.filter === state.filter ? 'true' : 'false'));
            const frag = document.createDocumentFragment();
            list.slice(0, state.limit).forEach((row) => frag.appendChild(nodeFor(row)));
            body.replaceChildren();
            if (!list.length) {
                body.appendChild(el('div', 'dd-list__empty', state.rows.length ? 'No rows match the filter.' : (opts.emptyText || 'Nothing to show.')));
            } else {
                body.appendChild(frag);
            }
            updateCounts();
        }

        let timer = null;
        search.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => { state.query = search.value; state.limit = PAGE; apply(); }, 150);
        });
        sortSel.addEventListener('change', () => { state.sort = sortSel.value; store('set', storageKey, state.sort); apply(); });

        function selectMany(rows, on) {
            rows.forEach((r) => {
                const id = r[idKey];
                if (on) state.selected.add(id); else state.selected.delete(id);
                const n = state.nodes.get(id);
                if (n && n._cb) n._cb.checked = on;
            });
            updateCounts();
        }
        btnVisible.addEventListener('click', () => selectMany(state.visible, true));
        btnAll.addEventListener('click', () => selectMany(state.rows, true));
        btnClear.addEventListener('click', () => selectMany(state.rows, false));
        if (headCb) headCb.addEventListener('change', () => selectMany(state.visible, headCb.checked));

        const api = {
            el: root,
            setRows(rows) {
                state.rows = rows || [];
                state.byId = new Map(state.rows.map((r) => [r[idKey], r]));
                // keep selection of ids that still exist; drop stale nodes so they re-render with fresh data
                state.selected = new Set([...state.selected].filter((id) => state.byId.has(id)));
                state.nodes.clear();
                btnAll.textContent = 'Select all (' + state.rows.length + ')';
                apply();
            },
            rows: () => state.rows,
            row: (id) => state.byId.get(id),
            selectedIds: () => [...state.selected],
            selectedRows: () => state.rows.filter((r) => state.selected.has(r[idKey])),
            select(ids, on) { selectMany(ids.map((id) => state.byId.get(id)).filter(Boolean), on !== false); },
            setItemState(id, st) {
                if (st) state.overrides.set(id, st); else state.overrides.delete(id);
                const row = state.byId.get(id);
                const n = state.nodes.get(id);
                if (row && n) fillNode(n, row);
            },
            clearItemStates() {
                const ids = [...state.overrides.keys()];
                state.overrides.clear();
                ids.forEach((id) => { const row = state.byId.get(id), n = state.nodes.get(id); if (row && n) fillNode(n, row); });
            },
            refreshRow(id) { const row = state.byId.get(id), n = state.nodes.get(id); if (row && n) fillNode(n, row); },
            setBusy(on) {
                state.busy = !!on;
                [search, sortSel, btnVisible, btnAll, btnClear, headCb].forEach((c) => { if (c) c.disabled = state.busy && c !== search; });
                chipBtns.forEach((b) => { b.disabled = state.busy; });
                state.nodes.forEach((n) => { if (n._cb) n._cb.disabled = state.busy; });
            },
            resort: apply,
            focusSearch() { search.focus(); },
        };

        if (opts.mount) { opts.mount.replaceChildren(root); }
        api.setRows(opts.rows || []);

        return api;
    }

    // Standard comparators ---------------------------------------------------
    const std = {
        name: (key, dir) => (a, b) => dir * collator.compare(text(a[key || 'name']), text(b[key || 'name'])),
        // dir -1 = newest first; rows without a date sort last
        date: (key, dir) => (a, b) => {
            const x = time(a[key]), y = time(b[key]);
            if (x === null && y === null) return 0;
            if (x === null) return 1;
            if (y === null) return -1;
            return dir * (y - x);
        },
        // status rank first (failed 0, unsynced 1, synced 2), then oldest sync first
        status: (rankOf) => (a, b, statusOf) => {
            const ra = (statusOf(a).rank !== undefined ? statusOf(a).rank : rankOf(a));
            const rb = (statusOf(b).rank !== undefined ? statusOf(b).rank : rankOf(b));
            if (ra !== rb) return ra - rb;
            const x = time(a.synced_at), y = time(b.synced_at);
            if (x !== y) { if (x === null) return -1; if (y === null) return 1; return x - y; }
            return 0;
        },
    };

    /** Status model shared by the domain sync lists (halo: exists_in_halo, itglue: exists_in_itglue). */
    function syncStatus(existsKey) {
        return function (row) {
            if (row.sync_error) return { key: 'failed', label: 'Failed', title: row.sync_error, rank: 0 };
            if (row.synced_at) return { key: 'synced', label: 'Synced ' + relative(row.synced_at), title: 'Last synced ' + new Date(row.synced_at).toLocaleString(), rank: 2 };
            return { key: 'unsynced', label: 'Not synced', title: row[existsKey] ? 'Exists remotely but DomainDash has not pushed it yet' : 'Will be created on the next sync', rank: 1 };
        };
    }

    /**
     * Live per-row status for a queued run. Returns { start(), onUpdate(run), onDone(run) }:
     * pass the last two to DDSync.track. opts: { ids, itemsUrl(runId), onFinished() }.
     */
    function bindRun(list, opts) {
        const ids = opts.ids.slice();
        const outcome = new Map(); // id -> {status, error}
        let since = null;
        let inflight = false;

        function paint(run) {
            let firstPending = true;
            ids.forEach((id) => {
                const o = outcome.get(id);
                if (o && o.status === 'failed') return list.setItemState(id, { key: 'failed', label: 'Failed ✗', title: o.error || 'Failed', rank: 0, variant: 'danger' });
                if (o) return list.setItemState(id, { key: 'synced', label: 'Synced ✓', title: 'Synced just now', rank: 2, variant: 'success' });
                if (run && run.finished) {
                    if (run.status === 'failed' || run.status === 'skipped') {
                        return list.setItemState(id, { key: 'failed', label: run.status === 'skipped' ? 'Not run' : 'Failed ✗', title: run.error || 'Run did not complete', rank: 0, variant: run.status === 'skipped' ? 'warning' : 'danger' });
                    }
                    return list.setItemState(id, { key: 'unchanged', label: 'Unchanged', title: 'Nothing changed since the last sync, so nothing was sent', rank: 2 });
                }
                if (run && run.status === 'running' && firstPending) {
                    firstPending = false;
                    return list.setItemState(id, { key: 'syncing', label: 'Syncing…', rank: 1 });
                }
                list.setItemState(id, { key: 'queued', label: 'Queued', rank: 1 });
            });
        }

        async function poll(run) {
            if (inflight) return;
            inflight = true;
            try {
                const r = await window.DDSync.request(opts.itemsUrl(run.id) + (since ? '?since=' + encodeURIComponent(since) : ''), 'GET');
                if (r.ok && r.data && Array.isArray(r.data.items)) {
                    since = r.data.server_time || since;
                    r.data.items.forEach((it) => outcome.set(it.domain_id, { status: it.status, error: it.error }));
                }
            } catch (e) { /* transient: next poll retries */ }
            inflight = false;
            paint(run);
        }

        return {
            start() { paint({ status: 'queued', finished: false }); },
            onUpdate(run) { return poll(run); },
            async onDone(run) {
                inflight = false;
                await poll(run);
                if (opts.onFinished) opts.onFinished(run, outcome);
            },
        };
    }

    window.DDList = { create, std, syncStatus, bindRun, relative, chip, collator };
})();
