import './bootstrap';

const trackedFetchHints = ['/sync', '/bulk-sync'];
const trackedFormHints = ['/sync', '/bulk-sync'];
let pendingRequests = 0;

function ensureGlobalLoader() {
    let overlay = document.getElementById('dd-global-loader');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'dd-global-loader';
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = `
            <div class="dd-global-loader__panel" role="status" aria-atomic="true">
                <div class="dd-global-loader__spinner" aria-hidden="true"></div>
                <p id="dd-global-loader-message" class="dd-global-loader__message">Sync in progress…</p>
                <p class="dd-global-loader__hint">Large syncs can take a few minutes. Please keep this tab open.</p>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    return overlay;
}

function updateLoaderMessage(message) {
    const messageNode = document.getElementById('dd-global-loader-message');

    if (messageNode && message) {
        messageNode.textContent = message;
    }
}

function showGlobalLoader(message = 'Sync in progress…') {
    const overlay = ensureGlobalLoader();
    updateLoaderMessage(message);
    overlay.classList.add('is-visible');
}

function hideGlobalLoader() {
    const overlay = document.getElementById('dd-global-loader');

    if (!overlay) {
        return;
    }

    overlay.classList.remove('is-visible');
}

function shouldTrackUrl(url) {
    return trackedFetchHints.some((hint) => url.includes(hint));
}

window.showGlobalLoader = showGlobalLoader;
window.hideGlobalLoader = hideGlobalLoader;

const nativeFetch = window.fetch.bind(window);
window.fetch = async (input, init = {}) => {
    const target = typeof input === 'string' ? input : (input?.url ?? '');
    const trackRequest = shouldTrackUrl(target);

    if (trackRequest) {
        pendingRequests += 1;
        const actionMessage = target.includes('itglue')
            ? 'Syncing with IT Glue…'
            : target.includes('halo')
                ? 'Syncing with HaloPSA…'
                : target.includes('ip2whois')
                    ? 'Syncing WHOIS records…'
                    : 'Sync in progress…';
        showGlobalLoader(actionMessage);
    }

    try {
        return await nativeFetch(input, init);
    } finally {
        if (trackRequest) {
            pendingRequests = Math.max(0, pendingRequests - 1);
            if (pendingRequests === 0) {
                hideGlobalLoader();
            }
        }
    }
};

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const action = form.getAttribute('action') ?? '';
    if (!action) {
        return;
    }

    if (trackedFormHints.some((hint) => action.includes(hint))) {
        showGlobalLoader('Sync in progress…');
    }
});

/* ==========================================================================
   UI shell behaviour (sidebar, nav sections, user menu, dialogs)
   ========================================================================== */

window.toggleNav = function toggleNav(element) {
    const section = element.closest('.nav-section');
    if (!section) {
        return;
    }
    const expanded = section.classList.toggle('expanded');
    element.setAttribute('aria-expanded', expanded ? 'true' : 'false');
};

function setSidebar(open) {
    document.body.classList.toggle('nav-open', open);
    document.querySelectorAll('[data-sidebar-toggle]').forEach((btn) => {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) {
        return;
    }

    // Mobile sidebar
    if (target.closest('[data-sidebar-toggle]')) {
        setSidebar(!document.body.classList.contains('nav-open'));
        return;
    }
    if (target.closest('.sidebar-scrim') || (target.closest('.sidebar a.nav-link') && window.matchMedia('(max-width: 1023px)').matches)) {
        setSidebar(false);
    }

    // Close open <details> menus when clicking elsewhere
    document.querySelectorAll('.user-menu details[open]').forEach((d) => {
        if (!d.contains(target)) {
            d.removeAttribute('open');
        }
    });

    // Generic dialogs: <x-ui.modal>
    const opener = target.closest('[data-modal-open]');
    if (opener) {
        const modal = document.getElementById(opener.getAttribute('data-modal-open'));
        if (modal) {
            modal.style.display = 'flex';
            modal.__returnFocus = opener;
            modal.querySelector('[role="dialog"]')?.focus();
        }
        return;
    }
    if (target.closest('[data-modal-close]')) {
        const modal = target.closest('[data-ui-modal]');
        if (modal) {
            modal.style.display = 'none';
            modal.__returnFocus?.focus?.();
        }
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }
    setSidebar(false);
    document.querySelectorAll('[data-ui-modal]').forEach((modal) => {
        if (modal.style.display === 'none') {
            return;
        }
        modal.style.display = 'none';
        modal.__returnFocus?.focus?.();
    });
    document.querySelectorAll('.user-menu details[open]').forEach((d) => d.removeAttribute('open'));
});
