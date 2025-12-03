@extends('layouts.app')

@section('content')
    <div style="max-width: 1200px; margin: 0 auto;">

        {{-- Header / actions --}}
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h1 style="font-size:18px;font-weight:600;margin:0;">Clients</h1>

            <div style="display:flex;gap:8px;">
                <a href="{{ route('admin.clients.create') }}"
                   class="btn-accent"
                   style="padding:8px 14px;text-decoration:none;display:inline-block;">
                    New client
                </a>

                <button type="button"
                        id="btn-halo-import"
                        class="btn-accent"
                        style="padding:8px 14px;">
                    + HaloPSA
                </button>
            </div>
        </div>

        {{-- Clients table card --}}
        <div style="background:rgba(15,23,42,0.4);border-radius:8px;padding:16px 20px;margin-bottom:24px;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Business Name</th>
                        <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">ABN</th>
                        <th style="text-align:center;padding:8px 6px;border-bottom:1px solid #1f2937;">HaloPSA</th>
                        <th style="text-align:center;padding:8px 6px;border-bottom:1px solid #1f2937;">ITGlue</th>
                        <th style="text-align:center;padding:8px 6px;border-bottom:1px solid #1f2937;">Status</th>
                        <th style="text-align:right;padding:8px 6px;border-bottom:1px solid #1f2937;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr class="client-row" data-client-id="{{ $client->id }}" style="cursor:pointer;transition:background-color 0.15s ease;">
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                                <strong>{{ $client->business_name }}</strong>
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                                {{ $client->abn }}
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:center;">
                                @if($client->halopsa_reference)
                                    <span style="color:#34d399;">✓</span>
                                @else
                                    <span style="color:#6b7280;">—</span>
                                @endif
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:center;">
                                @if($client->itglue_org_id)
                                    <span style="color:#60a5fa;">✓</span>
                                @else
                                    <span style="color:#6b7280;">—</span>
                                @endif
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:center;">
                                <span style="color:{{ $client->active ? '#34d399' : '#6b7280' }};">
                                    {{ $client->active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:right;">
                                <a href="{{ route('admin.clients.edit', $client) }}"
                                   onclick="event.stopPropagation();"
                                   style="padding:6px 10px;border-radius:4px;border:1px solid #e5e7eb;
                                          font-size:13px;text-decoration:none;">
                                    Edit
                                </a>
                            </td>
                        </tr>

                        {{-- Expandable details row --}}
                        <tr class="client-details" data-client-id="{{ $client->id }}" style="display:none;">
                            <td colspan="6" style="padding:0;border-bottom:1px solid #111827;">
                                <div style="background:#0f172a;padding:16px 20px;border-radius:0;">
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                                        {{-- Info column --}}
                                        <div>
                                            <h4 style="font-size:14px;font-weight:600;margin-bottom:8px;color:#9ca3af;">Client Information</h4>
                                            <div style="font-size:13px;color:#e5e7eb;">
                                                @if($client->halopsa_reference)
                                                    <div style="margin-bottom:4px;">
                                                        <strong>HaloPSA Ref:</strong> {{ $client->halopsa_reference }}
                                                    </div>
                                                @endif
                                                @if($client->itglue_org_name)
                                                    <div style="margin-bottom:4px;">
                                                        <strong>ITGlue Org:</strong> {{ $client->itglue_org_name }} ({{ $client->itglue_org_id }})
                                                    </div>
                                                @endif
                                                <div style="margin-bottom:4px;">
                                                    <strong>Domains:</strong> {{ $client->domains()->count() }}
                                                </div>
                                                <div style="margin-bottom:4px;">
                                                    <strong>Users:</strong> {{ $client->users()->count() }}
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Actions column --}}
                                        <div>
                                            <h4 style="font-size:14px;font-weight:600;margin-bottom:8px;color:#9ca3af;">Integration Actions</h4>

                                            {{-- ITGlue Sync --}}
                                            @if($client->itglue_org_id)
                                                <div style="margin-bottom:12px;">
                                                    <button type="button"
                                                            onclick="syncClientToItglue({{ $client->id }}, event)"
                                                            class="btn-accent"
                                                            style="padding:6px 12px;font-size:13px;width:100%;">
                                                        📘 Sync Domains to ITGlue
                                                    </button>
                                                    <div id="itglue-status-{{ $client->id }}" style="margin-top:4px;font-size:12px;"></div>
                                                </div>
                                            @else
                                                <div style="margin-bottom:12px;font-size:13px;color:#6b7280;">
                                                    Link ITGlue organization first
                                                </div>
                                            @endif

                                            {{-- HaloPSA DNS Sync --}}
                                            @if($client->halopsa_reference)
                                                @php
                                                    $domainsWithAssets = $client->domains()->whereNotNull('halo_asset_id')->count();
                                                @endphp
                                                @if($domainsWithAssets > 0)
                                                    <div style="margin-bottom:12px;">
                                                        <button type="button"
                                                                onclick="syncClientDnsToHalo({{ $client->id }}, event)"
                                                                class="btn-accent"
                                                                style="padding:6px 12px;font-size:13px;width:100%;">
                                                            🔧 Sync DNS to HaloPSA ({{ $domainsWithAssets }})
                                                        </button>
                                                        <div id="halo-status-{{ $client->id }}" style="margin-top:4px;font-size:12px;"></div>
                                                    </div>
                                                @else
                                                    <div style="margin-bottom:12px;font-size:13px;color:#6b7280;">
                                                        No domains with HaloPSA assets
                                                    </div>
                                                @endif
                                            @else
                                                <div style="margin-bottom:12px;font-size:13px;color:#6b7280;">
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
                            <td colspan="6" style="padding:12px 6px;text-align:center;color:#9ca3af;">
                                No clients found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top:12px;">
                {{ $clients->links() }}
            </div>
        </div>
    </div>

    {{-- Halo import modal (same as before) --}}
    <div id="halo-import-backdrop"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.8);
                z-index:50;align-items:center;justify-content:center;">
        <div style="background:#020617;border-radius:12px;padding:20px 24px;
                    width:100%;max-width:720px;box-shadow:0 20px 40px rgba(0,0,0,0.45);">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">
                Import clients from HaloPSA
            </h2>

            <p style="font-size:13px;color:#9ca3af;margin-bottom:8px;">
                Select one or more Halo clients to import. Matching domain assets will be linked automatically.
            </p>

            <div id="halo-import-loading"
                 style="font-size:14px;color:#9ca3af;margin:8px 0;">
                Loading clients from HaloPSA…
            </div>

            <div style="max-height:360px;overflow:auto;border-radius:6px;border:1px solid #1f2937;">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="background:#020617;">
                            <th style="width:40px;padding:8px 6px;border-bottom:1px solid #1f2937;text-align:center;">&nbsp;</th>
                            <th style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;">Name</th>
                            <th style="padding:8px 6px;border-bottom:1px solid #1f2937;text-align:left;">Reference</th>
                        </tr>
                    </thead>
                    <tbody id="halo-import-tbody"></tbody>
                </table>
            </div>

            <div id="halo-import-empty" style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No clients found from HaloPSA.
            </div>

            <div id="halo-import-error" style="display:none;font-size:14px;color:#f97373;margin-top:8px;">
                Failed to load clients from HaloPSA. Please check API settings.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button type="button" id="halo-import-cancel"
                        style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                               font-size:14px;background:transparent;">
                    Cancel
                </button>
                <button type="button" id="halo-import-submit" class="btn-accent" style="padding:8px 14px;">
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

            // Add hover effect
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = 'rgba(148,163,184,0.1)';
            });
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
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
            modal.style.display = 'flex';
            loadHaloClients();
        }

        function hideModal() {
            modal.style.display = 'none';
        }

        async function loadHaloClients() {
            tbody.innerHTML = '';
            if (errorBox) errorBox.style.display = 'none';
            if (emptyEl) emptyEl.style.display = 'none';
            if (loadingEl) loadingEl.style.display = 'block';

            try {
                const res = await fetch('{{ route("admin.clients.haloClients") }}', {
                    headers: { 'Accept': 'application/json' }
                });

                const data = await res.json().catch(() => []);

                if (!res.ok) {
                    if (errorBox) {
                        errorBox.style.display = 'block';
                        errorBox.textContent = data.error || 'Failed to load clients';
                    }
                    return;
                }

                if (!Array.isArray(data) || data.length === 0) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="3" style="padding:8px;color:#9ca3af;text-align:center;">No clients available to import.</td>';
                    tbody.appendChild(tr);
                    if (emptyEl) emptyEl.style.display = 'block';
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
                    errorBox.style.display = 'block';
                    errorBox.textContent = 'Failed to load clients';
                }
            } finally {
                if (loadingEl) loadingEl.style.display = 'none';
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
            const contentType = r.headers.get('content-type');
            let data;

            if (contentType && contentType.includes('application/json')) {
                data = await r.json();
            } else {
                const text = await r.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned non-JSON response');
            }

            if (!r.ok) {
                console.error('ITGlue sync failed:', data);
                throw new Error(data.error || data.message || 'HTTP ' + r.status);
            }

            return data;
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
            statusDiv.innerHTML = `<span style="color:#f87171;">✗ ${err.message || 'Sync failed'}</span>`;
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
            const contentType = r.headers.get('content-type');
            let data;

            if (contentType && contentType.includes('application/json')) {
                data = await r.json();
            } else {
                const text = await r.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned non-JSON response');
            }

            if (!r.ok) {
                console.error('HaloPSA sync failed:', data);
                throw new Error(data.error || data.message || 'HTTP ' + r.status);
            }

            return data;
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
            statusDiv.innerHTML = `<span style="color:#f87171;">✗ ${err.message || 'Sync failed'}</span>`;
        });
    }
    </script>
@endsection