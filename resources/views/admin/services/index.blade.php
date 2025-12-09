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

            <select name="status"
                    class="dd-pill-input dd-pill-select">
                <option value="">All statuses</option>
                <option value="ACTIVE" {{ (isset($statusFilter) && $statusFilter === 'ACTIVE') ? 'selected' : '' }}>Active</option>
                <option value="SUSPENDED" {{ (isset($statusFilter) && $statusFilter === 'SUSPENDED') ? 'selected' : '' }}>Suspended</option>
                <option value="TERMINATED" {{ (isset($statusFilter) && $statusFilter === 'TERMINATED') ? 'selected' : '' }}>Terminated</option>
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
                🔄 Sync from Synergy
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
                <tr data-service-panel="service-{{ $service->id }}" class="dd-service-panel">
                    <td colspan="6">
                        <div class="dd-service-panel-inner" data-details-for="{{ $service->id }}">
                            {{-- Service info header --}}
                            <div class="dd-service-panel-header">
                                <div>
                                    <div style="font-weight:600;">{{ $domainLabel }}</div>
                                    <div style="font-size:13px;opacity:.8;">
                                        <span class="dd-detail-plan">{{ $service->plan ?? 'Plan unknown' }}</span>
                                        • Username: <span class="dd-detail-username">{{ $service->username ?? '—' }}</span>
                                    </div>
                                </div>
                                <div style="font-size:13px;opacity:.7;">
                                    Server: <span class="dd-detail-server">{{ $service->server ?? '—' }}</span>
                                </div>
                            </div>

                            {{-- Actions grid --}}
                            <div class="dd-service-options-grid">
                                {{-- Overview --}}
                                <a href="{{ route('admin.services.hosting.show', $service) }}" class="dd-service-option">
                                    <div class="dd-service-option-icon">👁️</div>
                                    <div class="dd-service-option-label">Overview</div>
                                </a>

                                {{-- Show password --}}
                                <button type="button"
                                        class="dd-service-option dd-password-btn"
                                        data-password-id="{{ $service->id }}">
                                    <div class="dd-service-option-icon">🔐</div>
                                    <div class="dd-service-option-label">Show password</div>
                                </button>

                                {{-- Open cPanel --}}
                                <form method="POST"
                                      action="{{ route('admin.services.hosting.login', $service) }}"
                                      class="dd-service-option"
                                      target="_blank">
                                    @csrf
                                    <button type="submit" class="dd-service-option-btn">
                                        <div class="dd-service-option-icon">💻</div>
                                        <div class="dd-service-option-label">Open cPanel</div>
                                    </button>
                                </form>

                                {{-- Assign client --}}
                                <button type="button"
                                        class="dd-service-option dd-assign-btn"
                                        data-service-id="{{ $service->id }}"
                                        data-assign-url="{{ route('admin.services.hosting.assignClient', $service) }}"
                                        data-current-client-id="{{ $service->client_id ?? '' }}">
                                    <div class="dd-service-option-icon">👥</div>
                                    <div class="dd-service-option-label">Assign client</div>
                                </button>

                                {{-- Change primary domain --}}
                                <button type="button"
                                        class="dd-service-option dd-change-domain-btn"
                                        data-service-id="{{ $service->id }}"
                                        data-change-url="{{ route('admin.services.hosting.changeDomain', $service) }}"
                                        data-domain="{{ $service->domain_name }}">
                                    <div class="dd-service-option-icon">🌐</div>
                                    <div class="dd-service-option-label">Change primary domain</div>
                                </button>

                                {{-- Suspend / Unsuspend --}}
                                <form method="POST"
                                      action="{{ route('admin.services.hosting.suspend', $service) }}"
                                      class="dd-service-option dd-service-option-danger">
                                    @csrf
                                    <button type="submit" class="dd-service-option-btn">
                                        <div class="dd-service-option-icon">
                                            @if(property_exists($service, 'is_suspended') && $service->is_suspended)
                                                ▶️
                                            @else
                                                ⏸️
                                            @endif
                                        </div>
                                        <div class="dd-service-option-label">
                                            @if(property_exists($service, 'is_suspended') && $service->is_suspended)
                                                Unsuspend
                                            @else
                                                Suspend
                                            @endif
                                        </div>
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
        --dd-hover-bg: rgba(148,163,184,0.12);
        --dd-overlay-bg: rgba(15,23,42,0.65);
    }

    body.dark-mode,
    body[data-theme="dark"],
    html.dark,
    html[data-theme="dark"] {
        --dd-card-bg: #020617;
        --dd-card-border: #1f2937;
        --dd-pill-bg: #0f172a;
        --dd-pill-border: #374151;
        --dd-text-color: #e5e7eb;
        --dd-header-bg: #020617;
        --dd-header-text: #f9fafb;
        --dd-row-alt-bg: #111827;
        --dd-hover-bg: rgba(148,163,184,0.18);
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

    /* Expandable panel with smooth transitions */
    tr[data-service-panel] {
        display: none;
        height: 0;
    }
    tr[data-service-panel] > td {
        padding: 0;
        border: 0;
    }
    tr[data-service-panel].open {
        display: table-row;
        height: auto;
    }

    .dd-service-panel-inner {
        max-height: 0;
        padding: 0;
        margin-top: 0;
        border: 0;
        overflow: hidden;
        opacity: 0;
        transform: translateY(-4px);
        transition:
            max-height 0.25s ease,
            opacity 0.2s ease,
            transform 0.2s ease,
            padding 0.2s ease,
            margin-top 0.2s ease,
            border-width 0.2s ease;
    }

    tr[data-service-panel].open > td > .dd-service-panel-inner {
        max-height: 600px;
        opacity: 1;
        transform: translateY(0);
        padding: 16px 18px 18px;
        margin-top: 0;
        border-radius: 8px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
    }

    .dd-service-panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        border-radius: 6px;
        background: var(--dd-header-bg);
        border: 1px solid var(--dd-card-border);
        margin-bottom: 14px;
    }

    .dd-service-options-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 10px;
    }

    .dd-service-option,
    .dd-service-option-btn {
        display: flex;
        align-items: center;
        padding: 10px 14px;
        border-radius: 9999px;
        background: var(--dd-pill-bg);
        border: 1px solid var(--dd-pill-border);
        text-decoration: none;
        font-size: 14px;
        cursor: pointer;
        width: 100%;
        color: var(--dd-text-color);
        transition: background 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
    }

    .dd-service-option:hover,
    .dd-service-option-btn:hover {
        background: var(--dd-hover-bg, rgba(148,163,184,0.18));
        border-color: var(--accent);
        transform: translateY(-1px);
    }

    .dd-service-option-btn {
        background: transparent;
        color: inherit;
    }

    .dd-service-option-icon {
        width: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 8px;
        font-size: 16px;
    }

    .dd-service-option-label {
        flex: 1;
        text-align: left;
    }

    .dd-service-option-danger {
        border-color: #ef4444;
    }

    .dd-service-option-danger:hover {
        background: rgba(239, 68, 68, 0.1);
        border-color: #dc2626;
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
        color: var(--dd-text-color);
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
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 18px;
        color: var(--dd-text-color);
        opacity: 0.7;
        padding: 4px 8px;
        border-radius: 4px;
        transition: opacity 0.2s ease, background-color 0.2s ease;
    }

    .dd-password-toggle:hover {
        opacity: 1;
        background: var(--dd-pill-bg);
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
    // Modal elements (must be outside forEach to be accessible later)
    const assignModal  = document.getElementById('dd-assign-modal');
    const assignForm   = document.getElementById('dd-assign-form');
    const assignSelect = document.getElementById('dd-assign-select');
    const domainModal  = document.getElementById('dd-domain-modal');
    const domainForm   = document.getElementById('dd-domain-form');
    const domainInput  = document.getElementById('dd-domain-input');

    // Slide-down details toggles (click anywhere on row except controls)
    document.querySelectorAll('.dd-service-row').forEach(function (row) {
        const id = row.getAttribute('data-service-id');
        const panelId = 'service-' + id;
        const detailsRow = document.querySelector('[data-service-panel="' + panelId + '"]');
        const detailsWrapper = detailsRow
            ? detailsRow.querySelector('[data-details-for="' + id + '"]')
            : null;

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

                // Update header info
                const planSpan = detailsWrapper.querySelector('.dd-detail-plan');
                if (planSpan) planSpan.textContent = data.plan || 'Plan unknown';

                const serverSpan = detailsWrapper.querySelector('.dd-detail-server');
                if (serverSpan) serverSpan.textContent = data.server || '—';

                const usernameSpan = detailsWrapper.querySelector('.dd-detail-username');
                if (usernameSpan) usernameSpan.textContent = data.username || '—';
            })
            .catch(() => { /* ignore */ });
        }

        row.addEventListener('click', function (e) {
            // Don't toggle when clicking buttons, links, inputs, selects etc.
            if (e.target.closest('button, form, a, select, input, label')) {
                return;
            }

            const isOpen = detailsRow.classList.contains('open');
            if (!isOpen) {
                loadDetails();
                detailsRow.classList.add('open');
            } else {
                detailsRow.classList.remove('open');
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
