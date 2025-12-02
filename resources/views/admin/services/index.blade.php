@extends('layouts.app')

@section('content')
<div style="max-width: 1200px; margin: 0 auto;">
    <div class="dd-services-card">
        <h1 class="dd-services-title">
            Hosting Services
        </h1>

    {{-- Filter + sync toolbar --}}
    <div class="dd-services-toolbar">
        <form method="GET"
              action="{{ route('admin.services.hosting') }}"
              class="dd-services-filter">
            <select name="client_id"
                    class="dd-pill-input dd-pill-select">
                <option value="">All clients</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}"
                        {{ (isset($clientId) && (int)$clientId === $client->id) ? 'selected' : '' }}>
                        {{ $client->business_name ?? $client->name ?? ('Client #' . $client->id) }}
                    </option>
                @endforeach
            </select>

            <button type="submit" class="btn-accent dd-pill-btn">
                Filter
            </button>
        </form>

        <form method="POST"
              action="{{ route('admin.services.hosting.sync') }}"
              class="dd-services-sync">
            @csrf
            <button type="submit"
                    class="btn-accent dd-pill-btn"
                    onclick="return confirm('Sync hosting services from Synergy now?');">
                Sync services
            </button>
        </form>
    </div>

    {{-- Services table --}}
    <div class="dd-services-table-wrapper">
        <table class="dd-services-table">
            <thead>
            <tr>
                <th>Domain</th>
                <th>Plan</th>
                <th>Username</th>
                <th>Client</th>
                <th>Disk</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            @forelse($services as $service)
                @php
                    $domainLabel =
                        optional($service->domain)->name
                        ?? $service->domain_name
                        ?? $service->domain
                        ?? '-';
                    $clientLabel =
                        optional($service->client)->business_name
                        ?? optional($service->client)->name
                        ?? '-';
                @endphp
                <tr class="dd-service-row" data-service-id="{{ $service->id }}">
                    <td class="dd-service-domain-cell">
                        <span class="dd-service-domain-text">
                            {{ $domainLabel }}
                        </span>
                    </td>
                    <td>{{ $service->plan ?? '-' }}</td>
                    <td>{{ $service->username ?? '-' }}</td>
                    <td>{{ $clientLabel }}</td>
                    <td>
                        @php
                            $usage = $service->disk_usage_mb ?? null;
                            $limit = $service->disk_limit_mb ?? null;
                        @endphp
                        @if($usage !== null || $limit !== null)
                            {{ $usage ?? '?' }} / {{ $limit ?? '?' }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        {{ $service->ip
                           ?? $service->ip_address
                           ?? $service->dedicated_ipv4
                           ?? '-' }}
                    </td>
                </tr>

                {{-- Slide-down details row --}}
                <tr class="dd-service-details dd-hidden"
                    id="service-details-{{ $service->id }}">
                    <td colspan="6">
                        <div class="dd-service-details-inner"
                             data-details-for="{{ $service->id }}">
                            <div class="dd-service-details-overview">
                                <div class="dd-service-detail">
                                    <span class="dd-service-label">Plan</span>
                                    <span class="dd-service-value dd-detail-plan">–</span>
                                </div>
                                <div class="dd-service-detail">
                                    <span class="dd-service-label">Server</span>
                                    <span class="dd-service-value dd-detail-server">–</span>
                                </div>
                                <div class="dd-service-detail">
                                    <span class="dd-service-label">Status</span>
                                    <span class="dd-service-value dd-detail-status">–</span>
                                </div>
                                <div class="dd-service-detail">
                                    <span class="dd-service-label">IP</span>
                                    <span class="dd-service-value dd-detail-ip">–</span>
                                </div>
                                <div class="dd-service-detail">
                                    <span class="dd-service-label">Disk</span>
                                    <span class="dd-service-value dd-detail-disk">–</span>
                                </div>
                                <div class="dd-service-detail">
                                    <span class="dd-service-label">Username</span>
                                    <span class="dd-service-value dd-detail-username">–</span>
                                </div>
                                <div class="dd-service-detail">
                                    <button type="button"
                                            class="btn-secondary dd-pill-btn dd-password-btn"
                                            data-password-id="{{ $service->id }}">
                                        Show password
                                    </button>
                                </div>
                            </div>
                            <div class="dd-service-details-actions">
                                {{-- Assign client (opens modal) --}}
                                <button type="button"
                                        class="btn-accent dd-pill-btn dd-assign-btn"
                                        data-service-id="{{ $service->id }}"
                                        data-assign-url="{{ route('admin.services.hosting.assignClient', $service) }}"
                                        data-current-client-id="{{ $service->client_id ?? '' }}">
                                    Assign client
                                </button>

                                {{-- Suspend / unsuspend (plain button, no label) --}}
                                <form method="POST"
                                    action="{{ route('admin.services.hosting.suspend', $service) }}"
                                    class="dd-inline-form">
                                    @csrf
                                    <button type="submit" class="btn-danger dd-pill-btn dd-suspend-btn">
                                        @if(property_exists($service, 'is_suspended') && $service->is_suspended)
                                            Unsuspend
                                        @else
                                            Suspend
                                        @endif
                                    </button>
                                </form>

                                {{-- Change primary domain (opens modal) --}}
                                <button type="button"
                                        class="btn-accent dd-pill-btn dd-change-domain-btn"
                                        data-service-id="{{ $service->id }}"
                                        data-change-url="{{ route('admin.services.hosting.changeDomain', $service) }}"
                                        data-domain="{{ $service->domain_name }}">
                                    Change primary domain
                                </button>

                                {{-- Login to cPanel (simple button, still a form so it can POST and open new tab) --}}
                                <form method="POST"
                                    action="{{ route('admin.services.hosting.login', $service) }}"
                                    class="dd-inline-form"
                                    target="_blank">
                                    @csrf
                                    <button type="submit" class="btn-accent dd-pill-btn">
                                        Open cPanel
                                    </button>
                                </form>
                            </div>

                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6"
                        style="text-align:center;padding:12px 0;opacity:.7;">
                        No hosting services found. Try syncing from Synergy.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="dd-services-pagination">
        {{ $services->links() }}
    </div>
</div>
</div>

{{-- Password modal --}}
<div id="dd-password-modal" class="dd-password-modal dd-hidden" aria-hidden="true">
    <div class="dd-password-backdrop" data-dd-password-close></div>
    <div class="dd-password-panel">
        <h2 class="dd-password-title">Service password</h2>
        <div class="dd-password-input-wrapper">
            <input id="dd-password-input"
                type="password"
                readonly
                class="dd-pill-input dd-password-input"
                value="••••••••">
            <button type="button"
                    id="dd-password-toggle"
                    class="dd-password-toggle"
                    aria-label="Toggle password visibility">
                👁
            </button>
        </div>
        <div class="dd-password-actions">
            <button type="button"
                    class="btn-secondary dd-pill-btn"
                    data-dd-password-close>
                Close
            </button>
            <button type="button"
                    class="btn-accent dd-pill-btn"
                    id="dd-password-copy">
                Copy to clipboard
            </button>
        </div>
    </div>
</div>

{{-- Assign client modal --}}
<div id="dd-assign-modal" class="dd-password-modal dd-hidden" aria-hidden="true">
    <div class="dd-password-backdrop" data-dd-assign-close></div>
    <div class="dd-password-panel">
        <h2 class="dd-password-title">Assign client</h2>
        <form id="dd-assign-form" method="POST">
            @csrf
            <div class="dd-password-input-wrapper">
                <select name="client_id"
                        id="dd-assign-select"
                        class="dd-pill-input dd-pill-select">
                    <option value="">Unassigned</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">
                            {{ $client->business_name ?? $client->name ?? ('Client #' . $client->id) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="dd-password-actions">
                <button type="button"
                        class="btn-secondary dd-pill-btn"
                        data-dd-assign-close>
                    Cancel
                </button>
                <button type="submit"
                        class="btn-accent dd-pill-btn">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Change primary domain modal --}}
<div id="dd-domain-modal" class="dd-password-modal dd-hidden" aria-hidden="true">
    <div class="dd-password-backdrop" data-dd-domain-close></div>
    <div class="dd-password-panel">
        <h2 class="dd-password-title">Change primary domain</h2>
        <form id="dd-domain-form" method="POST">
            @csrf
            <div class="dd-password-input-wrapper">
                <input type="text"
                       name="domain_name"
                       id="dd-domain-input"
                       class="dd-pill-input"
                       value="">
            </div>
            <div class="dd-password-actions">
                <button type="button"
                        class="btn-secondary dd-pill-btn"
                        data-dd-domain-close>
                    Cancel
                </button>
                <button type="submit"
                        class="btn-accent dd-pill-btn">
                    Update
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    :root {
        --dd-card-radius: 18px;
        --dd-card-padding: 18px 20px;

        --dd-pill-radius: 9999px;
        --dd-pill-padding: 8px 14px;

        --dd-card-bg: #ffffff;
        --dd-card-border: #d1d5db;
        --dd-pill-bg: #f3f4f6;
        --dd-pill-border: #d1d5db;
        --dd-text-color: #111827;
        --dd-header-bg: #f9fafb;
        --dd-header-text: #111827;
        --dd-row-alt-bg: #f9f9fb;
        --dd-overlay-bg: rgba(15,23,42,0.65);
    }

    body.dark-mode,
    body[data-theme="dark"],
    html.dark,
    html[data-theme="dark"] {
        --dd-card-bg: #020617;
        --dd-card-border: #1f2937;
        --dd-pill-bg: #1f2937;
        --dd-pill-border: #4b5563;
        --dd-text-color: #e5e7eb;
        --dd-header-bg: #020617;
        --dd-header-text: #f9fafb;
        --dd-row-alt-bg: #111827;
        --dd-overlay-bg: rgba(15,23,42,0.85);
    }

    .dd-hidden {
        display: none;
    }

    .dd-services-card {
        border-radius: var(--dd-card-radius);
        padding: var(--dd-card-padding);
        margin-top: 24px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
        color: var(--dd-text-color);
    }

    .dd-services-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 12px;
    }

    .dd-services-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }

    .dd-services-filter {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .dd-services-sync {
        display: flex;
        align-items: center;
        justify-content: flex-end;
    }

    .dd-services-table-wrapper {
        overflow-x: auto;
    }

    .dd-services-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .dd-services-table thead tr {
        background: var(--dd-header-bg);
        color: var(--dd-header-text);
    }

    .dd-services-table th,
    .dd-services-table td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid rgba(148,163,184,0.4);
    }

    .dd-services-table tbody tr:nth-child(even) {
        background: var(--dd-row-alt-bg);
    }

    .dd-services-pagination {
        margin-top: 10px;
    }

    .dd-service-row {
        cursor: pointer;
    }

    .dd-service-row:hover {
        background-color: rgba(148,163,184,0.18);
    }

    .dd-service-domain-cell {
        position: relative;
    }

    .dd-service-domain-text {
        text-decoration: none;
    }

    .dd-service-details-inner {
        padding: 10px 6px 6px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .dd-service-details-overview {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 8px 16px;
    }

    .dd-service-detail {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .dd-service-label {
        font-size: 11px;
        text-transform: uppercase;
        opacity: .7;
    }

    .dd-service-value {
        font-size: 13px;
        font-weight: 500;
    }

    .dd-service-details-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        margin-top: 8px;
    }

    .dd-service-form {
        border-radius: 12px;
        padding: 10px;
        border: 1px solid var(--dd-card-border);
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .dd-service-form-label {
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 2px;
        display: block;
    }

    .dd-pill-input {
        border-radius: var(--dd-pill-radius) !important;
        border: 1px solid var(--dd-pill-border) !important;
        padding: var(--dd-pill-padding) !important;
        font-size: 14px;
        outline: none;
        background: var(--dd-pill-bg) !important;
        color: var(--dd-text-color) !important;
    }

    .dd-pill-input:focus {
        border-color: var(--accent, #4ade80) !important;
    }

    .dd-pill-select {
        min-width: 200px;
    }

    .dd-pill-btn {
        border-radius: var(--dd-pill-radius) !important;
        padding: 8px 16px !important;
    }

    /* Password modal */
    .dd-password-modal {
        position: fixed;
        inset: 0;
        z-index: 999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .dd-password-backdrop {
        position: absolute;
        inset: 0;
        background: var(--dd-overlay-bg);
    }

    .dd-password-panel {
        position: relative;
        z-index: 1000;
        max-width: 420px;
        width: 100%;
        border-radius: var(--dd-card-radius);
        padding: 20px;
        background: var(--dd-card-bg);
        border: 1px solid var(--dd-card-border);
    }

    .dd-password-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 10px;
    }

    .dd-password-input-wrapper {
        position: relative;
        margin-bottom: 10px;
    }

    .dd-password-input {
        width: 100%;
        padding-right: 44px !important;
    }

    .dd-password-toggle {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 16px;
    }

    .dd-password-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    .dd-password-modal.dd-hidden {
    display: none !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Slide-down details toggles (click anywhere on row except controls)
    document.querySelectorAll('.dd-service-row').forEach(function (row) {
        const id = row.getAttribute('data-service-id');
        const detailsRow = document.getElementById('service-details-' + id);
        const detailsWrapper = detailsRow
            ? detailsRow.querySelector('[data-details-for="' + id + '"]')
            : null;
        const assignModal  = document.getElementById('dd-assign-modal');
        const assignForm   = document.getElementById('dd-assign-form');
        const assignSelect = document.getElementById('dd-assign-select');
        const domainModal  = document.getElementById('dd-domain-modal');
        const domainForm   = document.getElementById('dd-domain-form');
        const domainInput  = document.getElementById('dd-domain-input');
        
        if (!detailsRow || !detailsWrapper) return;

        let loaded = false;

        function loadDetails() {
            if (loaded) return;

            fetch('{{ url('admin/services/hosting') }}/' + id + '/details', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(r => r.json())
            .then(data => {
                loaded = true;

                detailsWrapper.querySelector('.dd-detail-plan').textContent =
                    data.plan || '–';
                detailsWrapper.querySelector('.dd-detail-server').textContent =
                    data.server || '–';
                detailsWrapper.querySelector('.dd-detail-status').textContent =
                    data.status || '–';
                detailsWrapper.querySelector('.dd-detail-ip').textContent =
                    data.ip || '–';

                const disk = (data.disk_usage || data.disk_limit)
                    ? ((data.disk_usage || '?') + ' / ' + (data.disk_limit || '?') + ' MB')
                    : '–';
                detailsWrapper.querySelector('.dd-detail-disk').textContent = disk;

                detailsWrapper.querySelector('.dd-detail-username').textContent =
                    data.username || '–';
            })
            .catch(() => { /* ignore */ });
        }

        row.addEventListener('click', function (e) {
            // Don't toggle when clicking buttons, links, inputs, selects etc.
            if (e.target.closest('button, form, a, select, input, label')) {
                return;
            }

            const currentlyHidden = detailsRow.classList.contains('dd-hidden');
            if (currentlyHidden) {
                loadDetails();
                detailsRow.classList.remove('dd-hidden');
            } else {
                detailsRow.classList.add('dd-hidden');
            }
        });
    });

    // Password modal logic
    const passwordModal  = document.getElementById('dd-password-modal');
    const passwordInput  = document.getElementById('dd-password-input');
    const passwordToggle = document.getElementById('dd-password-toggle');
    const passwordCopy   = document.getElementById('dd-password-copy');

    function openPasswordModal(password) {
        if (passwordInput) {
            passwordInput.value = password || 'Unavailable';
            passwordInput.type = 'password';
        }
        if (passwordToggle) {
            passwordToggle.textContent = '👁';
        }
        passwordModal.classList.remove('dd-hidden');
        passwordModal.setAttribute('aria-hidden', 'false');
    }

    function closePasswordModal() {
        passwordModal.classList.add('dd-hidden');
        passwordModal.setAttribute('aria-hidden', 'true');
        if (passwordInput) {
            passwordInput.value = '••••••••';
            passwordInput.type  = 'password';
        }
    }

    // Open modal on "Show password" buttons
    document.querySelectorAll('.dd-password-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // don't toggle row

            const id = btn.getAttribute('data-password-id');

            fetch('{{ url('admin/services/hosting') }}/' + id + '/password', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    openPasswordModal(data.password);
                } else {
                    openPasswordModal(data.message || 'Unavailable');
                }
            })
            .catch(() => openPasswordModal('Unavailable'));
        });
    });

    // Close modal
    document.querySelectorAll('[data-dd-password-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            closePasswordModal();
        });
    });

    // Toggle password visibility with eye icon
    if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const visible = passwordInput.type === 'text';
            passwordInput.type = visible ? 'password' : 'text';
            passwordToggle.textContent = visible ? '👁' : '👁';
        });
    }

    // Copy to clipboard
    if (passwordCopy && passwordInput) {
        passwordCopy.addEventListener('click', function () {
            const text = passwordInput.value || '';
            if (!navigator.clipboard) {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
            } else {
                navigator.clipboard.writeText(text).catch(() => {});
            }
        });
    }

    // Assign client modal open/close
