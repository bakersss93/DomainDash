@extends('layouts.app')

@section('content')
<div style="max-width: 1200px; margin: 0 auto;">
    <div class="dd-clients-card">
        <h1 class="dd-clients-title">Clients</h1>

        {{-- Header / actions --}}
        <div class="dd-clients-toolbar">
            <div class="dd-clients-actions">
                <a href="{{ route('admin.clients.create') }}" class="btn-accent dd-pill-btn">
                    New client
                </a>

                <button type="button" id="btn-halo-import" class="btn-accent dd-pill-btn">
                    + HaloPSA
                </button>
            </div>
        </div>

        {{-- Clients table --}}
        <div class="dd-clients-table-wrapper">
            <table class="dd-clients-table">
                <thead>
                    <tr>
                        <th style="width:30px;"></th>
                        <th style="text-align:left;">Business Name</th>
                        <th style="text-align:left;">ABN</th>
                        <th style="text-align:center;">HaloPSA</th>
                        <th style="text-align:center;">ITGlue</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr class="dd-client-row" data-client-id="{{ $client->id }}">
                            <td>
                                <span class="expand-icon">▶</span>
                            </td>
                            <td>
                                <strong>{{ $client->business_name }}</strong>
                            </td>
                            <td>
                                {{ $client->abn }}
                            </td>
                            <td style="text-align:center;">
                                @if($client->halopsa_reference)
                                    <span class="dd-status-success">✓</span>
                                @else
                                    <span class="dd-status-muted">—</span>
                                @endif
                            </td>
                            <td style="text-align:center;">
                                @if($client->itglue_org_id)
                                    <span class="dd-status-info">✓</span>
                                @else
                                    <span class="dd-status-muted">—</span>
                                @endif
                            </td>
                            <td style="text-align:center;">
                                <span class="{{ $client->active ? 'dd-status-success' : 'dd-status-muted' }}">
                                    {{ $client->active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <a href="{{ route('admin.clients.edit', $client) }}"
                                   onclick="event.stopPropagation();"
                                   class="dd-edit-btn">
                                    Edit
                                </a>
                            </td>
                        </tr>

                        {{-- Expandable details row --}}
                        <tr class="dd-client-panel" data-client-panel="client-{{ $client->id }}">
                            <td colspan="7">
                                <div class="dd-client-panel-inner">
                                    <div class="dd-client-panel-header">
                                        <div>
                                            <div style="font-weight:600;">{{ $client->business_name }}</div>
                                            <div style="font-size:13px;opacity:.8;">
                                                {{ $client->abn ? 'ABN: ' . $client->abn : 'No ABN' }}
                                                • {{ $client->active ? 'Active' : 'Inactive' }}
                                            </div>
                                        </div>
                                        <div style="font-size:13px;opacity:.7;">
                                            {{ $client->domains()->count() }} domains • {{ $client->users()->count() }} users
                                        </div>
                                    </div>

                                    <div class="dd-client-options-grid">
                                        {{-- Client Information Section --}}
                                        <div class="dd-client-info-section">
                                            <h4 class="dd-section-title">Integrations</h4>
                                            @if($client->halopsa_reference)
                                                <div class="dd-info-item">
                                                    <strong>HaloPSA Ref:</strong> {{ $client->halopsa_reference }}
                                                </div>
                                            @endif
                                            @if($client->itglue_org_name)
                                                <div class="dd-info-item">
                                                    <strong>ITGlue Org:</strong> {{ $client->itglue_org_name }} ({{ $client->itglue_org_id }})
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Actions Section --}}
                                        <div class="dd-client-actions-section">
                                            <h4 class="dd-section-title">Actions</h4>

                                            {{-- ITGlue Sync --}}
                                            @if($client->itglue_org_id)
                                                <button type="button"
                                                        onclick="syncClientToItglue({{ $client->id }}, event)"
                                                        class="btn-accent dd-pill-btn dd-sync-btn">
                                                    📘 Sync Domains to ITGlue
                                                </button>
                                                <div id="itglue-status-{{ $client->id }}" class="dd-sync-status"></div>
                                            @else
                                                <div class="dd-status-muted dd-no-integration">
                                                    Link ITGlue organization first
                                                </div>
                                            @endif

                                            {{-- HaloPSA DNS Sync --}}
                                            @if($client->halopsa_reference)
                                                @php
                                                    $domainsWithAssets = $client->domains()->whereNotNull('halo_asset_id')->count();
                                                @endphp
                                                @if($domainsWithAssets > 0)
                                                    <button type="button"
                                                            onclick="syncClientDnsToHalo({{ $client->id }}, event)"
                                                            class="btn-accent dd-pill-btn dd-sync-btn">
                                                        🔧 Sync DNS to HaloPSA ({{ $domainsWithAssets }})
                                                    </button>
                                                    <div id="halo-status-{{ $client->id }}" class="dd-sync-status"></div>
                                                @else
                                                    <div class="dd-status-muted dd-no-integration">
                                                        No domains with HaloPSA assets
                                                    </div>
                                                @endif
                                            @else
                                                <div class="dd-status-muted dd-no-integration">
                                                    Import from HaloPSA first
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="dd-empty-state">
                                No clients found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="dd-clients-pagination">
            {{ $clients->links() }}
        </div>
    </div>
