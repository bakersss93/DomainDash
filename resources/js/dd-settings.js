/*
 * Settings pages glue (resources/views/admin/settings/*):
 *  - tabs:    [data-dd-tabs] with role=tab buttons and [data-tab-panel] panels; deep links via #tab or ?tab=
 *  - secrets: input[data-dd-secret] keeps the stored value ("********") unless the person types a new one
 *  - pickers: [data-dd-picker] searchable select bound to hidden inputs (asset type, contract type ...)
 *  - binds:   a local-mode mapping table writes its rows into the form inputs it is bound to
 *  - suggest / preview for the HaloPSA + IT Glue asset mapping
 * All server text is written with textContent.
 */

const SENTINEL = '********';

function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
}

/* ---------------- tabs ---------------- */
function initTabs(root) {
    const tabs = [...root.querySelectorAll('[role="tab"][data-tab]')];
    const panels = [...root.querySelectorAll('[data-tab-panel]')];
    if (!tabs.length) return;
    const names = tabs.map((t) => t.getAttribute('data-tab'));

    const show = (name, focus, push) => {
        if (!names.includes(name)) name = names[0];
        tabs.forEach((t) => {
            const on = t.getAttribute('data-tab') === name;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) t.focus();
        });
        panels.forEach((p) => { p.hidden = p.getAttribute('data-tab-panel') !== name; });
        if (push) {
            try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])tab=[^&]*&?/, '$1').replace(/[?&]$/, '') + '#' + name); } catch (e) { /* ignore */ }
        }
        root.dispatchEvent(new CustomEvent('dd:tab-shown', { bubbles: true, detail: { tab: name } }));
    };

    tabs.forEach((t, i) => {
        t.addEventListener('click', () => show(t.getAttribute('data-tab'), false, true));
        t.addEventListener('keydown', (e) => {
            let j = null;
            if (e.key === 'ArrowRight') j = (i + 1) % tabs.length;
            else if (e.key === 'ArrowLeft') j = (i - 1 + tabs.length) % tabs.length;
            else if (e.key === 'Home') j = 0;
            else if (e.key === 'End') j = tabs.length - 1;
            if (j !== null) { e.preventDefault(); show(names[j], true, true); }
        });
    });

    const fromUrl = () => {
        const h = location.hash.replace('#', '');
        if (names.includes(h)) return h;
        const q = new URLSearchParams(location.search).get('tab');
        return names.includes(q) ? q : root.getAttribute('data-initial') || names[0];
    };
    window.addEventListener('hashchange', () => show(fromUrl(), false, false));
    show(fromUrl(), false, false);

    // In-page links to another tab: <a data-goto-tab="billing">
    root.addEventListener('click', (e) => {
        const a = e.target.closest('[data-goto-tab]');
        if (a) { e.preventDefault(); show(a.getAttribute('data-goto-tab'), false, true); }
    });
}

/* ---------------- secrets ---------------- */
function initSecret(input) {
    const hadSecret = input.value === SENTINEL;
    input.addEventListener('focus', () => { if (input.value === SENTINEL) input.value = ''; });
    input.addEventListener('blur', () => { if (hadSecret && input.value === '') input.value = SENTINEL; });
}

/* ---------------- pickers ---------------- */
function initPicker(host) {
    if (!window.DDMapping || host.__ddPicker) return;
    host.__ddPicker = true;
    let cfg;
    try { cfg = JSON.parse(host.getAttribute('data-dd-picker')); } catch (e) { return; }
    const idInput = cfg.idInput ? document.querySelector(cfg.idInput) : null;
    const nameInput = cfg.nameInput ? document.querySelector(cfg.nameInput) : null;
    const curId = idInput ? String(idInput.value || '') : '';
    const curName = nameInput ? String(nameInput.value || '') : String(cfg.currentLabel || '');

    const combo = window.DDMapping.createCombo({
        label: cfg.label, required: !!cfg.required, placeholder: cfg.placeholder, help: cfg.help,
        refreshable: cfg.refresh !== false, emptyHint: cfg.emptyHint,
        value: curId && curId !== '0' ? { id: curId, label: curName || (cfg.fallbackLabel || 'Saved value') + ' (#' + curId + ')' } : null,
        load: async (q, refresh) => {
            const p = new URLSearchParams();
            if (q) p.set('q', q);
            if (refresh) p.set('refresh', '1');
            if (idInput && idInput.value && !q) p.set('include', idInput.value);
            const data = await window.DDMapping.request('GET', cfg.source + (p.toString() ? '?' + p.toString() : ''));
            return { options: data.options || [], truncated: data.truncated };
        },
        onChange: (v) => {
            if (idInput) { idInput.value = v ? v.id : ''; idInput.dispatchEvent(new Event('input', { bubbles: true })); idInput.dispatchEvent(new Event('change', { bubbles: true })); }
            if (nameInput) nameInput.value = v ? v.label : '';
            host.dispatchEvent(new CustomEvent('dd-picker:change', { bubbles: true, detail: { value: v } }));
        },
    });
    host.appendChild(combo.el);
    // The manual-ID input (Advanced) can change the id too: keep the picker label honest.
    if (idInput && cfg.syncFromInput) {
        idInput.addEventListener('input', () => {
            if (document.activeElement === combo.input) return;
            const v = idInput.value.trim();
            combo.set(v && v !== '0' ? { id: v, label: 'ID ' + v } : null);
            if (nameInput) nameInput.value = '';
        });
    }
}

