/*
 * Notification centre: topbar bell + right-hand slide-out panel.
 * Data comes from GET /notifications/summary (initial copy is embedded in data-initial). Polls every 20s, every 4s
 * while something is in progress, and pauses while the tab is hidden. Every server string is inserted with
 * textContent, never innerHTML.
 */
(function () {
    const root = document.querySelector('[data-notif]');
    if (!root) return;

    const bell = root.querySelector('[data-notif-bell]');
    const panel = root.querySelector('[data-notif-panel]');
    const overlay = root.querySelector('[data-notif-overlay]');
    const badge = root.querySelector('[data-notif-badge]');
    const spin = root.querySelector('[data-notif-spin]');
    const live = root.querySelector('[data-notif-live]');
    const body = panel.querySelector('[data-notif-body]');
    const markAll = panel.querySelector('[data-notif-markall]');
    // The topbar creates a containing block for fixed elements; the panel must live directly under <body>.
    [overlay, panel].forEach(function (n) { document.body.appendChild(n); });
    const summaryUrl = root.dataset.url;
    const snoozeUrl = root.dataset.snoozeUrl;
    const readAllUrl = root.dataset.readAllUrl;
    const SLOW = 20000, FAST = 4000;

    let state = null;
    let seenAlerts = null;
    let timer = null;
    let open = false;

    function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function ago(iso) {
        if (!iso) return '';
        const s = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
        if (isNaN(s)) return '';
        const f = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
        const a = Math.abs(s);
        if (a < 60) return f.format(Math.round(s / 1) , 'second').replace(/^in 0 seconds$/, 'now');
        if (a < 3600) return f.format(Math.round(s / 60), 'minute');
        if (a < 86400) return f.format(Math.round(s / 3600), 'hour');
        return f.format(Math.round(s / 86400), 'day');
    }
    async function call(url, method, payload) {
        const res = await fetch(url, {
            method: method || 'GET',
            headers: Object.assign({ 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, payload ? { 'Content-Type': 'application/json' } : {}),
            body: payload ? JSON.stringify(payload) : undefined,
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function section(title, count, cls) {
        const wrap = el('section', 'notif-section ' + (cls || ''));
        const h = el('h3', 'notif-section__title');
        h.appendChild(el('span', null, title));
        h.appendChild(el('span', 'notif-section__count', String(count)));
        wrap.appendChild(h);
        return wrap;
    }

    function progressItem(p) {
        const li = el('li', 'notif-item notif-item--progress');
        const head = el('div', 'notif-item__head');
        head.appendChild(el('span', 'notif-item__title', p.title));
        head.appendChild(el('span', 'ui-badge ui-badge--dot ui-badge--' + (p.status === 'queued' || p.status === 'pending' ? 'neutral' : 'info'), p.status === 'queued' || p.status === 'pending' ? 'Queued' : 'Running'));
        li.appendChild(head);
        li.appendChild(el('div', 'notif-item__text', p.detail));
        const bar = el('div', 'notif-bar' + (p.percent === null ? ' notif-bar--indeterminate' : ''));
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', p.title);
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', '100');
        if (p.percent !== null) bar.setAttribute('aria-valuenow', String(p.percent));
        const fill = el('span');
        if (p.percent !== null) fill.style.width = p.percent + '%';
        bar.appendChild(fill);
        li.appendChild(bar);
        return li;
    }

    function alertItem(a) {
        const li = el('li', 'notif-item notif-item--' + a.severity);
        const head = el('div', 'notif-item__head');
        const sev = el('span', 'notif-sev notif-sev--' + a.severity);
        sev.setAttribute('aria-hidden', 'true');
        head.appendChild(sev);
        head.appendChild(el('span', 'notif-item__title', a.title));
        const sr = el('span', 'sr-only', ' (' + a.severity + ')');
        head.appendChild(sr);
        li.appendChild(head);
        li.appendChild(el('div', 'notif-item__text', a.text));
        const foot = el('div', 'notif-item__foot');
        if (a.at) foot.appendChild(el('span', 'notif-item__time', ago(a.at)));
        const actions = el('div', 'notif-item__actions');
        if (a.url) { const l = el('a', 'ui-btn ui-btn--secondary ui-btn--sm', 'Fix'); l.href = a.url; actions.appendChild(l); }
        [['Snooze 1h', 1], ['Dismiss', 24]].forEach(function (x) {
            const b = el('button', 'ui-btn ui-btn--ghost ui-btn--sm', x[0]);
            b.type = 'button';
            b.title = x[1] === 24 ? 'Hide for 24 hours; it returns if the problem is still there' : 'Hide for 1 hour';
            b.addEventListener('click', async function () {
                b.disabled = true;
                try { apply(await call(snoozeUrl, 'POST', { key: a.key, hours: x[1] })); } catch (e) { b.disabled = false; }
            });
            actions.appendChild(b);
        });
        foot.appendChild(actions);
        li.appendChild(foot);
        return li;
    }

    function noteItem(n) {
        const li = el('li', 'notif-item');
        li.appendChild(el('div', 'notif-item__title', n.title));
        if (n.text) li.appendChild(el('div', 'notif-item__text', n.text));
        const foot = el('div', 'notif-item__foot');
        foot.appendChild(el('span', 'notif-item__time', ago(n.at)));
        const actions = el('div', 'notif-item__actions');
        if (n.url) { const l = el('a', 'ui-btn ui-btn--secondary ui-btn--sm', 'Open'); l.href = n.url; actions.appendChild(l); }
        const b = el('button', 'ui-btn ui-btn--ghost ui-btn--sm', 'Mark as read');
        b.type = 'button';
        b.addEventListener('click', async function () {
            b.disabled = true;
            try { apply(await call(n.read_url, 'POST')); } catch (e) { b.disabled = false; }
        });
        actions.appendChild(b);
        foot.appendChild(actions);
        li.appendChild(foot);
        return li;
    }

    function fill(list, items, make) {
        const ul = el('ul', 'notif-list-items');
        items.forEach(function (i) { ul.appendChild(make(i)); });
        list.appendChild(ul);
        return list;
    }

    function render() {
        const s = state;
        // Badge: unread notifications + active alerts, coloured by the highest severity.
        const n = s.count;
        badge.hidden = n === 0;
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.className = 'notif-badge' + (s.severity ? ' notif-badge--' + s.severity : '');
        spin.hidden = !(s.in_progress_count > 0);
        const parts = [];
        if (n) parts.push(n + ' need' + (n === 1 ? 's' : '') + ' review');
        if (s.in_progress_count) parts.push(s.in_progress_count + ' in progress');
        bell.setAttribute('aria-label', 'Notifications' + (parts.length ? ': ' + parts.join(', ') : ''));
        if (markAll) markAll.hidden = !s.unread_count;

        body.replaceChildren();
        if (s.is_admin && s.in_progress.length) body.appendChild(fill(section('In progress', s.in_progress.length), s.in_progress, progressItem));
        if (s.is_admin && s.alerts.length) body.appendChild(fill(section('Needs attention', s.alerts.length), s.alerts, alertItem));
        if (s.notifications.length) body.appendChild(fill(section('Notifications', s.unread_count), s.notifications, noteItem));
        if (!body.children.length) body.appendChild(el('p', 'notif-empty', 'You are all caught up.'));
    }

    function announce(prev, next) {
        if (!live) return;
        const keys = new Set((next.alerts || []).map(function (a) { return a.key; }));
        if (seenAlerts) {
            const fresh = (next.alerts || []).filter(function (a) { return !seenAlerts.has(a.key); });
            if (fresh.length) live.textContent = fresh.length === 1 ? 'New alert: ' + fresh[0].title : fresh.length + ' new alerts';
        }
        seenAlerts = keys;
    }

    function apply(next) {
        announce(state, next);
        state = next;
        render();
        schedule();
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(refresh, state && state.in_progress_count > 0 ? FAST : SLOW);
    }

    async function refresh() {
        if (document.hidden) { schedule(); return; }
        try { apply(await call(summaryUrl)); } catch (e) { schedule(); }
    }

    // ---- open / close with focus management ----
    let lastFocus = null;
    function focusables() {
        return Array.prototype.filter.call(panel.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'), function (n) { return !n.hidden && n.offsetParent !== null; });
    }
    function show() {
        if (open) return;
        open = true;
        lastFocus = document.activeElement;
        panel.hidden = false; overlay.hidden = false;
        requestAnimationFrame(function () { panel.classList.add('is-open'); overlay.classList.add('is-open'); });
        bell.setAttribute('aria-expanded', 'true');
        document.body.classList.add('notif-lock');
        (panel.querySelector('[data-notif-close]') || panel).focus();
        refresh();
    }
    function hide() {
        if (!open) return;
        open = false;
        panel.classList.remove('is-open'); overlay.classList.remove('is-open');
        bell.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('notif-lock');
        setTimeout(function () { if (!open) { panel.hidden = true; overlay.hidden = true; } }, 200);
        (lastFocus && document.contains(lastFocus) ? lastFocus : bell).focus();
    }

    bell.addEventListener('click', function () { open ? hide() : show(); });
    overlay.addEventListener('click', hide);
    panel.querySelector('[data-notif-close]').addEventListener('click', hide);
    panel.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.stopPropagation(); hide(); return; }
        if (e.key !== 'Tab') return;
        const f = focusables();
        if (!f.length) { e.preventDefault(); return; }
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === panel)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    if (markAll) markAll.addEventListener('click', async function () { try { apply(await call(readAllUrl, 'POST')); } catch (e) { /* ignore */ } });
    document.addEventListener('click', function (e) {
        const t = e.target.closest && e.target.closest('[data-notif-open]');
        if (t) { e.preventDefault(); show(); }
    });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });

    try { state = JSON.parse(root.dataset.initial); } catch (e) { state = null; }
    if (state) { seenAlerts = new Set((state.alerts || []).map(function (a) { return a.key; })); render(); schedule(); }
    else refresh();

    window.DDNotify = { open: show, close: hide, refresh: refresh };
})();
