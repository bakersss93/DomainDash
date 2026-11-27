/*
 * DomainDash mapping table: a compact table that matches values between two systems
 * (HaloPSA-style "Client Mappings"): columns, "1-10 of N" pager with prev/next, a "+ Add" button,
 * edit/delete icons on row hover and an add/edit dialog whose selects are searchable comboboxes.
 *
 * Markup comes from <x-ui.mapping-table> (resources/views/components/ui/mapping-table.blade.php):
 *   <div data-dd-mapping='{"id":..., "mode":"remote|local", "url":..., "columns":[...], "fields":[...], ...}'>
 *
 * Config
 *   mode      "remote": rows come from / are saved to `url` (GET list, POST, PUT {id}, DELETE {id}).
 *             "local":  rows live in the page (config.rows); every change fires `dd-mapping:change`
 *                       ({detail:{id, rows}}) and the page copies them into form inputs.
 *   columns   [{key, label, muted?}]            cell text comes from row.cells[key]
 *   fields    [{key, label, type: combo|select|text, source?, options?, staticOptions?, allowCustom?,
 *               required?, help?, placeholder?, refresh?, paramsFrom?:{param:'#selector'}, locked?:bool}]
 *   rows      row = {id, cells:{col:text}, values:{field: {id,label} | string}}
 *   fixed     true: no Add / Delete (the rows are a fixed list; edit only)
 *   deletable with fixed: keep the row action that deletes ("reset") a row
 *   dialogSize 'lg' for the wide edit dialog (asset mappings)
 *   field types added for asset mappings: checkbox, fieldmap (DomainDash value -> remote field rows, see createFieldMap)
 *   column.badge  {cellText: 'success|neutral|warning|danger'} renders the cell as a status badge
 *   labels    {title, add, empty, entity}
 *   actions   [{label, url, method, reload?}]   extra toolbar buttons (e.g. "Refresh from Halo")
 *   searchable  show a search box (remote: server-side q)
 *   perPage   default 10
 *
 * Page API: container.ddMapping = {reload(), getRows(), setRows(rows)}.
 * Security: every server string is written with textContent, never innerHTML.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
let uid = 0;
const nextId = (p) => `${p}-${++uid}`;

function el(tag, cls, text, attrs) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    if (attrs) Object.keys(attrs).forEach((k) => { if (attrs[k] !== null && attrs[k] !== undefined) n.setAttribute(k, attrs[k]); });
    return n;
}

const SVG = {
    pencil: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>',
    trash: '<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    x: '<path d="M18 6L6 18M6 6l12 12"/>',
    refresh: '<path d="M21 12a9 9 0 01-15.5 6.2L3 16m0 0v5m0-5h5M3 12A9 9 0 0118.5 5.8L21 8m0 0V3m0 5h-5"/>',
    left: '<path d="M15 18l-6-6 6-6"/>',
    right: '<path d="M9 18l6-6-6-6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
};

function icon(name, size = 16) {
    const ns = 'http://www.w3.org/2000/svg';
    const s = document.createElementNS(ns, 'svg');
    ['width', 'height'].forEach((a) => s.setAttribute(a, String(size)));
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor');
    s.setAttribute('stroke-width', '1.9');
    s.setAttribute('stroke-linecap', 'round');
    s.setAttribute('stroke-linejoin', 'round');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    // Static, trusted path data from the SVG table above (never server input).
    s.innerHTML = SVG[name] || '';
    return s;
}

function iconButton(name, label, cls) {
    const b = el('button', 'dd-mt__icon ' + (cls || ''), null, { type: 'button', 'aria-label': label, title: label });
    b.appendChild(icon(name));
    return b;
}

async function request(method, url, body) {
    const opts = {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
    };
    if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch(url, opts);
    } catch (e) {
        const err = new Error('Network error. Check your connection and try again.');
        err.status = 0;
        err.data = {};
        throw err;
    }
    let data = {};
    try { data = await res.json(); } catch (e) { data = {}; }
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0] : null;
        const err = new Error((first && first[0]) || data.error || data.message || ('Request failed (HTTP ' + res.status + ')'));
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

/* ------------------------------------------------------------------ */
/* Searchable select (accessible combobox, no dependencies)           */
/* ------------------------------------------------------------------ */

/**
 * @param {object} o {label, required, placeholder, help, value:{id,label}|null, allowCustom,
 *   load:(q, refresh)=>Promise<{options:[{id,label}], error?, truncated?}>, refreshable, emptyHint, onChange}
 */