/* ---------------- local mapping table <-> form inputs ---------------- */
function initBind(container) {
    const prefix = container.getAttribute('data-dd-bind-prefix');
    if (!prefix) return;
    const namePrefix = container.getAttribute('data-dd-bind-name-prefix');
    const fieldKey = container.getAttribute('data-dd-bind-field') || 'value';
    const defaults = JSON.parse(container.getAttribute('data-dd-bind-defaults') || '{}');
    const emptyLabel = container.getAttribute('data-dd-bind-empty') || '';

    container.addEventListener('dd-mapping:change', (e) => {
        // Only the row that changed: untouched rows must not have their stored name overwritten with a display label.
        (e.detail.row ? [e.detail.row] : []).forEach((row) => {
            const input = document.getElementById(prefix + row.id);
            if (!input) return;
            const v = row.values && row.values[fieldKey];
            const id = v && v.id !== undefined ? String(v.id) : '';
            input.value = id;
            if (namePrefix) {
                const n = document.getElementById(namePrefix + row.id);
                if (n) n.value = id && id !== '0' ? v.label : '';
            }
        });
    });

    // Manual edits of the (collapsed) Advanced inputs flow back into the table.
    const sync = () => {
        const api = container.ddMapping;
        if (!api) return;
        const rows = api.getRows().map((row) => {
            const input = document.getElementById(prefix + row.id);
            const id = input ? input.value.trim() : '';
            let label;
            if (id === '0') label = "Don't sync";
            else if (id === '') label = defaults[row.id] !== undefined ? emptyLabel.replace('%d', defaults[row.id]) : emptyLabel;
            else if (container.getAttribute('data-dd-bind-keylabel') === '1') label = id;
            else label = namePrefix && document.getElementById(namePrefix + row.id) && document.getElementById(namePrefix + row.id).value
                ? document.getElementById(namePrefix + row.id).value : 'Field #' + id;
            const cells = Object.assign({}, row.cells);
            const values = Object.assign({}, row.values);
            const key = container.getAttribute('data-dd-bind-column') || fieldKey;
            cells[key] = label;
            values[fieldKey] = { id, label };
            return Object.assign({}, row, { cells, values });
        });
        api.setRows(rows);
    };
    document.querySelectorAll('input[id^="' + prefix + '"]').forEach((i) => {
        i.addEventListener('input', () => { if (container.ddMapping) setTimeout(sync, 0); });
    });
}

/* ---------------- suggest from the remote type (Halo + IT Glue) ---------------- */
function initSuggest(btn) {
    const kind = btn.getAttribute('data-dd-suggest');
    const status = document.getElementById(btn.getAttribute('data-status') || '');
    const say = (m, bad) => { if (status) { status.textContent = m; status.classList.toggle('is-error', !!bad); } };

    btn.addEventListener('click', async () => {
        const typeInput = document.querySelector(btn.getAttribute('data-type-input'));
        const id = typeInput ? typeInput.value.trim() : '';
        if (!id) { say('Choose a type first.', true); return; }
        const url = kind === 'halo'
            ? '/admin/settings/halo/asset-types/' + encodeURIComponent(id) + '/fields?refresh=1'
            : '/admin/settings/itglue/flexible-asset-types/' + encodeURIComponent(id) + '/fields?refresh=1';
        btn.disabled = true;
        say('Loading fields...');
        try {
            const data = await window.DDMapping.request('GET', url);
            const prefix = btn.getAttribute('data-prefix');
            const namePrefix = btn.getAttribute('data-name-prefix');
            const table = document.querySelector(btn.getAttribute('data-table'));
            let applied = 0;
            const byId = {};
            (data.fields || []).forEach((f) => { byId[String(f.id !== undefined ? f.id : f.name_key)] = f; });
            Object.keys(data.suggestions || {}).forEach((point) => {
                const sug = data.suggestions[point];
                const input = document.getElementById(prefix + point);
                if (!input || sug === null || sug === undefined || sug === '') return;
                input.value = String(sug);
                if (namePrefix) {
                    const n = document.getElementById(namePrefix + point);
                    const f = byId[String(sug)];
                    if (n && f) n.value = f.name;
                }
                applied++;
            });
            if (kind === 'halo') {
                (data.keyfields || []).forEach((k) => {
                    const sel = document.getElementById('halo_key_field_' + k.slot);
                    if (sel && k.suggested_point) { sel.value = k.suggested_point; }
                });
            }
            // Re-read the inputs into the table.
            const first = document.querySelector('input[id^="' + prefix + '"]');
            if (first) first.dispatchEvent(new Event('input', { bubbles: true }));
            say(applied ? 'Suggested ' + applied + ' mapping' + (applied > 1 ? 's' : '') + '. Review, then save.' : 'No matching fields found in that type.');
            if (table && table.ddMapping) table.ddMapping.reload();
        } catch (e) {
            say(e.message, true);
        } finally {
            btn.disabled = false;
        }
    });
}

