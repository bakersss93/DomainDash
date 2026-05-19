import './bootstrap';

const trackedFetchHints = ['/sync', '/bulk-sync', 'halo', 'itglue', '/services/ssl', '/tickets', '/auth-code'];
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
                <div class="dd-global-loader__progress-wrap" id="dd-global-loader-progress" hidden>
                    <div class="dd-global-loader__progress-track">
                        <div class="dd-global-loader__progress-fill" id="dd-global-loader-fill"></div>
                    </div>
                    <p class="dd-global-loader__progress-label" id="dd-global-loader-progress-label"></p>
                </div>
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

    const progressWrap = document.getElementById('dd-global-loader-progress');
    if (progressWrap) {
        progressWrap.hidden = true;
    }
    const fill = document.getElementById('dd-global-loader-fill');
    if (fill) {
        fill.style.width = '0%';
    }
}

function holdGlobalLoader() {
    pendingRequests += 1;
}

function releaseGlobalLoader() {
    pendingRequests = Math.max(0, pendingRequests - 1);
    if (pendingRequests === 0) {
        hideGlobalLoader();
    }
}

function showGlobalLoaderWithProgress(total, message = 'Syncing…') {
    showGlobalLoader(message);
    ensureGlobalLoader();
    const progressWrap = document.getElementById('dd-global-loader-progress');
    if (progressWrap) {
        progressWrap.hidden = false;
    }
    updateGlobalLoaderProgress(0, total);
}

function updateGlobalLoaderProgress(current, total, label) {
    const fill = document.getElementById('dd-global-loader-fill');
    const progressLabel = document.getElementById('dd-global-loader-progress-label');

    if (fill) {
        fill.style.width = total > 0 ? `${Math.round((current / total) * 100)}%` : '0%';
    }
    if (progressLabel) {
        progressLabel.textContent = label !== undefined ? label : `${current} of ${total}`;
    }
}

function shouldTrackUrl(url) {
    return trackedFetchHints.some((hint) => url.includes(hint));
}

window.showGlobalLoader = showGlobalLoader;
window.hideGlobalLoader = hideGlobalLoader;
window.holdGlobalLoader = holdGlobalLoader;
window.releaseGlobalLoader = releaseGlobalLoader;
window.showGlobalLoaderWithProgress = showGlobalLoaderWithProgress;
window.updateGlobalLoaderProgress = updateGlobalLoaderProgress;

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
                    : target.includes('/services/ssl')
                        ? 'Processing SSL certificate…'
                        : target.includes('/tickets')
                            ? 'Loading tickets…'
                            : target.includes('/auth-code')
                                ? 'Retrieving authorisation code…'
                                : 'Loading…';
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