function createCombo(o) {
    const id = nextId('dd-combo');
    const wrap = el('div', 'dd-combo');
    const labelRow = el('div', 'dd-combo__labelrow');
    const label = el('label', 'ui-label', o.label || '', { for: id + '-input' });
    if (o.required) label.appendChild(el('span', 'ui-required', '*', { 'aria-hidden': 'true' }));
    labelRow.appendChild(label);
    let refreshBtn = null;
    if (o.refreshable) {
        refreshBtn = el('button', 'dd-combo__refresh', 'Refresh list', { type: 'button' });
        labelRow.appendChild(refreshBtn);
    }
    wrap.appendChild(labelRow);

    const box = el('div', 'dd-combo__box');
    const input = el('input', 'ui-input dd-combo__input', null, {
        id: id + '-input', type: 'text', role: 'combobox', autocomplete: 'off', spellcheck: 'false',
        'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': id + '-list',
        placeholder: o.placeholder || 'Type to search',
    });
    const clear = el('button', 'dd-combo__clear', null, { type: 'button', 'aria-label': 'Clear ' + (o.label || 'selection'), tabindex: '-1' });
    clear.appendChild(icon('x', 14));
    const list = el('ul', 'dd-combo__list', null, { id: id + '-list', role: 'listbox', 'aria-label': o.label || 'Options' });
    list.hidden = true;
    box.append(input, clear, list);
    wrap.appendChild(box);
    if (o.help) wrap.appendChild(el('p', 'ui-help', o.help, { id: id + '-help' }));
    if (o.help) input.setAttribute('aria-describedby', id + '-help');
    const err = el('p', 'ui-error', '', { role: 'alert' });
    err.hidden = true;
    wrap.appendChild(err);

    let value = o.value && (o.value.id !== undefined && o.value.id !== '') ? { id: String(o.value.id), label: String(o.value.label ?? o.value.id) } : null;
    let items = [];
    let active = -1;
    let seq = 0;
    let timer = null;
    let open = false;
    let nextRefresh = false;

    const syncClear = () => { clear.hidden = !(input.value || value); };
    const setValue = (v, silent) => {
        value = v;
        input.value = v ? v.label : '';
        syncClear();
        if (!silent && o.onChange) o.onChange(value);
    };
    const close = () => {
        open = false;
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const paintActive = () => {
        [...list.children].forEach((li, i) => {
            const on = i === active;
            li.classList.toggle('is-active', on);
            if (on) {
                input.setAttribute('aria-activedescendant', li.id);
                li.scrollIntoView({ block: 'nearest' });
            }
        });
        if (active < 0) input.removeAttribute('aria-activedescendant');
    };
    const message = (text, retry) => {
        list.replaceChildren();
        const li = el('li', 'dd-combo__msg', text, { role: 'presentation' });
        if (retry) {
            const b = el('button', 'dd-combo__retry', 'Retry', { type: 'button' });
            b.addEventListener('mousedown', (e) => { e.preventDefault(); fetchOptions(input.value.trim() === (value?.label || '') ? '' : input.value.trim(), true); });
            li.appendChild(document.createTextNode(' '));
            li.appendChild(b);
        }
        list.appendChild(li);
        list.hidden = false;
        open = true;
        input.setAttribute('aria-expanded', 'true');
    };
    const render = (query, truncated) => {
        list.replaceChildren();
        items.forEach((it, i) => {
            const li = el('li', 'dd-combo__opt', null, { id: id + '-o' + i, role: 'option', 'aria-selected': value && value.id === it.id ? 'true' : 'false' });
            li.appendChild(el('span', 'dd-combo__opt-label', it.label));
            if (it.meta && it.meta.group) li.appendChild(el('span', 'dd-combo__opt-meta', it.meta.group));
            if (it.custom) li.classList.add('is-custom');
            li.addEventListener('mousedown', (e) => { e.preventDefault(); pick(i); });
            list.appendChild(li);
        });
        if (!items.length) {
            list.appendChild(el('li', 'dd-combo__msg', query ? 'No matches for "' + query + '".' : (o.emptyHint || 'No options available.'), { role: 'presentation' }));
        } else if (truncated) {
            list.appendChild(el('li', 'dd-combo__msg', 'Keep typing to narrow the list.', { role: 'presentation' }));
        }
        list.hidden = false;
        open = true;
        input.setAttribute('aria-expanded', 'true');
        active = items.length ? Math.max(0, items.findIndex((it) => value && it.id === value.id)) : -1;
        paintActive();
    };
    const pick = (i) => {
        const it = items[i];
        if (!it) return;
        setValue({ id: it.id, label: it.custom ? it.id : it.label });
        close();
    };
    async function fetchOptions(q, forceRefresh) {
        const mine = ++seq;
        message('Loading...');
        let res;
        try {
            res = await o.load(q, forceRefresh || nextRefresh);
            nextRefresh = false;
        } catch (e) {
            if (mine === seq) message((e && e.message) || 'Could not load options.', true);
            return;
        }
        if (mine !== seq) return;
        if (res.error) { message(res.error, true); return; }
        items = (res.options || []).map((x) => ({ id: String(x.id), label: String(x.label), meta: x.meta }));
        if (o.allowCustom && q && !items.some((x) => x.label.toLowerCase() === q.toLowerCase())) {
            items.push({ id: q, label: 'Use "' + q + '"', custom: true });
        }
        render(q, res.truncated);
    }
    const schedule = (q) => { clearTimeout(timer); timer = setTimeout(() => fetchOptions(q), 180); };

    input.addEventListener('focus', () => { if (!open) fetchOptions(''); });
    input.addEventListener('click', () => { if (!open) fetchOptions(''); });
    input.addEventListener('input', () => {
        if (value && input.value !== value.label) { value = null; if (o.onChange) o.onChange(null); }
        syncClear();
        schedule(input.value.trim());
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!open) { fetchOptions(''); return; }
            if (!items.length) return;
            active = e.key === 'ArrowDown' ? (active + 1) % items.length : (active - 1 + items.length) % items.length;
            paintActive();
        } else if (e.key === 'Home' && open && items.length) { active = 0; paintActive(); e.preventDefault(); }
        else if (e.key === 'End' && open && items.length) { active = items.length - 1; paintActive(); e.preventDefault(); }
        else if (e.key === 'Enter') {
            if (open && active >= 0) { e.preventDefault(); pick(active); }
        } else if (e.key === 'Escape') {
            if (open) { e.preventDefault(); e.stopPropagation(); close(); }
        } else if (e.key === 'Tab') { close(); }
    });
    input.addEventListener('blur', () => {
        setTimeout(() => {
            if (document.activeElement === input) return;
            close();
            const typed = input.value.trim();
            if (value && typed === value.label) return;
            if (o.allowCustom && typed) { setValue({ id: typed, label: typed }); return; }
            input.value = value ? value.label : '';
            syncClear();
        }, 120);
    });
    clear.addEventListener('mousedown', (e) => { e.preventDefault(); });
    clear.addEventListener('click', () => { setValue(null); input.focus(); fetchOptions(''); });
    if (refreshBtn) refreshBtn.addEventListener('click', () => { nextRefresh = true; input.focus(); fetchOptions(input.value.trim() === (value?.label || '') ? '' : input.value.trim(), true); });

    setValue(value, true);

    return {
        el: wrap,
        input,
        get value() { return value; },
        set: (v) => setValue(v, true),
        setError: (m) => { err.textContent = m || ''; err.hidden = !m; wrap.classList.toggle('has-error', !!m); input.setAttribute('aria-invalid', m ? 'true' : 'false'); },
    };
}

