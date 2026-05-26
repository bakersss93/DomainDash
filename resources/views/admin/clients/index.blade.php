@extends('layouts.app')

@section('content')
<div style="max-width: 1200px; margin: 0 auto;">
    <div class="dd-clients-card">
        <h1 class="dd-clients-title">Clients</h1>

        {{-- Header / actions --}}
        <div class="dd-clients-toolbar">
            {{-- Search bar --}}
            <form method="GET"
                  action="{{ route('admin.clients.index') }}"
                  class="dd-search-form">
                <input type="text"
                       name="search"
                       id="client-search"
                       placeholder="Search clients..."
                       class="dd-search-input"
                       value="{{ $search ?? '' }}">
                <button type="submit" class="btn-accent dd-search-btn">Search</button>

                {{-- Preserve sort parameters --}}
                @if(request('sort'))
                    <input type="hidden" name="sort" value="{{ request('sort') }}">
                @endif
                @if(request('direction'))
                    <input type="hidden" name="direction" value="{{ request('direction') }}">
                @endif
            </form>

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
                        <th class="dd-sortable-header" data-sort="business_name">
                            <a href="{{ route('admin.clients.index', ['sort' => 'business_name', 'direction' => ($sortColumn === 'business_name' && $sortDirection === 'asc') ? 'desc' : 'asc']) }}" class="dd-sort-link">
                                Business Name
                                <span class="dd-sort-arrow {{ $sortColumn === 'business_name' ? 'active' : '' }}">
                                    @if($sortColumn === 'business_name')
                                        {{ $sortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>
                        <th class="dd-sortable-header" data-sort="abn">
                            <a href="{{ route('admin.clients.index', ['sort' => 'abn', 'direction' => ($sortColumn === 'abn' && $sortDirection === 'asc') ? 'desc' : 'asc']) }}" class="dd-sort-link">
                                ABN
                                <span class="dd-sort-arrow {{ $sortColumn === 'abn' ? 'active' : '' }}">
                                    @if($sortColumn === 'abn')
                                        {{ $sortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>
                        <th class="dd-sortable-header" style="text-align:center;" data-sort="halopsa_reference">
                            <a href="{{ route('admin.clients.index', ['sort' => 'halopsa_reference', 'direction' => ($sortColumn === 'halopsa_reference' && $sortDirection === 'asc') ? 'desc' : 'asc']) }}" class="dd-sort-link">
                                HaloPSA
                                <span class="dd-sort-arrow {{ $sortColumn === 'halopsa_reference' ? 'active' : '' }}">
                                    @if($sortColumn === 'halopsa_reference')
                                        {{ $sortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>
                        <th class="dd-sortable-header" style="text-align:center;" data-sort="itglue_org_id">
                            <a href="{{ route('admin.clients.index', ['sort' => 'itglue_org_id', 'direction' => ($sortColumn === 'itglue_org_id' && $sortDirection === 'asc') ? 'desc' : 'asc']) }}" class="dd-sort-link">
                                ITGlue
                                <span class="dd-sort-arrow {{ $sortColumn === 'itglue_org_id' ? 'active' : '' }}">
                                    @if($sortColumn === 'itglue_org_id')
                                        {{ $sortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>
                        <th class="dd-sortable-header" style="text-align:center;" data-sort="active">
                            <a href="{{ route('admin.clients.index', ['sort' => 'active', 'direction' => ($sortColumn === 'active' && $sortDirection === 'asc') ? 'desc' : 'asc']) }}" class="dd-sort-link">
                                Status
                                <span class="dd-sort-arrow {{ $sortColumn === 'active' ? 'active' : '' }}">
                                    @if($sortColumn === 'active')
                                        {{ $sortDirection === 'asc' ? '↑' : '↓' }}
                                    @else
                                        ↕
                                    @endif
                                </span>
                            </a>
                        </th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr class="client-row" data-client-id="{{ $client->id }}">
                            <td>
                                <strong>{{ $client->business_name }}</strong>
                            </td>
                            <td>{{ $client->abn }}</td>
                            <td class="dd-cell-center">
                                @if($client->halopsa_reference)
                                    <span class="dd-status-success">✓</span>
                                @else
                                    <span class="dd-status-muted">—</span>
                                @endif
                            </td>
                            <td class="dd-cell-center">
                                @if($client->itglue_org_id)
                                    <span class="dd-status-info">✓</span>
                                @else
                                    <span class="dd-status-muted">—</span>
                                @endif
                            </td>
                            <td class="dd-cell-center">
                                <span class="{{ $client->active ? 'dd-status-success' : 'dd-status-muted' }}">
                                    {{ $client->active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="dd-cell-right">
                                <a href="{{ route('admin.clients.edit', $client) }}"
                                   onclick="event.stopPropagation();"
                                   class="dd-edit-btn">
                                    Edit
                                </a>
                            </td>
                        </tr>

                        {{-- Expandable details row --}}
                        <tr class="client-details" data-client-id="{{ $client->id }}" style="display:none;">
                            <td colspan="6" class="dd-expandable-cell">
                                <div style="background:#0f172a;padding:16px 20px;border-radius:0;">
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                                        {{-- Info column --}}
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

                                        {{-- Actions column --}}
                                        <div>
                                            <h4 style="font-size:14px;font-weight:600;margin-bottom:8px;color:#9ca3af;">Integration Actions</h4>

                                            {{-- ITGlue Sync --}}
                                            @if($client->itglue_org_id)
                                                <button type="button"
                                                        onclick="syncClientToItglue({{ $client->id }}, event)"
                                                        class="btn-accent dd-pill-btn dd-sync-btn">
                                                    📘 Sync Domains to ITGlue
                                                </button>
                                            @else
                                                <div class="dd-status-muted dd-no-integration">
                                                    Link ITGlue organization first
                                                </div>
                                            @endif

                                            {{-- HaloPSA Actions --}}
                                            @if($client->halopsa_reference)
                                                @php
                                                    $domainsWithAssets = $client->domains()->whereNotNull('halo_asset_id')->count();
                                                    $totalDomains = $client->domains()->count();
                                                @endphp

                                                {{-- Link Domains from Halo button --}}
                                                <button type="button"
                                                        onclick="linkDomainsFromHalo({{ $client->id }}, event)"
                                                        class="btn-accent dd-pill-btn dd-sync-btn">
                                                    🔗 Link Domains from Halo
                                                </button>

                                                {{-- Sync DNS to HaloPSA --}}
                                                @if($domainsWithAssets > 0)
                                                    <button type="button"
                                                            onclick="syncClientDnsToHalo({{ $client->id }}, event)"
                                                            class="btn-accent dd-pill-btn dd-sync-btn">
                                                        🔧 Sync DNS to HaloPSA ({{ $domainsWithAssets }})
                                                    </button>
                                                @else
                                                    <div class="dd-status-muted dd-no-integration">
                                                        No domains with HaloPSA assets yet
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
                            <td colspan="6" class="dd-empty-state">
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

    {{-- Halo import modal (same as before) --}}
    <div id="halo-import-backdrop"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.8);
                z-index:50;align-items:center;justify-content:center;">
        <div style="background:var(--dd-card-bg);border:1px solid var(--dd-card-border);color:var(--dd-text-color);border-radius:12px;padding:20px 24px;
                    width:100%;max-width:720px;box-shadow:0 20px 40px rgba(0,0,0,0.45);">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">
                Import clients from HaloPSA
            </h2>

            <p style="font-size:13px;color:#9ca3af;margin-bottom:12px;">
                Select one or more Halo clients to import. Matching domain assets will be linked automatically.
            </p>

            {{-- Search input --}}
            <input type="text"
                   id="halo-import-search"
                   placeholder="Search clients..."
                   style="width:100%;padding:8px 12px;border-radius:12px;border:1px solid var(--dd-card-border);
                          font-size:14px;margin-bottom:12px;background:var(--dd-pill-bg);color:var(--dd-text-color);">

            <div id="halo-import-loading" class="dd-loading-text">
                Loading clients from HaloPSA...
            </div>

            <div style="max-height:360px;overflow:auto;border-radius:10px;border:1px solid var(--dd-card-border);background:var(--dd-card-bg);">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="background:var(--dd-header-bg);">
                            <th style="width:40px;padding:8px 6px;border-bottom:1px solid var(--dd-card-border);text-align:center;">&nbsp;</th>
                            <th data-import-sort="name" style="padding:8px 6px;border-bottom:1px solid var(--dd-card-border);text-align:left;cursor:pointer;user-select:none;">
                                Name <span class="import-sort-arrow">↕</span>
                            </th>
                            <th data-import-sort="reference" style="padding:8px 6px;border-bottom:1px solid var(--dd-card-border);text-align:left;cursor:pointer;user-select:none;">
                                Reference <span class="import-sort-arrow">↕</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="halo-import-tbody"></tbody>
                </table>
            </div>

            <div id="halo-import-no-results" style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No clients match your search.
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

    {{-- Client Sync Confirm Modal --}}
    <div id="clientSyncConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:13000;align-items:center;justify-content:center;padding:20px;">
        <div style="width:min(90vw,440px);background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;overflow:hidden;">
            <div style="padding:18px 22px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:12px;">
                <span id="clientSyncConfirmIcon" style="flex-shrink:0;display:flex;color:var(--text);"></span>
                <h3 id="clientSyncConfirmTitle" style="font-size:16px;font-weight:700;color:var(--text);margin:0;"></h3>
            </div>
            <div style="padding:18px 22px;">
                <p id="clientSyncConfirmMessage" style="margin:0;color:var(--text);font-size:14px;line-height:1.5;"></p>
            </div>
            <div style="padding:0 22px 18px;display:flex;justify-content:flex-end;gap:12px;">
                <button onclick="closeClientSyncConfirmModal()" style="padding:9px 22px;background:var(--dd-status-danger-bg);border:1px solid color-mix(in srgb, var(--dd-danger) 35%, transparent);border-radius:10px;color:var(--dd-danger);cursor:pointer;font-size:14px;">Cancel</button>
                <button id="clientSyncConfirmBtn" class="btn-accent" style="padding:9px 22px;">Confirm</button>
            </div>
        </div>
    </div>

    {{-- Client Sync Progress Modal --}}
    <div id="clientSyncProgressModal" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:13000;align-items:center;justify-content:center;padding:20px;">
        <div style="min-width:240px;background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--text);">
            <span style="width:18px;height:18px;border:2px solid rgba(148,163,184,0.45);border-top-color:var(--text);border-radius:999px;display:inline-block;animation:dd-spin 0.7s linear infinite;flex-shrink:0;"></span>
            <span id="clientSyncProgressTitle"></span>
        </div>
    </div>

    {{-- Client Sync Result Modal --}}
    <div id="clientSyncResultModal" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:14000;align-items:center;justify-content:center;padding:20px;">
        <div style="width:min(90vw,480px);background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;overflow:hidden;">
            <div style="padding:18px 22px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:12px;">
                <span id="clientSyncResultIcon" style="flex-shrink:0;display:flex;"></span>
                <h3 id="clientSyncResultTitle" style="font-size:16px;font-weight:700;color:var(--text);margin:0;"></h3>
            </div>
            <div style="padding:18px 22px;">
                <p id="clientSyncResultMessage" style="margin:0;color:var(--text);font-size:14px;line-height:1.5;"></p>
                <div id="clientSyncResultDetails" style="display:none;margin-top:16px;">
                    <p style="font-size:12px;font-weight:600;color:var(--text-muted);margin:0 0 8px;text-transform:uppercase;letter-spacing:0.06em;">Details</p>
                    <div id="clientSyncResultDetailList" style="border:1px solid var(--border-subtle);border-radius:8px;overflow:hidden;max-height:220px;overflow-y:auto;"></div>
                </div>
            </div>
            <div style="padding:0 22px 18px;display:flex;justify-content:flex-end;">
                <button onclick="closeClientSyncResultModal()" class="btn-accent" style="padding:9px 22px;">Done</button>
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
        document.querySelectorAll('.client-row').forEach(row => {
            row.addEventListener('click', function(e) {
                if (e.target.tagName === 'A') return; // Don't expand when clicking Edit

                const clientId = this.dataset.clientId;
                const detailsRow = document.querySelector(`.client-details[data-client-id="${clientId}"]`);

                if (detailsRow.style.display === 'none') {
                    detailsRow.style.display = 'table-row';
                } else {
                    detailsRow.style.display = 'none';
                }
            });
        });

        // Client search is handled by form submission
        // No additional JavaScript needed - form handles the search

        // HaloPSA import modal functionality
        const openBtn = document.getElementById('btn-halo-import');
        const modal = document.getElementById('halo-import-backdrop');
        const cancelBtn = document.getElementById('halo-import-cancel');
        const confirmBtn = document.getElementById('halo-import-submit');
        const tbody = document.getElementById('halo-import-tbody');
        const errorBox = document.getElementById('halo-import-error');
        const loadingEl = document.getElementById('halo-import-loading');
        const emptyEl = document.getElementById('halo-import-empty');
        const noResultsEl = document.getElementById('halo-import-no-results');
        const searchInput = document.getElementById('halo-import-search');

        let allImportClients = [];
        let selectedImportClientIds = new Set();
        let currentImportSort = { column: 'name', direction: 'asc' };

        function showModal() {
            modal.classList.remove('dd-hidden');
            modal.style.display = 'flex';
            loadHaloClients();
        }

        function hideModal() {
            modal.classList.add('dd-hidden');
            modal.style.display = 'none';
            selectedImportClientIds.clear();
        }

        async function loadHaloClients() {
            tbody.innerHTML = '';
            if (errorBox) errorBox.style.display = 'none';
            if (emptyEl) emptyEl.style.display = 'none';
            if (noResultsEl) noResultsEl.style.display = 'none';
            if (loadingEl) loadingEl.style.display = 'block';

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
                    if (emptyEl) emptyEl.style.display = 'block';
                    return;
                }

                allImportClients = data;
                selectedImportClientIds.clear();
                renderImportClients();
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

        function renderImportClients() {
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';

            // Filter clients
            let filteredClients = allImportClients;
            if (searchTerm) {
                filteredClients = allImportClients.filter(client => {
                    const name = (client.name || '').toLowerCase();
                    const ref = (client.reference || client.id || '').toString().toLowerCase();
                    return name.includes(searchTerm) || ref.includes(searchTerm);
                });
            }

            // Sort clients
            filteredClients.sort((a, b) => {
                let aVal, bVal;
                if (currentImportSort.column === 'name') {
                    aVal = (a.name || '').toLowerCase();
                    bVal = (b.name || '').toLowerCase();
                } else {
                    aVal = (a.reference || a.id || '').toString().toLowerCase();
                    bVal = (b.reference || b.id || '').toString().toLowerCase();
                }

                if (aVal < bVal) return currentImportSort.direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentImportSort.direction === 'asc' ? 1 : -1;
                return 0;
            });

            // Clear tbody
            tbody.innerHTML = '';

            // Show/hide no results message
            if (filteredClients.length === 0) {
                if (searchTerm) {
                    if (noResultsEl) noResultsEl.style.display = 'block';
                } else {
                    if (emptyEl) emptyEl.style.display = 'block';
                }
                return;
            } else {
                if (noResultsEl) noResultsEl.style.display = 'none';
                if (emptyEl) emptyEl.style.display = 'none';
            }

            // Render rows
            filteredClients.forEach(function (client) {
                const tr = document.createElement('tr');
                const isChecked = selectedImportClientIds.has(String(client.id));
                tr.innerHTML = `
                    <td style="padding:6px 8px;text-align:center;border-bottom:1px solid var(--dd-card-border);background:var(--dd-card-bg);">
                        <input type="checkbox" class="halo-client-checkbox dd-checkbox" value="${client.id}" ${isChecked ? 'checked' : ''}>
                    </td>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--dd-card-border);background:var(--dd-card-bg);color:var(--dd-text-color);">${client.name || ''}</td>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--dd-card-border);background:var(--dd-card-bg);color:var(--dd-text-color);">${client.reference || ''}</td>
                `;
                const checkbox = tr.querySelector('.halo-client-checkbox');
                checkbox.addEventListener('change', function () {
                    const clientId = String(client.id);
                    if (this.checked) {
                        selectedImportClientIds.add(clientId);
                    } else {
                        selectedImportClientIds.delete(clientId);
                    }
                });
                tbody.appendChild(tr);
            });
        }

        function sortImportBy(column) {
            if (currentImportSort.column === column) {
                currentImportSort.direction = currentImportSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentImportSort.column = column;
                currentImportSort.direction = 'asc';
            }

            // Update sort arrows
            document.querySelectorAll('.import-sort-arrow').forEach(arrow => {
                arrow.textContent = '↕';
                arrow.style.opacity = '0.5';
            });

            const th = document.querySelector(`[data-import-sort="${column}"]`);
            if (th) {
                const arrow = th.querySelector('.import-sort-arrow');
                if (arrow) {
                    arrow.textContent = currentImportSort.direction === 'asc' ? '↑' : '↓';
                    arrow.style.opacity = '1';
                }
            }

            renderImportClients();
        }

        async function importSelected() {
            const ids = Array.from(selectedImportClientIds).map(id => parseInt(id, 10)).filter(Number.isFinite);

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

        // Search input event listener
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                if (allImportClients.length > 0) {
                    renderImportClients();
                }
            });
        }

        // Sort column event listeners
        document.querySelectorAll('[data-import-sort]').forEach(th => {
            th.addEventListener('click', function() {
                const column = this.getAttribute('data-import-sort');
                if (allImportClients.length > 0) {
                    sortImportBy(column);
                }
            });
        });
    });
    
    // Client sync modal helpers
    let _clientSyncConfirmCallback = null;
    let _clientSyncResultOnClose = null;

    function openClientSyncConfirmModal(title, message, iconSvg, onConfirm) {
        _clientSyncConfirmCallback = onConfirm;
        document.getElementById('clientSyncConfirmTitle').textContent = title;
        document.getElementById('clientSyncConfirmMessage').textContent = message;
        document.getElementById('clientSyncConfirmIcon').innerHTML = iconSvg;
        document.getElementById('clientSyncConfirmBtn').onclick = function () {
            closeClientSyncConfirmModal();
            if (_clientSyncConfirmCallback) _clientSyncConfirmCallback();
        };
        document.getElementById('clientSyncConfirmModal').style.display = 'flex';
    }

    function closeClientSyncConfirmModal() {
        document.getElementById('clientSyncConfirmModal').style.display = 'none';
    }

    function showClientSyncProgress(message) {
        document.getElementById('clientSyncProgressTitle').textContent = message;
        document.getElementById('clientSyncProgressModal').style.display = 'flex';
    }

    function hideClientSyncProgress() {
        document.getElementById('clientSyncProgressModal').style.display = 'none';
    }

    function showClientSyncResult(title, message, details, onClose) {
        _clientSyncResultOnClose = onClose || null;
        document.getElementById('clientSyncResultTitle').textContent = title;
        document.getElementById('clientSyncResultMessage').textContent = message;

        const iconEl = document.getElementById('clientSyncResultIcon');
        const hasErrors = details && details.some(d => !d.success);

        if (hasErrors) {
            iconEl.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
        } else {
            iconEl.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`;
        }

        if (details && details.length > 0) {
            const list = document.getElementById('clientSyncResultDetailList');
            list.innerHTML = details.map((d, idx) => `
                <div style="padding:10px 12px;${idx < details.length - 1 ? 'border-bottom:1px solid var(--border-subtle);' : ''}display:flex;flex-direction:column;gap:3px;">
                    <span style="font-size:13px;font-weight:600;color:var(--text);">${d.label}</span>
                    <span style="font-size:13px;color:${d.success ? '#10b981' : '#ef4444'};">${d.message}</span>
                </div>`).join('');
            document.getElementById('clientSyncResultDetails').style.display = 'block';
        } else {
            document.getElementById('clientSyncResultDetails').style.display = 'none';
        }

        document.getElementById('clientSyncResultModal').style.display = 'flex';
    }

    function closeClientSyncResultModal() {
        document.getElementById('clientSyncResultModal').style.display = 'none';
        if (_clientSyncResultOnClose) {
            const cb = _clientSyncResultOnClose;
            _clientSyncResultOnClose = null;
            cb();
        }
    }

    // Sync functions
    function syncClientToItglue(clientId, event) {
        event.stopPropagation();

        const syncIcon = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`;

        openClientSyncConfirmModal(
            'Sync Domains to ITGlue',
            'This will sync all client domains to ITGlue with DNS records from Synergy. Continue?',
            syncIcon,
            function () {
                showClientSyncProgress('Syncing domains to ITGlue…');

                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 120000);

                fetch(`/admin/clients/${clientId}/itglue/sync-domains`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    signal: controller.signal
                })
                .then(async r => {
                    clearTimeout(timeoutId);
                    const contentType = r.headers.get('content-type');
                    let data;
                    if (contentType && contentType.includes('application/json')) {
                        data = await r.json();
                    } else {
                        const text = await r.text();
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error('Server returned non-JSON response');
                    }
                    if (!r.ok) throw new Error(data.error || data.message || 'HTTP ' + r.status);
                    return data;
                })
                .then(data => {
                    hideClientSyncProgress();
                    if (data.success) {
                        const details = (data.results || []).map(r => ({
                            label: r.domain,
                            message: r.message,
                            success: r.success
                        }));
                        showClientSyncResult('Sync Complete', data.message, details, null);
                    } else {
                        showClientSyncResult('Sync Failed', data.error || data.message, [], null);
                    }
                })
                .catch(err => {
                    clearTimeout(timeoutId);
                    hideClientSyncProgress();
                    const msg = err.name === 'AbortError'
                        ? 'Sync timed out after 2 minutes. Check server logs for details.'
                        : (err.message || 'Sync failed');
                    showClientSyncResult('Sync Failed', msg, [], null);
                });
            }
        );
    }

    function syncClientDnsToHalo(clientId, event) {
        event.stopPropagation();

        const syncIcon = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>`;

        openClientSyncConfirmModal(
            'Sync DNS to HaloPSA',
            'This will sync DNS records from Synergy to HaloPSA asset notes. Continue?',
            syncIcon,
            function () {
                showClientSyncProgress('Syncing DNS records to HaloPSA…');

                fetch(`/admin/clients/${clientId}/halo/sync-dns`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(async r => {
                    const contentType = r.headers.get('content-type');
                    let data;
                    if (contentType && contentType.includes('application/json')) {
                        data = await r.json();
                    } else {
                        const text = await r.text();
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error('Server returned non-JSON response');
                    }
                    if (!r.ok) throw new Error(data.error || data.message || 'HTTP ' + r.status);
                    return data;
                })
                .then(data => {
                    hideClientSyncProgress();
                    if (data.success) {
                        showClientSyncResult('Sync Complete', data.message, [], null);
                    } else {
                        showClientSyncResult('Sync Failed', data.error || data.message, [], null);
                    }
                })
                .catch(err => {
                    hideClientSyncProgress();
                    showClientSyncResult('Sync Failed', err.message || 'Sync failed', [], null);
                });
            }
        );
    }

    function linkDomainsFromHalo(clientId, event) {
        event.stopPropagation();

        const linkIcon = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>`;

        openClientSyncConfirmModal(
            'Link Domains from HaloPSA',
            'This will link HaloPSA domain assets to matching DomainDash domains. Continue?',
            linkIcon,
            function () {
                showClientSyncProgress('Linking domains from HaloPSA…');

                fetch(`/admin/clients/${clientId}/halo/link-domains`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(async r => {
                    const contentType = r.headers.get('content-type');
                    let data;
                    if (contentType && contentType.includes('application/json')) {
                        data = await r.json();
                    } else {
                        const text = await r.text();
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error('Server returned non-JSON response');
                    }
                    if (!r.ok) throw new Error(data.error || data.message || 'HTTP ' + r.status);
                    return data;
                })
                .then(data => {
                    hideClientSyncProgress();
                    if (data.success) {
                        showClientSyncResult(
                            'Link Complete',
                            data.message,
                            [],
                            data.linked > 0 ? () => window.location.reload() : null
                        );
                    } else {
                        showClientSyncResult('Link Failed', data.error || data.message, [], null);
                    }
                })
                .catch(err => {
                    hideClientSyncProgress();
                    showClientSyncResult('Link Failed', err.message || 'Link failed', [], null);
                });
            }
        );
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
        padding: 16px 20px;
        margin-top: 0;
        border: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
        color: var(--dd-text-color);
    }

    .dd-clients-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 16px;
    }

    /* Toolbar */
    .dd-clients-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
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

    /* Search form */
    .dd-search-form {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
        min-width: 250px;
    }

    .dd-search-input {
        flex: 1;
        padding: 10px 16px !important;
        border-radius: 12px !important;
        border: 1px solid #1e293b !important;
        background: #0f172a !important;
        color: #e5e7eb !important;
        font-size: 14px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .dd-search-input:focus {
        outline: none;
        border-color: #334155 !important;
        box-shadow: 0 0 0 2px rgba(51, 65, 85, 0.3);
    }

    .dd-search-input::placeholder {
        color: #64748b !important;
    }

    .dd-search-btn {
        white-space: nowrap;
        padding: 8px 16px !important;
        border-radius: 12px !important;
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

    /* Cell alignment */
    .dd-cell-center {
        text-align: center;
    }

    .dd-cell-right {
        text-align: right;
    }

    /* Expandable cell */
    .dd-expandable-cell {
        padding: 0;
    }

    /* Sortable headers */
    .dd-sortable-header {
        cursor: pointer;
        user-select: none;
    }

    .dd-sort-link {
        display: flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        color: inherit;
        white-space: nowrap;
    }

    .dd-sort-link:hover {
        color: var(--accent, #4ade80);
    }

    .dd-sort-arrow {
        opacity: 0.4;
        font-size: 12px;
        transition: opacity 0.15s ease;
    }

    .dd-sort-arrow.active {
        opacity: 1;
        color: var(--accent, #4ade80);
    }

    .dd-sortable-header:hover .dd-sort-arrow {
        opacity: 0.8;
    }

    /* Client rows */
    .client-row {
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .client-row:hover {
        background-color: var(--dd-hover-bg) !important;
    }

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
        margin-bottom: 6px;
    }

    .dd-sync-status {
        margin-top: 4px;
        font-size: 12px;
    }

    /* Pill button styling */
    .dd-pill-btn {
        border-radius: 12px !important;
        padding: 8px 16px !important;
    }

    /* Empty state */
    .dd-empty-state {
        padding: 12px 6px;
        text-align: center;
        color: #9ca3af;
    }

    /* Force rounded custom checkboxes in Halo import modal (native checkboxes stay square on some browsers). */
    .halo-client-checkbox {
        appearance: none;
        -webkit-appearance: none;
        width: 18px;
        height: 18px;
        border-radius: 6px;
        border: 1px solid var(--dd-pill-border);
        background: var(--dd-pill-bg);
        display: inline-grid;
        place-content: center;
        cursor: pointer;
        transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    }

    .halo-client-checkbox::before {
        content: "";
        width: 9px;
        height: 9px;
        border-radius: 3px;
        transform: scale(0);
        transition: transform 0.12s ease-in-out;
        background: var(--dd-accent-contrast);
    }

    .halo-client-checkbox:checked {
        border-color: var(--dd-accent-strong);
        background: var(--dd-accent-strong);
    }

    .halo-client-checkbox:checked::before {
        transform: scale(1);
    }

    .halo-client-checkbox:focus-visible {
        outline: none;
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--dd-accent) 45%, transparent);
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

    @keyframes dd-spin {
        to { transform: rotate(360deg); }
    }
</style>
@endsection
