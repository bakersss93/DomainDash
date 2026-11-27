<script>

        // IP2WHOIS Modal
        function openIp2whoisModal() {
            document.getElementById('ip2whoisSyncModal').style.display = 'flex';
            loadIp2whoisDomains();
        }

        function closeIp2whoisModal() {
            document.getElementById('ip2whoisSyncModal').style.display = 'none';
        }

        // Add hover effect to section headers

        // Halo Sync Modal Functions
        function openHaloSyncModal() {
            document.getElementById('haloSyncModal').style.display = 'flex';
        }

        function closeHaloSyncModal() {
            document.getElementById('haloSyncModal').style.display = 'none';
        }



        function ddSyncBarHtml(sync, url) {
            const when = sync && sync.last_synced_human ? 'Last synced ' + sync.last_synced_human : 'Never synced';
            const busy = sync && sync.active ? ' (refresh running: ' + sync.active.status + ')' : '';
            return `<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;font-size:13px;color:var(--text-muted);">
                <span>${when}${busy}</span>
                <button type="button" onclick="ddRefreshMirror('${url}', this)" style="padding:4px 12px;background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:6px;color:var(--text);cursor:pointer;">Refresh now</button>
            </div><div data-dd-runs></div>`;
        }

        function ddEsc(v) {
            return String(v ?? '').replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        async function ddRefreshMirror(url, btn) {
            const sib = btn.parentElement.nextElementSibling;
            const mount = (sib && sib.hasAttribute('data-dd-runs')) ? sib : btn.closest('.dd-tabs__panel').querySelector('[data-dd-runs]');
            btn.disabled = true;
            try {
                const r = await DDSync.request(url, 'POST');
                const runs = r.data.runs || (r.data.run ? [r.data.run] : []);
                if (!r.ok || !runs.length) {
                    mount.textContent = 'Refresh not started: ' + (r.data.error || r.data.message || ('HTTP ' + r.status));
                    return;
                }
                DDSync.updateWorker(r.data.worker);
                DDSync.track(runs, mount, { onDone: () => { btn.disabled = false; } });
            } catch (e) {
                mount.textContent = 'Refresh failed: ' + e.message;
            } finally {
                setTimeout(() => { btn.disabled = false; }, 3000);
            }
        }

        // ---------------------------------------------------------------
        // Shared list helpers (DDList lives in resources/js/dd-list.js)
        // ---------------------------------------------------------------
        const DD_SPINNER_HTML = (label) => '<div style="display:flex;align-items:center;justify-content:center;padding:40px;"><div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--text);"><span style="width:18px;height:18px;border:2px solid rgba(148,163,184,0.45);border-top-color:var(--text);border-radius:999px;display:inline-block;animation:halo-spin 0.7s linear infinite;"></span><span>' + ddEsc(label) + '</span></div></div>';

        function ddTextEl(tag, text, css) {
            const n = document.createElement(tag);
            n.textContent = text === null || text === undefined ? '' : String(text);
            if (css) n.style.cssText = css;
            return n;
        }

        function ddNameCell(row, nameKey) {
            const wrap = document.createElement('div');
            wrap.appendChild(ddTextEl('div', row[nameKey || 'name'] || ''));
            if (row.sync_error) {
                const err = ddTextEl('div', 'Last sync failed: ' + row.sync_error);
                err.className = 'dd-list__err';
                wrap.appendChild(err);
            }
            return wrap;
        }

        function ddSyncSorts() { return [
            { value: 'status', label: 'Status (failed / not synced first)', compare: DDList.std.status((r) => (r.sync_error ? 0 : (r.synced_at ? 2 : 1))) },
            { value: 'name-asc', label: 'Name A–Z', compare: DDList.std.name('name', 1) },
            { value: 'name-desc', label: 'Name Z–A', compare: DDList.std.name('name', -1) },
            { value: 'synced-new', label: 'Last synced (newest)', compare: DDList.std.date('synced_at', -1) },
            { value: 'synced-old', label: 'Last synced (oldest)', compare: DDList.std.date('synced_at', 1) },
            { value: 'client', label: 'Client', compare: (a, b) => DDList.collator.compare(a.client || '', b.client || '') },
        ]; }
        function ddSyncFilters() { return [
            { value: 'all', label: 'All', match: () => true },
            { value: 'unsynced', label: 'Not synced', match: (r, st) => st.key === 'unsynced' || st.key === 'queued' },
            { value: 'failed', label: 'Failed', match: (r, st) => st.key === 'failed' },
            { value: 'synced', label: 'Synced', match: (r, st) => st.key === 'synced' || st.key === 'unchanged' },
        ]; }

        function ddCreateDomainList(mount, rows, o) {
            return DDList.create({
                mount: mount,
                rows: rows,
                idKey: 'id',
                storageKey: o.storageKey,
                grid: o.grid,
                columns: o.columns,
                searchKeys: ['name', 'client'],
                sorts: ddSyncSorts(),
                defaultSort: 'status',
                filters: ddSyncFilters(),
                statusOf: DDList.syncStatus(o.existsKey),
                emptyText: o.emptyText || 'No domains to show.',
            });
        }

        function ddMapSorts() { return [
            { value: 'status', label: 'Status (unmapped first)', compare: (a, b, statusOf) => (statusOf(a).rank - statusOf(b).rank) },
            { value: 'name-asc', label: 'Name A–Z', compare: DDList.std.name('name', 1) },
            { value: 'name-desc', label: 'Name Z–A', compare: DDList.std.name('name', -1) },
        ]; }
        function ddMapFilters() { return [
            { value: 'all', label: 'All', match: () => true },
            { value: 'unmapped', label: 'Unmapped', match: (r, st) => st.key === 'unsynced' },
            { value: 'mapped', label: 'Mapped', match: (r, st) => st.key === 'synced' },
        ]; }
        const ddMapStatus = (row) => (row.mapped_id
            ? { key: 'synced', label: 'Mapped', rank: 1, title: 'Linked to a record in the other system' }
            : { key: 'unsynced', label: 'Unmapped', rank: 0, variant: 'warning', title: 'Not linked yet' });

        // Refresh a domain list in place (no spinner, selection and sort kept) after a run finished.
        async function ddReloadDomainList(url, listVar, rowsKey) {
            try {
                const response = await fetch(url);
                const data = await response.json();
                const list = window[listVar];
                if (list && Array.isArray(data[rowsKey])) {
                    list.clearItemStates();
                    list.setRows(data[rowsKey]);
                }
            } catch (e) { /* the next manual refresh will show current data */ }
            if (window[listVar]) window[listVar].setBusy(false);
        }




        async function loadHaloDomains() {
            const listEl = document.getElementById('haloDomainList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading domains from DomainDash...');

            try {
                const response = await fetch('/admin/sync/halo/domains');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderHaloDomainList(data.domains, data.sync);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load domains: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderHaloDomainList(domains, sync) {
            const listEl = document.getElementById('haloDomainList');
            listEl.innerHTML = ddSyncBarHtml(sync, '/admin/sync/halo/refresh');
            const mount = document.createElement('div');
            listEl.appendChild(mount);

            window._haloDomainList = ddCreateDomainList(mount, domains, {
                storageKey: 'halo-domains',
                existsKey: 'exists_in_halo',
                grid: '1.4fr 1fr 100px 150px',
                columns: [
                    { label: 'Domain Name', render: (r) => ddNameCell(r) },
                    { label: 'Client', render: (r) => ddTextEl('div', r.client || 'No Client', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Expiry', render: (r) => ddTextEl('div', r.expiry ? String(r.expiry).slice(0, 10) : 'N/A', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                ],
            });
        }

        // Shared by Halo and IT Glue: queue, then show Queued -> Syncing -> Synced/Failed per row plus the overall bar.
        async function ddQueueDomainRun(opts) {
            const list = window[opts.listVar];
            const panel = document.getElementById(opts.panelId);
            const ids = list ? list.selectedIds() : [];
            if (!ids.length) {
                alert(opts.emptyMessage);
                return;
            }

            try {
                showHaloSyncProgress('Queueing sync…');
                const r = await opts.request(ids);
                const data = r.data;
                hideHaloSyncProgress();

                if (!r.ok || !data.success) {
                    panel.replaceChildren();
                    const box = document.createElement('div');
                    box.className = 'dd-run';
                    box.setAttribute('role', 'alert');
                    const err = document.createElement('div');
                    err.className = 'dd-run__error';
                    err.textContent = 'Sync not started: ' + (data.error || data.message || ('HTTP ' + r.status));
                    box.appendChild(err);
                    panel.appendChild(box);
                    return;
                }

                DDSync.updateWorker(data.worker);
                list.setBusy(true);
                const binder = DDList.bindRun(list, {
                    ids: ids,
                    itemsUrl: (runId) => '/admin/sync/runs/' + encodeURIComponent(runId) + '/items',
                });
                binder.start();
                DDSync.track([data.run], panel, {
                    label: opts.label(ids.length),
                    runNow: opts.runNow ? () => opts.runNow(ids) : undefined,
                    onUpdate: (run) => binder.onUpdate(run),
                    onDone: async (run) => {
                        await binder.onDone(run);
                        // Let people see the final per-row result, then re-sort from fresh server data.
                        setTimeout(() => ddReloadDomainList(opts.reloadUrl, opts.listVar, opts.rowsKey), 1500);
                    },
                });
            } catch (error) {
                hideHaloSyncProgress();
                panel.textContent = 'Sync failed: ' + error.message;
                if (list) list.setBusy(false);
            }
        }

        function syncHaloDomains() {
            return ddQueueDomainRun({
                listVar: '_haloDomainList',
                panelId: 'haloDomainRunPanel',
                emptyMessage: 'Please select at least one domain to sync',
                request: (ids) => DDSync.request('/admin/sync/halo/domains/sync', 'POST', { domain_ids: ids }),
                runNow: (ids) => DDSync.request('/admin/sync/halo/domains/run-now', 'POST', { domain_ids: ids }),
                label: (n) => 'Sync ' + n + ' domain' + (n === 1 ? '' : 's') + ' to HaloPSA',
                reloadUrl: '/admin/sync/halo/domains',
                rowsKey: 'domains',
            });
        }

        // IT Glue Sync Modal Functions
        function openItGlueSyncModal() {
            document.getElementById('itglueSyncModal').style.display = 'flex';
        }

        function closeItGlueSyncModal() {
            document.getElementById('itglueSyncModal').style.display = 'none';
        }







        async function loadIp2whoisDomains() {
            const listEl = document.getElementById('ip2whoisDomainList');
            listEl.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:40px;"><div style="font-size:32px;margin-bottom:12px;">⏳</div>Loading domains…</div>';

            try {
                const response = await fetch('/admin/sync/ip2whois/domains');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderIp2whoisDomainList(data.items || []);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load domains: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderIp2whoisDomainList(items) {
            const listEl = document.getElementById('ip2whoisDomainList');

            let html = `
                <div style="margin-bottom:16px;padding:12px;background:var(--surface-muted);border-radius:6px;display:grid;grid-template-columns:40px 1fr 1fr 140px 100px;gap:12px;align-items:center;font-weight:600;color:var(--dd-text-soft);font-size:13px;">
                    <input type="checkbox" id="selectAllIp2whois" onchange="toggleAllIp2whois(this)" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                    <div>Name</div>
                    <div>Client</div>
                    <div>Last synced</div>
                    <div style="text-align:center;">Status</div>
                </div>
            `;

            items.forEach(item => {
                const statusColor = item.has_data ? '#10b981' : '#94a3b8';
                const statusText = item.has_data ? 'Cached' : 'Not synced';
                const syncedAt = item.synced_at ? new Date(item.synced_at).toLocaleString() : '—';

                html += `
                    <div style="padding:12px;background:var(--surface-soft, var(--surface-muted));border-radius:6px;margin-bottom:8px;display:grid;grid-template-columns:40px 1fr 1fr 140px 100px;gap:12px;align-items:center;">
                        <input type="checkbox" class="ip2whois-checkbox" data-item-id="${ddEsc(item.id)}" style="width:18px;height:18px;cursor:pointer;border-radius:4px;">
                        <div style="color:var(--text);">${ddEsc(item.name)}</div>
                        <div style="color:var(--dd-text-soft);font-size:13px;">${ddEsc(item.client || 'No client')}</div>
                        <div style="color:var(--dd-text-soft);font-size:13px;">${syncedAt}</div>
                        <div style="text-align:center;color:${statusColor};font-size:13px;">${statusText}</div>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        function toggleAllIp2whois(checkbox) {
            document.querySelectorAll('.ip2whois-checkbox').forEach(cb => cb.checked = checkbox.checked);
        }

        async function syncIp2whoisDomains() {
            const selected = [];
            document.querySelectorAll('.ip2whois-checkbox:checked').forEach(cb => {
                selected.push({ id: cb.dataset.itemId });
            });

            if (selected.length === 0) {
                alert('Please select at least one domain to sync');
                return;
            }

            showGlobalSpinner('Syncing WHOIS data…');

            try {
                const response = await fetch('/admin/sync/ip2whois/domains/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ items: selected })
                });

                const data = await response.json();

                if (data.success) {
                    alert(`Synced ${data.synced_count} domain(s)`);
                    loadIp2whoisDomains();
                } else {
                    alert('Sync failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('Sync failed: ' + error.message);
            } finally {
                hideGlobalSpinner();
            }
        }

        async function loadItGlueConfigs() {
            const listEl = document.getElementById('itglueConfigList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading configuration items...');

            try {
                const response = await fetch('/admin/sync/itglue/configurations');
                const data = await response.json();

                if (data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error)}</div>`;
                    return;
                }

                renderItGlueConfigList(data.items, data.sync);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load items: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderItGlueConfigList(items, sync) {
            const listEl = document.getElementById('itglueConfigList');
            listEl.innerHTML = ddSyncBarHtml(sync, '/admin/sync/itglue/refresh');
            const mount = document.createElement('div');
            listEl.appendChild(mount);

            window._itglueConfigList = ddCreateDomainList(mount, items, {
                storageKey: 'itglue-configs',
                existsKey: 'exists_in_itglue',
                grid: '1.4fr 1fr 90px 150px',
                columns: [
                    { label: 'Name', render: (r) => ddNameCell(r) },
                    { label: 'Client', render: (r) => ddTextEl('div', r.client || 'No Client', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Type', render: (r) => ddTextEl('div', r.type || '', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                ],
            });
        }

        function syncItGlueConfigs() {
            return ddQueueDomainRun({
                listVar: '_itglueConfigList',
                panelId: 'itglueConfigRunPanel',
                emptyMessage: 'Please select at least one item to sync',
                request: (ids) => DDSync.request('/admin/sync/itglue/configurations/sync', 'POST', { items: ids.map((id) => ({ id: id, type: 'domain' })) }),
                label: (n) => 'Sync ' + n + ' domain' + (n === 1 ? '' : 's') + ' to IT Glue',
                reloadUrl: '/admin/sync/itglue/configurations',
                rowsKey: 'items',
            });
        }

        // ---- Generic asset sync (internet services / hosting / SSL certificates -> HaloPSA or IT Glue) ----
        const DD_ASSET_PROVIDERS = { halo: 'HaloPSA', itglue: 'IT Glue' };
        window._assetSyncCtx = null;

        function openAssetSyncModal(provider, entity) {
            window._assetSyncCtx = { provider: provider, entity: entity };
            document.getElementById('assetSyncTitle').textContent = 'Sync to ' + DD_ASSET_PROVIDERS[provider];
            document.getElementById('assetSyncRunPanel').replaceChildren();
            document.getElementById('assetSyncModal').style.display = 'flex';
            loadAssetList();
        }

        function closeAssetSyncModal() {
            document.getElementById('assetSyncModal').style.display = 'none';
        }

        async function loadAssetList() {
            const ctx = window._assetSyncCtx;
            const listEl = document.getElementById('assetSyncList');
            listEl.innerHTML = DD_SPINNER_HTML('Loading from DomainDash...');
            try {
                const response = await fetch('/admin/sync/assets/' + ctx.provider + '/' + ctx.entity, { headers: { Accept: 'application/json' } });
                const data = await response.json();
                if (!response.ok || data.error) {
                    listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">${ddEsc(data.error || data.message || 'Could not load the list.')}</div>`;
                    return;
                }
                document.getElementById('assetSyncTitle').textContent = 'Sync ' + data.label.toLowerCase() + ' to ' + DD_ASSET_PROVIDERS[ctx.provider];
                renderAssetList(data, ctx);
            } catch (error) {
                listEl.innerHTML = `<div style="text-align:center;color:var(--dd-danger-text);padding:40px;">Failed to load: ${ddEsc(error.message)}</div>`;
            }
        }

        function renderAssetList(data, ctx) {
            const listEl = document.getElementById('assetSyncList');
            listEl.replaceChildren();
            if (data.problem) {
                const warn = document.createElement('div');
                warn.className = 'ui-alert ui-alert--warning';
                warn.setAttribute('role', 'alert');
                warn.textContent = data.problem;
                listEl.appendChild(warn);
            }
            const mount = document.createElement('div');
            listEl.appendChild(mount);
            window._assetSyncList = ddCreateDomainList(mount, data.items, {
                storageKey: 'assets-' + ctx.provider + '-' + ctx.entity,
                existsKey: 'exists_remote',
                grid: '1.2fr 1fr 1.2fr 150px',
                emptyText: 'Nothing to show.',
                columns: [
                    { label: 'Name', render: (r) => ddNameCell(r) },
                    { label: 'Client', render: (r) => ddTextEl('div', r.client || 'No Client', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Details', render: (r) => ddTextEl('div', r.detail || '', 'color:var(--dd-text-soft);font-size:13px;') },
                    { label: 'Status', render: (r, l, st) => DDList.chip(st) },
                ],
            });
        }

        function syncAssetSelected() {
            const ctx = window._assetSyncCtx;
            const base = '/admin/sync/assets/' + ctx.provider + '/' + ctx.entity;
            return ddQueueDomainRun({
                listVar: '_assetSyncList',
                panelId: 'assetSyncRunPanel',
                emptyMessage: 'Please select at least one item to sync',
                request: (ids) => DDSync.request(base + '/sync', 'POST', { ids: ids }),
                label: (n) => 'Sync ' + n + ' item' + (n === 1 ? '' : 's') + ' to ' + DD_ASSET_PROVIDERS[ctx.provider],
                reloadUrl: base,
                rowsKey: 'items',
            });
        }

        function showHaloSyncProgress(message) {
            document.getElementById('haloSyncProgressTitle').textContent = message;
            document.getElementById('haloSyncProgressModal').style.display = 'flex';
        }

        function hideHaloSyncProgress() {
            document.getElementById('haloSyncProgressModal').style.display = 'none';
        }

        let _syncResultOnClose = null;

        function showSyncResult(title, message, errors, onClose) {
            _syncResultOnClose = onClose || null;

            document.getElementById('syncResultTitle').textContent = title;
            document.getElementById('syncResultMessage').textContent = message;

            const hasErrors = errors && errors.length > 0;
            const iconEl = document.getElementById('syncResultIcon');

            if (hasErrors) {
                iconEl.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
                const list = document.getElementById('syncResultErrorList');
                list.innerHTML = errors.map((e, idx) => `
                    <div style="padding:10px 12px;${idx < errors.length - 1 ? 'border-bottom:1px solid var(--border-subtle);' : ''}display:flex;flex-direction:column;gap:3px;">
                        <span style="font-size:13px;font-weight:600;color:var(--text);">${ddEsc(e.label)}</span>
                        <span style="font-size:13px;color:var(--dd-danger-text);">${ddEsc(e.message)}</span>
                    </div>`).join('');
                document.getElementById('syncResultErrors').style.display = 'block';
            } else {
                iconEl.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`;
                document.getElementById('syncResultErrors').style.display = 'none';
            }

            document.getElementById('syncResultModal').style.display = 'flex';
        }

        function closeSyncResultModal() {
            document.getElementById('syncResultModal').style.display = 'none';
            if (_syncResultOnClose) {
                const cb = _syncResultOnClose;
                _syncResultOnClose = null;
                cb();
            }
        }

        function showGlobalSpinner(message = 'Working…') {
            if (typeof window.showGlobalLoader === 'function') {
                window.showGlobalLoader(message);
            }
        }

        function hideGlobalSpinner() {
            if (typeof window.hideGlobalLoader === 'function') {
                window.hideGlobalLoader();
            }
        }
</script>
<style>
    @keyframes halo-spin {
        to { transform: rotate(360deg); }
    }
</style>
