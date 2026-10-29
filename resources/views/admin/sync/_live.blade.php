{{--
    Live sync feedback shared by Settings and Clients pages.
    DDSync.track(runs, mountElement, opts) renders each run inline and polls
    GET /admin/sync/runs/{id} every 3s: Queued -> Running -> Succeeded / Partial / Failed / Skipped.
    Every server string is inserted with textContent (never innerHTML).
--}}
<style>
    .dd-run { border: 1px solid var(--dd-border, var(--border-subtle)); border-radius: var(--dd-radius, 10px); background: var(--dd-surface-soft, var(--surface-muted)); padding: .75rem .875rem; margin: 0 0 .75rem; font-size: 13px; color: var(--dd-text, var(--text)); }
    .dd-run__head { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
    .dd-run__title { font-weight: 600; }
    .dd-run__meta { color: var(--dd-text-soft, var(--text-muted)); }
    .dd-run__bar { height: 8px; border-radius: 999px; background: var(--dd-status-muted-bg, #e8ebef); overflow: hidden; margin-top: .5rem; }
    .dd-run__bar > span { display: block; height: 100%; width: 0; background: var(--dd-accent, #2563eb); transition: width .3s; }
    .dd-run__error { margin-top: .5rem; color: var(--dd-danger-text, #b42318); word-break: break-word; }
    .dd-run__list { margin: .5rem 0 0; padding: 0; list-style: none; max-height: 180px; overflow-y: auto; border: 1px solid var(--dd-border, var(--border-subtle)); border-radius: 8px; }
    .dd-run__list li { padding: .375rem .625rem; border-bottom: 1px solid var(--dd-border, var(--border-subtle)); word-break: break-word; }
    .dd-run__list li:last-child { border-bottom: 0; }
    .dd-run__actions { margin-top: .5rem; display: flex; gap: .5rem; flex-wrap: wrap; }
</style>
<script>
    window.DDSync = (function () {
        const POLL_MS = 3000;
        const LABELS = { queued: 'Queued', running: 'Running', ok: 'Succeeded', partial: 'Partial', failed: 'Failed', skipped: 'Skipped' };
        const VARIANTS = { queued: 'neutral', running: 'info', ok: 'success', partial: 'warning', failed: 'danger', skipped: 'warning' };
        const SCOPES = { 'halo/clients': 'HaloPSA clients', 'halo/assets': 'HaloPSA assets', 'halo/domains': 'Sync domains to HaloPSA', 'itglue/organizations': 'IT Glue organisations', 'itglue/configurations': 'Sync domains to IT Glue' };

        function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }

        async function request(url, method, body) {
            const res = await fetch(url, {
                method: method || 'GET',
                headers: Object.assign({ 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body ? { 'Content-Type': 'application/json' } : {}),
                body: body ? JSON.stringify(body) : undefined,
            });
            let data = {};
            try { data = await res.json(); } catch (e) { data = { error: 'Unexpected response (HTTP ' + res.status + ')' }; }
            return { ok: res.ok, status: res.status, data: data };
        }

        function el(tag, cls, text) {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        }

        function updateWorker(worker) {
            if (!worker) return;
            document.querySelectorAll('[data-dd-worker-banner]').forEach(function (b) {
                b.style.display = worker.stale ? '' : 'none';
                const m = b.querySelector('[data-dd-worker-msg]');
                if (m && worker.message) m.textContent = worker.message;
            });
            window.DDSync.worker = worker;
        }

        async function refreshWorker() {
            try { const r = await request('/admin/sync/status'); if (r.ok) updateWorker(r.data.worker); } catch (e) { /* ignore */ }
        }

        function describe(run) {
            const parts = [];
            if (run.status === 'queued') parts.push('Waiting for a queue worker to pick it up');
            if (run.status === 'running') {
                parts.push(run.total ? (run.done + run.failed + run.skipped) + ' of ' + run.total + ' processed' : 'Working');
                if (run.done) parts.push(run.done + ' succeeded');
                if (run.failed) parts.push(run.failed + ' failed');
                if (run.skipped) parts.push(run.skipped + ' unchanged');
            }
            if (run.status === 'ok') parts.push(run.total ? run.done + ' synced' + (run.skipped ? ', ' + run.skipped + ' unchanged' : '') : 'Nothing to do');
            if (run.status === 'partial') parts.push(run.done + ' synced, ' + run.failed + ' failed' + (run.skipped ? ', ' + run.skipped + ' unchanged' : ''));
            return parts.join(' · ');
        }

        function render(card, run, opts) {
            const key = run.provider + '/' + run.scope;
            card.replaceChildren();
            const head = el('div', 'dd-run__head');
            head.appendChild(el('span', 'dd-run__title', (opts && opts.label) || SCOPES[key] || key));
            head.appendChild(el('span', 'ui-badge ui-badge--dot ui-badge--' + (VARIANTS[run.status] || 'neutral'), LABELS[run.status] || run.status));
            head.appendChild(el('span', 'dd-run__meta', describe(run)));
            card.appendChild(head);

            if (run.status === 'queued' || run.status === 'running' || (run.total && run.finished)) {
                const bar = el('div', 'dd-run__bar');
                const fill = el('span');
                const processed = run.done + run.failed + run.skipped;
                const pct = run.finished ? 100 : (run.total ? Math.min(100, Math.round((processed / run.total) * 100)) : (run.status === 'running' ? 10 : 0));
                fill.style.width = pct + '%';
                if (run.failed) fill.style.background = 'var(--dd-danger-text, #b42318)';
                bar.setAttribute('role', 'progressbar');
                bar.setAttribute('aria-valuemin', '0');
                bar.setAttribute('aria-valuemax', '100');
                bar.setAttribute('aria-valuenow', String(pct));
                bar.setAttribute('aria-label', (opts && opts.label) || SCOPES[key] || key);
                bar.appendChild(fill);
                card.appendChild(bar);
            }

            if (run.error && run.status !== 'ok') {
                card.appendChild(el('div', 'dd-run__error', (run.status === 'skipped' ? 'Skipped: ' : (run.retrying ? '' : 'Error: ')) + run.error));
            }

            if (run.failures && run.failures.length) {
                const list = el('ul', 'dd-run__list');
                run.failures.forEach(function (f) {
                    const li = el('li');
                    li.appendChild(el('strong', null, f.name));
                    li.appendChild(document.createTextNode(': ' + f.error));
                    list.appendChild(li);
                });
                card.appendChild(list);
            }
            return card;
        }

        /**
         * runs: array of status arrays returned by the dispatch endpoints.
         * opts: { label, onDone(run), onUpdate(run), runNow: () => Promise }
         */
        function track(runs, mount, opts) {
            opts = opts || {};
            mount.replaceChildren();
            const state = { timers: [], remaining: runs.length };

            (mount._ddTimers || []).forEach(clearTimeout);
            mount._ddTimers = state.timers;

            runs.forEach(function (initial, i) {
                const card = el('div', 'dd-run');
                card.setAttribute('role', 'status');
                card.setAttribute('aria-live', 'polite');
                mount.appendChild(card);
                render(card, initial, opts);
                let finished = initial.finished;
                if (finished) state.remaining--;

                async function poll() {
                    try {
                        const r = await request('/admin/sync/runs/' + encodeURIComponent(initial.id));
                        if (r.ok) {
                            updateWorker(r.data.worker);
                            render(card, r.data, opts);
                            addRunNow(card, r.data);
                            if (opts.onUpdate) opts.onUpdate(r.data);
                            if (r.data.finished) {
                                if (--state.remaining <= 0 && opts.onDone) opts.onDone(r.data);
                                return;
                            }
                        }
                    } catch (e) { /* transient network error: keep polling */ }
                    state.timers[i] = setTimeout(poll, POLL_MS);
                }

                function addRunNow(c, run) {
                    if (!opts.runNow || run.status !== 'queued' || !window.DDSync.worker || !window.DDSync.worker.stale) return;
                    const actions = el('div', 'dd-run__actions');
                    const btn = el('button', 'ui-btn ui-btn--secondary ui-btn--sm', 'Run now (no worker detected)');
                    btn.type = 'button';
                    btn.onclick = async function () {
                        btn.disabled = true;
                        btn.textContent = 'Running…';
                        const out = await opts.runNow();
                        if (!out.ok) { c.appendChild(el('div', 'dd-run__error', 'Error: ' + (out.data.error || 'Run now failed'))); btn.disabled = false; btn.textContent = 'Run now (no worker detected)'; return; }
                        track([out.data.run], mount, Object.assign({}, opts, { label: opts.label }));
                    };
                    actions.appendChild(btn);
                    c.appendChild(actions);
                }

                if (!finished) state.timers[i] = setTimeout(poll, POLL_MS);
                else if (opts.onDone && state.remaining <= 0) opts.onDone(initial);
            });
        }

        return { request: request, track: track, updateWorker: updateWorker, refreshWorker: refreshWorker, worker: null };
    })();
</script>