</div>

{{-- Halo import modal --}}
<div id="halo-import-backdrop" class="dd-modal">
    <div class="dd-modal-backdrop"></div>
    <div class="dd-modal-dialog dd-modal-wide">
        <h2 class="dd-modal-title">Import clients from HaloPSA</h2>

        <p class="dd-modal-description">
            Select one or more Halo clients to import. Matching domain assets will be linked automatically.
        </p>

        <div id="halo-import-loading" class="dd-loading-text">
            Loading clients from HaloPSA…
        </div>

        <div class="dd-modal-table-wrapper">
            <table class="dd-modal-table">
                <thead>
                    <tr>
                        <th style="width:40px;text-align:center;">&nbsp;</th>
                        <th style="text-align:left;">Name</th>
                        <th style="text-align:left;">Reference</th>
                    </tr>
                </thead>
                <tbody id="halo-import-tbody"></tbody>
            </table>
        </div>

        <div id="halo-import-empty" class="dd-hidden dd-status-muted dd-modal-message">
            No clients found from HaloPSA.
        </div>

        <div id="halo-import-error" class="dd-hidden dd-error-text dd-modal-message">
            Failed to load clients from HaloPSA. Please check API settings.
        </div>

        <div class="dd-modal-actions">
            <button type="button" id="halo-import-cancel" class="dd-modal-btn-secondary">
                Cancel
            </button>
            <button type="button" id="halo-import-submit" class="btn-accent dd-pill-btn">
                Import selected
            </button>
        </div>
    </div>