/* ---------------- Halo dry-run preview ---------------- */
function initHaloPreview(box) {
    const input = box.querySelector('[data-preview-domain]');
    const btn = box.querySelector('[data-preview-run]');
    const out = box.querySelector('[data-preview-out]');
    const list = box.querySelector('datalist');
    let loaded = false;

    input.addEventListener('focus', async () => {
        if (loaded) return;
        loaded = true;
        try {
            const r = await window.DDMapping.request('GET', '/admin/sync/halo/domains');
            (r.domains || []).slice(0, 500).forEach((d) => { const o = document.createElement('option'); o.value = d.name; list.appendChild(o); });
        } catch (e) { /* free typing still works */ }
    });

    const cell = (tr, text, cls) => tr.appendChild(el('td', cls || '', text));

    btn.addEventListener('click', async () => {
        const name = input.value.trim();
        out.replaceChildren();
        if (!name) { out.textContent = 'Enter a domain name first.'; return; }
        // The Assets tab edits mappings in a dialog and saves them itself, so the preview always uses the saved Domains mapping.
        const typeInput = document.getElementById('halo_asset_type_id');
        let halo = null;
        if (typeInput) {
            const ids = {}; const names = {};
            ['domain', 'expiry', 'nameservers', 'whois'].forEach((p) => {
                ids[p] = (document.getElementById('halo_field_' + p) || {}).value || '';
                names[p] = (document.getElementById('halo_field_name_' + p) || {}).value || '';
            });
            const keys = {};
            [1, 2, 3].forEach((n) => { keys[n] = (document.getElementById('halo_key_field_' + n) || {}).value || ''; });
            halo = {
                asset_type_id: typeInput.value || null,
                asset_type_name: (document.getElementById('halo_asset_type_name') || {}).value || '',
                asset_field_ids: ids, asset_field_names: names, asset_key_fields: keys,
            };
        }
        out.textContent = 'Building preview...';
        btn.disabled = true;
        try {
            const d = await window.DDMapping.request('POST', '/admin/settings/halo/preview-mapping', {
                domain: name,
                halo: halo || undefined,
            });
            out.replaceChildren();
            out.appendChild(el('p', 'dd-set-help', 'Preview for ' + d.domain + (d.client ? ' (' + d.client + ')' : '') + ' to asset type ' + d.asset_type_name + (d.asset_type_id ? ' #' + d.asset_type_id : ' (looked up by name at sync time)') + '. Nothing was sent to HaloPSA.'));
            const wrap = el('div', 'ui-table-wrap');
            const table = el('table', 'ui-table ui-table--compact');
            const head = el('tr');
            ['DomainDash value', 'Halo field', 'Value sent'].forEach((h) => head.appendChild(el('th', '', h)));
            table.appendChild(el('thead')).appendChild(head);
            const tb = el('tbody');
            (d.rows || []).forEach((row) => {
                const tr = el('tr', row.synced ? '' : 'dd-mt__muted');
                cell(tr, row.label);
                cell(tr, row.synced ? ((row.field_name ? row.field_name + ' ' : '') + '#' + row.field_id) : "Don't sync");
                cell(tr, row.synced ? row.value : '', 'dd-set-pre');
                tb.appendChild(tr);
            });
            (d.key_fields || []).forEach((k) => {
                const tr = el('tr');
                cell(tr, 'Key field'); cell(tr, k.property); cell(tr, k.value, 'dd-set-pre');
                tb.appendChild(tr);
            });
            table.appendChild(tb);
            wrap.appendChild(table);
            out.appendChild(wrap);
            (d.warnings || []).forEach((w) => out.appendChild(el('p', 'ui-error', w)));
        } catch (e) {
            out.replaceChildren(el('p', 'ui-error', 'Preview failed: ' + e.message));
        } finally {
            btn.disabled = false;
        }
    });
}

function boot() {
    document.querySelectorAll('[data-dd-tabs]').forEach(initTabs);
    document.querySelectorAll('input[data-dd-secret]').forEach(initSecret);
    document.querySelectorAll('[data-dd-picker]').forEach(initPicker);
    document.querySelectorAll('[data-dd-bind-prefix]').forEach(initBind);
    document.querySelectorAll('[data-dd-suggest]').forEach(initSuggest);
    document.querySelectorAll('[data-dd-halo-preview]').forEach(initHaloPreview);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
