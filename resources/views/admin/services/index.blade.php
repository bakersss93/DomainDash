@extends('layouts.app')

@section('content')
<div id="hosting-sync-loading-overlay" style="position:fixed;inset:0;background:var(--dd-backdrop);backdrop-filter:blur(2px);z-index:12000;display:none;align-items:center;justify-content:center;padding:20px;">
    <div style="min-width:220px;background:var(--dd-surface);border:1px solid var(--dd-border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--dd-text);">
        <span style="width:18px;height:18px;border:2px solid color-mix(in srgb,var(--dd-text) 30%,transparent);border-top-color:var(--dd-text);border-radius:999px;display:inline-block;animation:hosting-sync-spin 0.7s linear infinite;"></span>
        <span>Syncing hosting services...</span>
    </div>
</div>
<div class="ui-page">
    <x-ui.page-header title="Hosting Services" subtitle="Web hosting accounts, plans and client assignment.">
        <x-slot name="actions">
            <form method="POST" action="{{ route('admin.services.hosting.sync') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    <x-ui.icon name="refresh" :size="16" /> Sync from Synergy
                </x-ui.button>
            </form>
        </x-slot>
    </x-ui.page-header>

    {{-- Filters --}}
    <div class="ui-toolbar">
        <form method="GET" action="{{ route('admin.services.hosting') }}" class="flex flex-1 flex-wrap items-center gap-2">
            <label for="hosting-client-filter" class="sr-only">Filter by client</label>
            <select id="hosting-client-filter" name="client_id" class="dd-input" style="flex:1;min-width:12rem;max-width:20rem;">
                <option value="">All clients</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}"
                        {{ (isset($clientId) && (int)$clientId === $client->id) ? 'selected' : '' }}>
                        {{ $client->business_name ?? $client->name ?? ('Client #' . $client->id) }}
                    </option>
                @endforeach
            </select>

            <label for="hosting-status-filter" class="sr-only">Filter by status</label>
            <select id="hosting-status-filter" name="status" class="dd-input" style="flex:0 1 12rem;">
                <option value="">All statuses</option>
                <option value="ACTIVE" {{ (isset($statusFilter) && $statusFilter === 'ACTIVE') ? 'selected' : '' }}>Active</option>
                <option value="SUSPENDED" {{ (isset($statusFilter) && $statusFilter === 'SUSPENDED') ? 'selected' : '' }}>Suspended</option>
                <option value="TERMINATED" {{ (isset($statusFilter) && $statusFilter === 'TERMINATED') ? 'selected' : '' }}>Terminated</option>
            </select>

            <button type="submit" class="ui-btn ui-btn--primary">Filter</button>
        </form>
    </div>

    {{-- Services table --}}
    <x-ui.card :flush="true">
        <x-ui.table>
            <thead>
            <tr>
                <th>Domain</th>
                <th>Plan</th>
                <th>Username</th>
                <th>Client</th>
                <th>Status</th>
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
                <tr class="dd-service-row" data-service-id="{{ $service->id }}" style="cursor:pointer;">
                    <td class="dd-service-domain-cell">
                        <span class="dd-service-domain-text">
                            {{ $domainLabel }}
                        </span>
                    </td>
                    <td>{{ $service->plan ?? '-' }}</td>
                    <td>{{ $service->username ?? '-' }}</td>
                    <td>{{ $clientLabel }}</td>
                    <td>@if(! empty($service->service_status))<x-ui.badge :status="$service->service_status">{{ ucfirst(strtolower($service->service_status)) }}</x-ui.badge>@else — @endif</td>
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
                <x-ui.row-detail data-service-panel="service-{{ $service->id }}" :title="$domainLabel" :details-for="$service->id" :colspan="7">
                    <x-slot:meta>
                        <span class="dd-detail-plan">{{ $service->plan ?? 'Plan unknown' }}</span>
                        • Username: <span class="dd-detail-username">{{ $service->username ?? '—' }}</span>
                    </x-slot:meta>
                    <x-slot:aside>Server: <span class="dd-detail-server">{{ $service->server ?? '—' }}</span></x-slot:aside>

                    <x-ui.action-grid label="Hosting actions">
                        <x-ui.action-tile :href="route('admin.services.hosting.show', $service)" icon="eye">Overview</x-ui.action-tile>
                        <x-ui.action-tile icon="lock" class="dd-password-btn" data-password-id="{{ $service->id }}">Show password</x-ui.action-tile>
                        <x-ui.action-tile :action="route('admin.services.hosting.login', $service)" target="_blank" icon="monitor">Open cPanel</x-ui.action-tile>
                        <x-ui.action-tile icon="users" class="dd-assign-btn"
                                          data-service-id="{{ $service->id }}"
                                          data-assign-url="{{ route('admin.services.hosting.assignClient', $service) }}"
                                          data-current-client-id="{{ $service->client_id ?? '' }}">Assign client</x-ui.action-tile>
                        <x-ui.action-tile icon="globe" class="dd-change-domain-btn"
                                          data-service-id="{{ $service->id }}"
                                          data-change-url="{{ route('admin.services.hosting.changeDomain', $service) }}"
                                          data-domain="{{ $service->domain_name }}">Change primary domain</x-ui.action-tile>
                        @php $isSuspended = property_exists($service, 'is_suspended') && $service->is_suspended; @endphp
                        <x-ui.action-tile :action="route('admin.services.hosting.suspend', $service)" variant="danger" :icon="$isSuspended ? 'play' : 'pause'">{{ $isSuspended ? 'Unsuspend' : 'Suspend' }}</x-ui.action-tile>
                    </x-ui.action-grid>
                </x-ui.row-detail>
            @empty
                <tr>
                    <td colspan="7" class="ui-muted" style="text-align:center;padding:1.5rem 0;">
                        No hosting services found. Try syncing from Synergy.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </x-ui.table>

        @if ($services->hasPages())
            <div class="px-4 py-3">{{ $services->links() }}</div>
        @endif
    </x-ui.card>
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
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
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
    .dd-hidden {
        display: none;
    }

    .dd-service-form {
        border-radius: 12px;
        padding: 10px;
        border: 1px solid var(--border-subtle);
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .dd-service-form-label {
        font-size: 12px;
        font-weight: 500;
        margin-bottom: 2px;
        display: block;
        color: var(--text);
    }

    .dd-pill-input {
        border-radius: 12px !important;
        border: 1px solid var(--border-subtle) !important;
        padding: 8px 14px !important;
        font-size: 14px;
        outline: none;
        background: var(--surface-elevated) !important;
        color: var(--text) !important;
    }

    .dd-pill-input:focus {
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 28%, transparent) !important;
    }

    .dd-pill-select {
        min-width: 200px;
    }

    .dd-pill-btn {
        border-radius: 12px !important;
        padding: 8px 16px !important;
    }

    /* Modals */
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
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(2px);
    }

    .dd-password-panel {
        position: relative;
        z-index: 1000;
        max-width: 420px;
        width: 100%;
        border-radius: 18px;
        padding: 20px;
        background: var(--surface-elevated);
        border: 1px solid var(--border-subtle);
        box-shadow: 0 24px 48px rgba(15, 23, 42, 0.35);
    }

    .dd-password-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 10px;
        color: var(--text);
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
        color: var(--text);
        opacity: 0.7;
        padding: 4px 8px;
        border-radius: 4px;
        transition: opacity 0.2s ease, background-color 0.2s ease;
    }

    .dd-password-toggle:hover {
        opacity: 1;
        background: var(--surface-muted);
    }

    .dd-password-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    .dd-password-modal.dd-hidden {
        display: none !important;
    }

    @keyframes hosting-sync-spin {
        to { transform: rotate(360deg); }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var hostingSyncForm = document.querySelector('.dd-services-sync');
    var hostingSyncOverlay = document.getElementById('hosting-sync-loading-overlay');
    if (hostingSyncForm && hostingSyncOverlay) {
        hostingSyncForm.addEventListener('submit', function () {
            hostingSyncOverlay.style.display = 'flex';
        });
    }

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
            passwordToggle.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
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
            passwordToggle.innerHTML = visible
                ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
                : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
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