</div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        console.log('Client list loaded');
        
        // Get CSRF token
        let csrf = '';
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            csrf = csrfMeta.getAttribute('content');
        }
        if (!csrf) {
            csrf = '{{ csrf_token() }}';
        }
        
        // Expandable rows
        document.querySelectorAll('.dd-client-row').forEach(row => {
            row.addEventListener('click', function(e) {
                // Don't expand when clicking buttons, links, or form elements
                if (e.target.closest('a, button, form, input, select, textarea')) return;

                const clientId = this.dataset.clientId;
                const panelId = 'client-' + clientId;
                const detailsRow = document.querySelector('[data-client-panel="' + panelId + '"]');
                const icon = this.querySelector('.expand-icon');

                if (!detailsRow) return;

                const isOpen = detailsRow.classList.contains('open');
                if (isOpen) {
                    detailsRow.classList.remove('open');
                    icon.style.transform = 'rotate(0deg)';
                } else {
                    detailsRow.classList.add('open');
                    icon.style.transform = 'rotate(90deg)';
                }
            });
        });
        
        // HaloPSA import modal functionality
        const openBtn = document.getElementById('btn-halo-import');
        const modal = document.getElementById('halo-import-backdrop');
        const cancelBtn = document.getElementById('halo-import-cancel');
        const confirmBtn = document.getElementById('halo-import-submit');
        const tbody = document.getElementById('halo-import-tbody');
        const errorBox = document.getElementById('halo-import-error');
        const loadingEl = document.getElementById('halo-import-loading');
        const emptyEl = document.getElementById('halo-import-empty');

        function showModal() {
            modal.classList.remove('dd-hidden');
            modal.style.display = 'flex';
            loadHaloClients();
        }

        function hideModal() {
            modal.classList.add('dd-hidden');
            modal.style.display = 'none';
        }

        async function loadHaloClients() {
            tbody.innerHTML = '';
            if (errorBox) errorBox.classList.add('dd-hidden');
            if (emptyEl) emptyEl.classList.add('dd-hidden');
            if (loadingEl) loadingEl.classList.remove('dd-hidden');

            try {
                const res = await fetch('{{ route("admin.clients.haloClients") }}', {
                    headers: { 'Accept': 'application/json' }
                });

                const data = await res.json().catch(() => []);

                if (!res.ok) {
                    if (errorBox) {
                        errorBox.classList.remove('dd-hidden');
                        errorBox.textContent = data.error || 'Failed to load clients';
                    }
                    return;
                }

                if (!Array.isArray(data) || data.length === 0) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="3" class="dd-empty-state">No clients available to import.</td>';
                    tbody.appendChild(tr);
                    if (emptyEl) emptyEl.classList.remove('dd-hidden');
                    return;
                }

                data.forEach(function (client) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="padding:6px 8px;text-align:center;">
                            <input type="checkbox" class="halo-client-checkbox" value="${client.id}">
                        </td>
                        <td style="padding:6px 8px;">${client.name || ''}</td>
                        <td style="padding:6px 8px;">${client.reference || ''}</td>
                    `;
                    tbody.appendChild(tr);
                });
            } catch (e) {
                console.error('Load error', e);
                if (errorBox) {
                    errorBox.classList.remove('dd-hidden');
                    errorBox.textContent = 'Failed to load clients';
                }
            } finally {
                if (loadingEl) loadingEl.classList.add('dd-hidden');
            }
        }

        async function importSelected() {
            const checkboxes = tbody.querySelectorAll('.halo-client-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));

            if (ids.length === 0) {
                alert('Please select at least one client');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Importing...';

            try {
                const res = await fetch('{{ route("admin.clients.importHalo") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({ client_ids: ids })
                });

                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    alert('Import failed: ' + (data.error || 'Please check logs'));
                    return;
                }

                const imported = data.imported ?? 0;
                const linked = data.domains_linked ?? 0;
                alert(`Imported ${imported} client(s), linked ${linked} domain(s).`);

                hideModal();
                window.location.reload();
            } catch (e) {
                console.error('Import error', e);
                alert('Import failed. Check console and logs.');
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Import selected';
            }
        }

        if (openBtn) openBtn.addEventListener('click', showModal);
        if (cancelBtn) cancelBtn.addEventListener('click', hideModal);
        if (confirmBtn) confirmBtn.addEventListener('click', importSelected);
    });
    
    // Sync functions
    function syncClientToItglue(clientId, event) {
        event.stopPropagation();
        
        if (!confirm('Sync all domains to ITGlue with DNS records from Synergy?')) {
            return;
        }

        const statusDiv = document.getElementById(`itglue-status-${clientId}`);
        statusDiv.innerHTML = '<span style="color:#9ca3af;">⏳ Syncing...</span>';

        fetch(`/admin/clients/${clientId}/itglue/sync-domains`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        })
        .then(async r => {
            if (!r.ok) {
                const text = await r.text();
                console.error('ITGlue sync failed:', text);
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            if (data.success) {
                statusDiv.innerHTML = `<span style="color:#34d399;">✓ ${data.message}</span>`;
            } else {
                statusDiv.innerHTML = `<span style="color:#f87171;">✗ ${data.error || data.message}</span>`;
            }
        })
        .catch(err => {
            console.error('Sync error:', err);
            statusDiv.innerHTML = '<span style="color:#f87171;">✗ Sync failed</span>';
        });
    }
    
    function syncClientDnsToHalo(clientId, event) {
        event.stopPropagation();
        
        if (!confirm('Sync DNS records to HaloPSA asset notes?')) {
            return;
        }

        const statusDiv = document.getElementById(`halo-status-${clientId}`);
        statusDiv.innerHTML = '<span style="color:#9ca3af;">⏳ Syncing...</span>';

        fetch(`/admin/clients/${clientId}/halo/sync-dns`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        })
        .then(async r => {
            if (!r.ok) {
                const text = await r.text();
                console.error('HaloPSA sync failed:', text);
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            if (data.success) {
                statusDiv.innerHTML = `<span style="color:#34d399;">✓ ${data.message}</span>`;
            } else {
                statusDiv.innerHTML = `<span style="color:#f87171;">✗ ${data.error || data.message}</span>`;
            }
        })
        .catch(err => {
            console.error('Sync error:', err);
            statusDiv.innerHTML = '<span style="color:#f87171;">✗ Sync failed</span>';
        });
    }
    </script>

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
        display: none !important;
    }

    /* Card styling */
    .dd-clients-card {
        border-radius: var(--dd-card-radius);
        padding: var(--dd-card-padding);
        margin-top: 24px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
        color: var(--dd-text-color);
    }

    .dd-clients-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 12px;
    }

    /* Toolbar */
    .dd-clients-toolbar {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .dd-clients-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    /* Table styling */
    .dd-clients-table-wrapper {
        overflow-x: auto;
    }

    .dd-clients-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .dd-clients-table thead tr {
        background: var(--dd-header-bg);
        color: var(--dd-header-text);
    }

    .dd-clients-table th,
    .dd-clients-table td {
        padding: 8px 10px;
        text-align: left;
        border-bottom: 1px solid rgba(148,163,184,0.4);
    }

    .dd-clients-table tbody tr:nth-child(4n+1),
    .dd-clients-table tbody tr:nth-child(4n+2) {
        background: var(--dd-row-alt-bg);
    }

    .dd-clients-pagination {
        margin-top: 10px;
    }

    /* Client rows */
    .dd-client-row {
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .dd-client-row:hover {
        background-color: var(--dd-hover-bg) !important;
    }

    .expand-icon {
        transition: transform 0.2s ease;
        display: inline-block;
    }

    /* Status badges */
    .dd-status-success {
        color: #34d399;
    }

    .dd-status-info {
        color: #60a5fa;
    }

    .dd-status-muted {
        color: #6b7280;
    }

    .dd-error-text {
        color: #f87171;
    }

    /* Edit button */
    .dd-edit-btn {
        padding: 6px 12px;
        border-radius: var(--dd-pill-radius);
        border: 1px solid var(--dd-pill-border);
        background: var(--dd-pill-bg);
        font-size: 13px;
        text-decoration: none;
        color: var(--dd-text-color);
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .dd-edit-btn:hover {
        background: var(--dd-hover-bg);
        border-color: var(--accent, #4ade80);
    }

    /* Expandable panel */
    tr[data-client-panel] {
        display: none;
        height: 0;
    }

    tr[data-client-panel] > td {
        padding: 0;
        border: 0;
    }

    tr[data-client-panel].open {
        display: table-row;
        height: auto;
    }

    .dd-client-panel-inner {
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

    tr[data-client-panel].open > td > .dd-client-panel-inner {
        max-height: 600px;
        opacity: 1;
        transform: translateY(0);
        padding: 16px 18px 18px;
        margin-top: 0;
        border-radius: 8px;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
    }

    .dd-client-panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        border-radius: 6px;
        background: var(--dd-header-bg);
        border: 1px solid var(--dd-card-border);
        margin-bottom: 14px;
    }

    .dd-client-options-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .dd-section-title {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #9ca3af;
    }

    .dd-info-item {
        font-size: 13px;
        color: var(--dd-text-color);
        margin-bottom: 6px;
    }

    .dd-client-info-section,
    .dd-client-actions-section {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .dd-sync-btn {
        font-size: 13px;
        width: 100%;
    }

    .dd-sync-status {
        margin-top: 4px;
        font-size: 12px;
    }

    .dd-no-integration {
        font-size: 13px;
        margin-bottom: 12px;
    }

    /* Pill button styling */
    .dd-pill-btn {
        border-radius: var(--dd-pill-radius) !important;
        padding: 8px 16px !important;
    }

    /* Empty state */
    .dd-empty-state {
        padding: 12px 6px;
        text-align: center;
        color: #9ca3af;
    }

    /* Modal */
    .dd-modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 60;
    }

    .dd-modal-backdrop {
        position: absolute;
        inset: 0;
        background: var(--dd-overlay-bg);
    }

    .dd-modal-dialog {
        position: relative;
        background: var(--dd-card-bg);
        border-radius: 12px;
        padding: 16px 18px 18px;
        border: 1px solid var(--dd-card-border);
        width: 420px;
        max-width: 95%;
        box-shadow: 0 20px 40px rgba(0,0,0,.6);
    }

    .dd-modal-wide {
        width: 720px;
    }

    .dd-modal-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 12px;
        color: var(--dd-text-color);
    }

    .dd-modal-description {
        font-size: 13px;
        color: #9ca3af;
        margin-bottom: 12px;
    }

    .dd-loading-text {
        font-size: 14px;
        color: #9ca3af;
        margin: 8px 0;
    }

    .dd-modal-table-wrapper {
        max-height: 360px;
        overflow: auto;
        border-radius: 6px;
        border: 1px solid var(--dd-card-border);
        margin-bottom: 12px;
    }

    .dd-modal-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .dd-modal-table thead tr {
        background: var(--dd-header-bg);
    }

    .dd-modal-table th {
        padding: 8px 6px;
        border-bottom: 1px solid var(--dd-card-border);
    }

    .dd-modal-table td {
        padding: 6px 8px;
    }

    .dd-modal-message {
        font-size: 14px;
        margin-top: 8px;
    }

    .dd-modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 16px;
    }

    .dd-modal-btn-secondary {
        padding: 8px 14px;
        border-radius: var(--dd-pill-radius);
        border: 1px solid var(--dd-pill-border);
        font-size: 14px;
        background: transparent;
        color: var(--dd-text-color);
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .dd-modal-btn-secondary:hover {
        background: var(--dd-hover-bg);
        border-color: var(--accent, #4ade80);
    }
</style>
@endsection