if (assignModal && assignForm && assignSelect) {
    document.querySelectorAll('.dd-assign-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // don't toggle row

            const url = btn.getAttribute('data-assign-url');
            const currentClientId = btn.getAttribute('data-current-client-id') || '';

            assignForm.action = url || '';
            assignSelect.value = currentClientId;

            assignModal.classList.remove('dd-hidden');
            assignModal.setAttribute('aria-hidden', 'false');
        });
    });

    document.querySelectorAll('[data-dd-assign-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            assignModal.classList.add('dd-hidden');
            assignModal.setAttribute('aria-hidden', 'true');
        });
    });
}

// Change domain modal open/close
if (domainModal && domainForm && domainInput) {
    document.querySelectorAll('.dd-change-domain-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation(); // don't toggle row

            const url = btn.getAttribute('data-change-url');
            const domain = btn.getAttribute('data-domain') || '';

            domainForm.action = url || '';
            domainInput.value = domain;

            domainModal.classList.remove('dd-hidden');
            domainModal.setAttribute('aria-hidden', 'false');
        });
    });

    document.querySelectorAll('[data-dd-domain-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            domainModal.classList.add('dd-hidden');
            domainModal.setAttribute('aria-hidden', 'true');
        });
    });
}

    // ESC closes modal
    document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        // Close password modal
        if (passwordModal && !passwordModal.classList.contains('dd-hidden')) {
            closePasswordModal();
        }
        // Close assign modal
        if (assignModal && !assignModal.classList.contains('dd-hidden')) {
            assignModal.classList.add('dd-hidden');
            assignModal.setAttribute('aria-hidden', 'true');
        }
        // Close domain modal
        if (domainModal && !domainModal.classList.contains('dd-hidden')) {
            domainModal.classList.add('dd-hidden');
            domainModal.setAttribute('aria-hidden', 'true');
        }
    }
});
    
});
</script>
@endsection