/* ------------------------------------------------------------------ */
/* Dialog shell (add/edit and confirm)                                */
/* ------------------------------------------------------------------ */

function openDialog({ title, size, body, footer, onClose }) {
    const prevFocus = document.activeElement;
    const root = el('div', 'dd-mtm');
    const backdrop = el('div', 'dd-mtm__backdrop');
    const dialog = el('div', 'dd-mtm__dialog' + (size === 'sm' ? ' dd-mtm__dialog--sm' : (size === 'lg' ? ' dd-mtm__dialog--lg' : '')), null, { role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    const tid = nextId('dd-mtm-title');
    dialog.setAttribute('aria-labelledby', tid);
    const head = el('div', 'dd-mtm__head');
    head.appendChild(el('h2', 'dd-mtm__title', title, { id: tid }));
    const x = iconButton('x', 'Close dialog', 'dd-mt__icon--plain');
    head.appendChild(x);
    const main = el('div', 'dd-mtm__body');
    main.appendChild(body);
    dialog.append(head, main);
    if (footer) { const f = el('div', 'dd-mtm__foot'); f.appendChild(footer); dialog.appendChild(f); }
    root.append(backdrop, dialog);
    document.body.appendChild(root);

    const close = () => {
        document.removeEventListener('keydown', onKey, true);
        root.remove();
        if (prevFocus && prevFocus.focus) prevFocus.focus();
        if (onClose) onClose();
    };
    function onKey(e) {
        if (e.key === 'Escape') {
            // The combobox handles its own Escape (closing the list) before this point.
            e.stopImmediatePropagation();
            e.preventDefault();
            close();
        } else if (e.key === 'Tab') {
            const f = [...dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')].filter((n) => n.offsetParent !== null);
            if (!f.length) return;
            const first = f[0];
            const last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }
    document.addEventListener('keydown', onKey, true);
    backdrop.addEventListener('click', close);
    x.addEventListener('click', close);
    setTimeout(() => (dialog.querySelector('input, select, button.ui-btn--primary') || dialog).focus(), 0);

    return { close, dialog, root };
}

/* ------------------------------------------------------------------ */
/* Field map: DomainDash value -> field of the chosen remote asset type */
/* ------------------------------------------------------------------ */

/**
 * @param {object} f  {key, label, source, typeParam, typeField, suggestUrl ('{id}' placeholder), provider:'halo'|'itglue',
 *   noneLabel, keyFields:bool, help}
 * @param {object} cur {entity, points:[{key,label}], map:{point:{id,label}}, key_fields:{1..3: point}|null}
 * @param {object} ctx {typeValue:()=>{id,label}|null, onType:(fn)=>void}
 * One native <select> per DomainDash value, filled from the asset type's fields (loaded live, cached server side).
 * Changing the asset type clears the mapping and suggests fields by name. All server strings use textContent.
 */
function createFieldMap(f, cur, ctx) {
    cur = cur || { entity: '', points: [], map: {}, key_fields: null };
    const wrap = el('div', 'dd-fm');
    const none = f.noneLabel || "Don't sync";

    const head = el('div', 'dd-fm__head');
    head.appendChild(el('span', 'ui-label', f.label));
    const suggestBtn = el('button', 'ui-btn ui-btn--secondary ui-btn--sm', f.suggestLabel || 'Suggest by name', { type: 'button' });
    head.appendChild(suggestBtn);
    wrap.appendChild(head);
    const status = el('p', 'dd-fm__status', '', { role: 'status', 'aria-live': 'polite' });
    wrap.appendChild(status);
    const say = (m, bad) => { status.textContent = m || ''; status.classList.toggle('is-error', !!bad); };

    const list = el('div', 'dd-fm__list', null, { role: 'group', 'aria-label': f.label });
    wrap.appendChild(list);

    let fields = []; // [{id,label}]
    const selects = {};
    const sel = (id, labelText, emptyLabel) => {
        const row = el('div', 'dd-fm__row');
        const lab = el('label', 'dd-fm__label', labelText, { for: id });
        const select = el('select', 'ui-input dd-fm__select', null, { id });
        select.appendChild(el('option', null, emptyLabel, { value: '' }));
        row.append(lab, select);
        return { row, select };
    };

    const ensureOption = (select, id, label) => {
        if (id === '' || id === null || id === undefined) return;
        if (![...select.options].some((o) => o.value === String(id))) {
            select.appendChild(el('option', null, label || (f.provider === 'halo' ? 'Field #' + id : String(id)), { value: String(id) }));
        }
    };

    (cur.points || []).forEach((p) => {
        const id = nextId('dd-fm-p');
        const { row, select } = sel(id, p.label, none);
        const saved = (cur.map && cur.map[p.key]) || null;
        if (saved && saved.id) ensureOption(select, saved.id, saved.label);
        select.value = saved && saved.id ? String(saved.id) : '';
        selects[p.key] = select;
        list.appendChild(row);
    });

    const keySelects = {};
    if (f.keyFields && cur.key_fields) {
        const keys = el('div', 'dd-fm__keys');
        keys.appendChild(el('h3', 'dd-fm__subtitle', 'Key fields'));
        keys.appendChild(el('p', 'ui-help', 'HaloPSA shows up to three key fields per asset in its lists. Pick which DomainDash value fills each.'));
        [1, 2, 3].forEach((slot) => {
            const id = nextId('dd-fm-k');
            const { row, select } = sel(id, 'Key field ' + slot, 'Leave empty');
            (cur.points || []).forEach((p) => select.appendChild(el('option', null, p.label, { value: p.key })));
            select.value = (cur.key_fields && cur.key_fields[slot]) || '';
            keySelects[slot] = select;
            keys.appendChild(row);
        });
        wrap.appendChild(keys);
    }
    if (f.help) wrap.appendChild(el('p', 'ui-help', f.help));

    const fillOptions = () => {
        Object.keys(selects).forEach((point) => {
            const select = selects[point];
            const keep = select.value;
            const keepLabel = select.selectedOptions[0] ? select.selectedOptions[0].textContent : '';
            [...select.options].slice(1).forEach((o) => o.remove());
            fields.forEach((fl) => select.appendChild(el('option', null, fl.label, { value: fl.id })));
            if (keep) ensureOption(select, keep, keepLabel);
            select.value = keep;
        });
    };

    const typeId = () => { const t = ctx.typeValue(); return t && t.id ? String(t.id) : ''; };

    async function loadFields(refresh) {
        const id = typeId();
        fields = [];
        if (!id) { fillOptions(); say('Choose an asset type first, then its fields appear here.'); return false; }
        say('Loading fields...');
        try {
            const q = new URLSearchParams();
            q.set(f.typeParam || 'asset_type_id', id);
            if (refresh) q.set('refresh', '1');
            const data = await request('GET', f.source + '?' + q.toString());
            fields = (data.options || []).map((o) => ({ id: String(o.id), label: String(o.label) }));
            fillOptions();
            say(fields.length ? '' : 'This asset type has no fields that can be filled.');
            return true;
        } catch (e) {
            fillOptions();
            say(e.message, true);
            return false;
        }
    }

    async function suggest(refresh) {
        const id = typeId();
        if (!id) { say('Choose an asset type first.', true); return; }
        suggestBtn.disabled = true;
        say('Looking for matching fields...');
        try {
            const url = f.suggestUrl.replace('{id}', encodeURIComponent(id)) + '?entity=' + encodeURIComponent(cur.entity || '') + (refresh ? '&refresh=1' : '');
            const data = await request('GET', url);
            const byKey = {};
            (data.fields || []).forEach((fl) => { byKey[String(fl.id !== undefined ? fl.id : fl.name_key)] = fl.name; });
            let applied = 0;
            Object.keys(data.suggestions || {}).forEach((point) => {
                const v = data.suggestions[point];
                const select = selects[point];
                if (!select || v === null || v === undefined || v === '') return;
                ensureOption(select, String(v), byKey[String(v)]);
                select.value = String(v);
                applied++;
            });
            (data.keyfields || []).forEach((k) => {
                if (keySelects[k.slot] && k.suggested_point) keySelects[k.slot].value = k.suggested_point;
            });
            say(applied ? 'Suggested ' + applied + ' match' + (applied > 1 ? 'es' : '') + '. Review, then save.' : 'No matching fields found by name; choose them manually.');
        } catch (e) {
            say(e.message, true);
        } finally {
            suggestBtn.disabled = false;
        }
    }

    suggestBtn.addEventListener('click', () => suggest(true));

    // A different asset type means different fields: clear the old picks and suggest by name.
    ctx.onType(async () => {
        Object.keys(selects).forEach((p) => { selects[p].value = ''; });
        Object.keys(keySelects).forEach((p) => { keySelects[p].value = ''; });
        if (await loadFields(false)) await suggest(false);
    });

    loadFields(false);

    return {
        el: wrap,
        get value() {
            const ids = {}; const labels = {};
            Object.keys(selects).forEach((p) => {
                const o = selects[p].selectedOptions[0];
                ids[p] = selects[p].value;
                labels[p] = o && selects[p].value ? o.textContent : '';
            });
            let keys = null;
            if (Object.keys(keySelects).length) {
                keys = {};
                Object.keys(keySelects).forEach((slot) => { keys[slot] = keySelects[slot].value; });
            }
            return { ids, labels, keys };
        },
        setError: (m) => say(m, !!m),
    };
}

/* ------------------------------------------------------------------ */
/* The table                                                          */
/* ------------------------------------------------------------------ */

function init(container) {
    if (container.__ddMt) return;
    container.__ddMt = true;
    let cfg;
    try { cfg = JSON.parse(container.getAttribute('data-dd-mapping') || '{}'); } catch (e) { return; }
    const labels = Object.assign({ title: 'Mapping', add: 'Add', empty: 'No mappings yet.', entity: 'mapping' }, cfg.labels || {});
    const perPage = Math.max(5, Math.min(100, cfg.perPage || 10));
    const local = cfg.mode === 'local';
    const columns = cfg.columns || [];
    const fields = cfg.fields || [];
    let page = 1;
    let total = 0;
    let rows = local ? (cfg.rows || []).slice() : [];
    let query = '';
    let loading = false;

    container.classList.add('dd-mt');
    container.replaceChildren();

    // toolbar
    const toolbar = el('div', 'dd-mt__toolbar');
    const left = el('div', 'dd-mt__toolbar-left');
    const right = el('div', 'dd-mt__toolbar-right');
    let search = null;
    if (cfg.searchable) {
        const sw = el('div', 'dd-mt__search');
        sw.appendChild(icon('search', 15));
        search = el('input', 'ui-input dd-mt__search-input', null, { type: 'search', placeholder: 'Search', 'aria-label': 'Search ' + labels.title });
        sw.appendChild(search);
        left.appendChild(sw);
    }
    (cfg.actions || []).forEach((a) => {
        const b = el('button', 'ui-btn ui-btn--secondary ui-btn--sm', a.label, { type: 'button' });
        b.addEventListener('click', async () => {
            b.disabled = true;
            try {
                const r = await request(a.method || 'POST', a.url);
                say(r.message || a.success || 'Request sent.');
                if (a.reload) setTimeout(() => load(), a.delay || 3000);
            } catch (e) { say(e.message, true); } finally { b.disabled = false; }
        });
        right.appendChild(b);
    });
    let addBtn = null;
    if (!cfg.fixed) {
        addBtn = el('button', 'ui-btn ui-btn--primary ui-btn--sm', null, { type: 'button' });
        addBtn.appendChild(icon('plus', 14));
        addBtn.appendChild(document.createTextNode(' ' + labels.add));
        right.appendChild(addBtn);
    }
    toolbar.append(left, right);

    const live = el('p', 'dd-mt__live', '', { role: 'status', 'aria-live': 'polite' });
    const wrap = el('div', 'ui-table-wrap dd-mt__wrap');
    const table = el('table', 'ui-table ui-table--compact dd-mt__table');
    const thead = el('thead');
    const hr = el('tr');
    columns.forEach((c) => hr.appendChild(el('th', null, c.label, { scope: 'col' })));
    hr.appendChild(el('th', 'dd-mt__actions-col', 'Actions', { scope: 'col' }));
    thead.appendChild(hr);
    const tbody = el('tbody');
    table.append(thead, tbody);
    wrap.appendChild(table);
    const state = el('div', 'dd-mt__state');
    const foot = el('div', 'dd-mt__foot');
    const range = el('span', 'dd-mt__range', '');
    const pager = el('div', 'dd-mt__pager');
    const prev = iconButton('left', 'Previous page', 'dd-mt__icon--bordered');
    const next = iconButton('right', 'Next page', 'dd-mt__icon--bordered');
    pager.append(range, prev, next);
    foot.appendChild(pager);
    container.append(toolbar, live, wrap, state, foot);

    function say(msg, isError) {
        live.textContent = msg || '';
        live.classList.toggle('is-error', !!isError);
        if (msg && !isError) setTimeout(() => { if (live.textContent === msg) live.textContent = ''; }, 6000);
    }

    function paintRows(list) {
        tbody.replaceChildren();
        list.forEach((row) => {
            const tr = el('tr', 'dd-mt__row');
            columns.forEach((c) => {
                const txt = row.cells && row.cells[c.key] !== undefined ? row.cells[c.key] : '';
                if (c.badge) {
                    const td = el('td');
                    td.appendChild(el('span', 'ui-badge ui-badge--dot ui-badge--' + (c.badge[txt] || 'neutral'), txt));
                    tr.appendChild(td);
                    return;
                }
                tr.appendChild(el('td', c.muted ? 'dd-mt__muted' : null, txt));
            });
            const td = el('td', 'dd-mt__actions-cell');
            const box = el('div', 'dd-mt__row-actions');
            const edit = iconButton('pencil', 'Edit ' + labels.entity);
            edit.addEventListener('click', () => openForm(row));
            box.appendChild(edit);
            if (!cfg.fixed || cfg.deletable) {
                const del = iconButton('trash', 'Delete ' + labels.entity, 'dd-mt__icon--danger');
                del.addEventListener('click', () => confirmDelete(row));
                box.appendChild(del);
            }
            td.appendChild(box);
            tr.appendChild(td);
            tbody.appendChild(tr);
        });
    }

    function paint(list) {
        state.replaceChildren();
        container.removeAttribute('aria-busy');
        wrap.hidden = list.length === 0;
        if (!list.length) {
            const empty = el('div', 'dd-mt__empty');
            empty.appendChild(el('strong', null, query ? 'No matches' : labels.empty));
            if (!query && !cfg.fixed) empty.appendChild(el('span', null, ' Use "' + labels.add + '" to create the first one.'));
            state.appendChild(empty);
        }
        paintRows(list);
        const from = total === 0 ? 0 : (page - 1) * perPage + 1;
        const to = Math.min(total, page * perPage);
        range.textContent = total === 0 ? '0 of 0' : from + '-' + to + ' of ' + total;
        prev.disabled = page <= 1;
        next.disabled = page * perPage >= total;
        foot.hidden = total === 0;
    }

    function paintError(msg) {
        state.replaceChildren();
        container.removeAttribute('aria-busy');
        wrap.hidden = true;
        const box = el('div', 'dd-mt__error', null, { role: 'alert' });
        box.appendChild(el('span', null, msg));
        const retry = el('button', 'ui-btn ui-btn--secondary ui-btn--sm', 'Retry', { type: 'button' });
        retry.addEventListener('click', () => load());
        box.appendChild(retry);
        state.appendChild(box);
        foot.hidden = true;
    }

    async function load() {
        if (local) {
            const q = query.toLowerCase();
            const filtered = q ? rows.filter((r) => Object.values(r.cells || {}).join(' ').toLowerCase().includes(q)) : rows;
            total = filtered.length;
            if ((page - 1) * perPage >= total) page = Math.max(1, Math.ceil(total / perPage));
            paint(filtered.slice((page - 1) * perPage, page * perPage));
            return;
        }
        if (loading) return;
        loading = true;
        container.setAttribute('aria-busy', 'true');
        state.replaceChildren(el('div', 'dd-mt__loading', 'Loading...'));
        try {
            const data = await request('GET', cfg.url + '?page=' + page + '&per_page=' + perPage + (query ? '&q=' + encodeURIComponent(query) : ''));
            total = data.total || 0;
            rows = data.rows || [];
            if (!rows.length && page > 1) { page = Math.max(1, Math.ceil(total / perPage)); loading = false; return load(); }
            paint(rows);
        } catch (e) {
            paintError(e.message);
        } finally {
            loading = false;
        }
    }

    function changed(row) {
        container.dispatchEvent(new CustomEvent('dd-mapping:change', { bubbles: true, detail: { id: cfg.id, rows: rows.slice(), row } }));
    }

    /* ----- add / edit dialog ----- */
    function fieldOptionsLoader(f, getValue) {
        return async (q, refresh) => {
            const inline = (f.options || []).map((x) => ({ id: String(x.id), label: String(x.label) }));
            const stat = (f.staticOptions || []).map((x) => ({ id: String(x.id), label: String(x.label) }));
            const filt = (arr) => (q ? arr.filter((x) => x.label.toLowerCase().includes(q.toLowerCase())) : arr);
            if (!f.source) return { options: filt(inline) };
            const params = new URLSearchParams();
            if (q) params.set('q', q);
            if (refresh) params.set('refresh', '1');
            Object.keys(f.paramsFrom || {}).forEach((p) => {
                const src = document.querySelector(f.paramsFrom[p]);
                if (src && src.value) params.set(p, src.value);
            });
            const cur = getValue();
            if (cur && cur.id && !q) params.set('include', cur.id);
            const data = await request('GET', f.source + (params.toString() ? '?' + params.toString() : ''));
            return { options: filt(stat).concat(data.options || []), truncated: data.truncated };
        };
    }

    function openForm(row) {
        const editing = !!row;
        const body = el('form', 'dd-mtm__form', null, { novalidate: 'novalidate' });
        const formErr = el('div', 'ui-alert ui-alert--danger dd-mtm__err', null, { role: 'alert' });
        formErr.hidden = true;
        body.appendChild(formErr);
        const widgets = {};
        const listeners = {};
        fields.forEach((f) => {
            const cur = editing && row.values ? row.values[f.key] : null;
            const locked = editing && (f.locked || (cfg.fixed && f === fields[0]));
            if (locked) {
                const fw = el('div', 'ui-field');
                fw.appendChild(el('span', 'ui-label', f.label));
                fw.appendChild(el('div', 'dd-mtm__readonly', cur && cur.label !== undefined ? cur.label : String(cur ?? '')));
                body.appendChild(fw);
                widgets[f.key] = { locked: true, get: () => (cur && cur.id !== undefined ? cur : null) };
                return;
            }
            if (f.type === 'combo') {
                const w = createCombo({
                    label: f.label, required: f.required, placeholder: f.placeholder, help: f.help,
                    value: cur && cur.id !== undefined ? cur : null,
                    allowCustom: !!f.allowCustom,
                    refreshable: !!f.refresh,
                    emptyHint: f.emptyHint,
                    load: fieldOptionsLoader(f, () => widgets[f.key] && widgets[f.key].value),
                    onChange: (v) => (listeners[f.key] || []).forEach((fn) => fn(v)),
                });
                widgets[f.key] = w;
                body.appendChild(w.el);
            } else if (f.type === 'checkbox') {
                const fw = el('div', 'ui-field');
                const id = nextId('dd-mtm-f');
                const lab = el('label', 'dd-set-check', null, { for: id });
                const box = el('input', null, null, { id, type: 'checkbox' });
                box.checked = cur === '1' || cur === 1 || cur === true;
                lab.appendChild(box);
                const txt = el('span', null, f.label);
                if (f.help) txt.appendChild(el('small', 'dd-set-help', f.help));
                lab.appendChild(txt);
                fw.appendChild(lab);
                widgets[f.key] = { get value() { return box.checked ? '1' : '0'; }, get checked() { return box.checked; }, el: fw, input: box, setError: () => {} };
                body.appendChild(fw);
            } else if (f.type === 'fieldmap') {
                const w = createFieldMap(f, cur, {
                    typeValue: () => (widgets[f.typeField] && widgets[f.typeField].value) || null,
                    onType: (fn) => { (listeners[f.typeField] = listeners[f.typeField] || []).push(fn); },
                });
                widgets[f.key] = w;
                body.appendChild(w.el);
            } else {
                const fw = el('div', 'ui-field');
                const id = nextId('dd-mtm-f');
                const lab = el('label', 'ui-label', f.label, { for: id });
                if (f.required) lab.appendChild(el('span', 'ui-required', '*', { 'aria-hidden': 'true' }));
                fw.appendChild(lab);
                let ctl;
                if (f.type === 'select') {
                    ctl = el('select', 'ui-input', null, { id });
                    (f.options || []).forEach((o) => ctl.appendChild(el('option', null, o.label, { value: String(o.id) })));
                    ctl.value = String(cur ?? (f.options && f.options[0] ? f.options[0].id : ''));
                } else {
                    ctl = el('input', 'ui-input', null, { id, type: 'text', placeholder: f.placeholder || '' });
                    ctl.value = cur === null || cur === undefined ? '' : String(cur);
                }
                fw.appendChild(ctl);
                if (f.help) { const h = el('p', 'ui-help', f.help, { id: id + '-h' }); ctl.setAttribute('aria-describedby', id + '-h'); fw.appendChild(h); }
                const er = el('p', 'ui-error', '', { role: 'alert' });
                er.hidden = true;
                fw.appendChild(er);
                widgets[f.key] = {
                    get value() { return ctl.value; },
                    el: fw, input: ctl,
                    setError: (m) => { er.textContent = m || ''; er.hidden = !m; fw.classList.toggle('has-error', !!m); },
                };
                body.appendChild(fw);
            }
        });

        const foot = el('div', 'dd-mtm__actions');
        const cancel = el('button', 'ui-btn ui-btn--ghost', 'Cancel', { type: 'button' });
        const save = el('button', 'ui-btn ui-btn--primary', 'Save', { type: 'submit' });
        foot.append(cancel, save);
        body.appendChild(foot);

        const dlg = openDialog({ title: (editing ? 'Edit ' : 'Add ') + labels.title.toLowerCase(), body, size: cfg.dialogSize });
        cancel.addEventListener('click', dlg.close);

        body.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            formErr.hidden = true;
            // Collect values + client-side required check.
            const payload = {};
            const localValues = {};
            const localCells = {};
            let ok = true;
            fields.forEach((f) => {
                const w = widgets[f.key];
                if (w.setError) w.setError('');
                if (w.locked) {
                    const v = w.get();
                    if (v) { payload[f.key] = v.id; payload[f.key + '_label'] = v.label; localValues[f.key] = v; localCells[f.key] = v.label; }
                    return;
                }
                if (f.type === 'fieldmap') {
                    const v = w.value;
                    payload[f.key] = v.ids;
                    payload[f.key + '_label'] = v.labels;
                    if (v.keys) payload.key_fields = v.keys;
                    return;
                }
                if (f.type === 'checkbox') {
                    payload[f.key] = w.checked;
                    return;
                }
                if (f.type === 'combo') {
                    const v = w.value;
                    if (f.required && !v) { w.setError(f.label + ' is required.'); ok = false; return; }
                    if (v) { payload[f.key] = v.id; payload[f.key + '_label'] = v.label; }
                    else { payload[f.key] = ''; payload[f.key + '_label'] = ''; }
                    localValues[f.key] = v || { id: '', label: '' };
                    localCells[f.key] = v ? v.label : ((cfg.emptyCells && cfg.emptyCells[f.key]) || '');
                } else {
                    const v = String(w.value || '').trim();
                    if (f.required && !v) { w.setError(f.label + ' is required.'); ok = false; return; }
                    payload[f.key] = v;
                    localValues[f.key] = v;
                    localCells[f.key] = f.type === 'select' ? ((f.options || []).find((o) => String(o.id) === v) || { label: v }).label : v;
                }
            });
            if (!ok) { (dlg.dialog.querySelector('.has-error input, .has-error select') || {}).focus?.(); return; }

            save.disabled = true;
            const prevLabel = save.textContent;
            save.textContent = 'Saving...';
            try {
                if (local) {
                    const newRow = { id: editing ? row.id : (localValues[fields[0].key].id || String(Date.now())), cells: localCells, values: localValues };
                    const idx = rows.findIndex((r) => r.id === newRow.id);
                    if (idx >= 0) rows[idx] = newRow; else rows.push(newRow);
                    changed(newRow);
                } else {
                    await request(editing ? 'PUT' : 'POST', editing ? cfg.url + '/' + encodeURIComponent(row.id) : cfg.url, payload);
                }
                dlg.close();
                say(labels.title + ' saved.');
                await load();
            } catch (e) {
                save.disabled = false;
                save.textContent = prevLabel;
                const errs = (e.data && e.data.errors) || {};
                let placed = false;
                Object.keys(errs).forEach((k) => {
                    if (widgets[k] && widgets[k].setError) { widgets[k].setError(errs[k][0]); placed = true; }
                });
                if (!placed) { formErr.textContent = e.message; formErr.hidden = false; }
            }
        });
    }

    function confirmDelete(row) {
        const body = el('div', 'dd-mtm__confirm');
        const first = Object.values(row.cells || {}).filter(Boolean).slice(0, 2).join('  >  ');
        body.appendChild(el('p', null, 'Delete this ' + labels.entity + '?'));
        if (first) body.appendChild(el('p', 'dd-mtm__readonly', first));
        const foot = el('div', 'dd-mtm__actions');
        const cancel = el('button', 'ui-btn ui-btn--ghost', 'Cancel', { type: 'button' });
        const del = el('button', 'ui-btn ui-btn--danger', 'Delete', { type: 'button' });
        foot.append(cancel, del);
        body.appendChild(foot);
        const dlg = openDialog({ title: 'Delete ' + labels.entity, size: 'sm', body });
        cancel.addEventListener('click', dlg.close);
        del.addEventListener('click', async () => {
            del.disabled = true;
            del.textContent = 'Deleting...';
            try {
                if (local) {
                    rows = rows.filter((r) => r.id !== row.id);
                    changed(null);
                } else {
                    await request('DELETE', cfg.url + '/' + encodeURIComponent(row.id));
                }
                dlg.close();
                say(labels.title + ' deleted.');
                await load();
            } catch (e) {
                del.disabled = false;
                del.textContent = 'Delete';
                body.insertBefore(el('p', 'ui-error', e.message, { role: 'alert' }), foot);
            }
        });
    }

    /* ----- wiring ----- */
    if (addBtn) addBtn.addEventListener('click', () => openForm(null));
    prev.addEventListener('click', () => { if (page > 1) { page--; load(); } });
    next.addEventListener('click', () => { if (page * perPage < total) { page++; load(); } });
    if (search) {
        let t = null;
        search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { query = search.value.trim(); page = 1; load(); }, 250); });
    }

    container.ddMapping = {
        reload: () => load(),
        getRows: () => rows.slice(),
        setRows: (r) => { rows = (r || []).slice(); load(); },
    };
    load();
}

function boot() {
    const nodes = document.querySelectorAll('[data-dd-mapping]');
    if (!nodes.length) return;
    // Initialise when first visible: tables inside hidden tabs or closed dialogs load nothing until opened.
    if (!('IntersectionObserver' in window)) { nodes.forEach(init); return; }
    const io = new IntersectionObserver((entries) => {
        entries.forEach((en) => {
            if (en.isIntersecting) { io.unobserve(en.target); init(en.target); }
        });
    }, { rootMargin: '120px' });
    nodes.forEach((n) => io.observe(n));
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();

window.DDMapping = { init, createCombo, createFieldMap, request };
