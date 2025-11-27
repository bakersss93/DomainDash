@extends('layouts.app')

@section('content')
    <div style="max-width: 960px; margin: 0 auto;">

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
                        <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">HaloPSA Ref</th>
                        <th style="text-align:left;padding:8px 6px;border-bottom:1px solid #1f2937;">Status</th>
                        <th style="text-align:right;padding:8px 6px;border-bottom:1px solid #1f2937;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                                {{ $client->business_name }}
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                                {{ $client->abn }}
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                                {{ $client->halopsa_reference }}
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;">
                                {{ $client->active ? 'Active' : 'Inactive' }}
                            </td>
                            <td style="padding:8px 6px;border-bottom:1px solid #111827;text-align:right;">
                                <a href="{{ route('admin.clients.edit', $client) }}"
                                   style="padding:6px 10px;border-radius:4px;border:1px solid #e5e7eb;
                                          font-size:13px;text-decoration:none;">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding:12px 6px;text-align:center;color:#9ca3af;">
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

    {{-- Halo import modal --}}
    <div id="halo-import-backdrop"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.8);
                z-index:50;align-items:center;justify-content:center;">
        <div style="background:#020617;border-radius:12px;padding:20px 24px;
                    width:100%;max-width:720px;box-shadow:0 20px 40px rgba(0,0,0,0.45);">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;">
                Import clients from HaloPSA
            </h2>

            <p style="font-size:13px;color:#9ca3af;margin-bottom:8px;">
                Select one or more Halo clients to import. Matching domain assets (type “Domain”)
                will be linked automatically to DomainDash domains.
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
                    <tbody id="halo-import-tbody">
                        {{-- populated by JS --}}
                    </tbody>
                </table>
            </div>

            <div id="halo-import-empty"
                 style="display:none;font-size:14px;color:#9ca3af;margin-top:8px;">
                No clients found from HaloPSA.
            </div>

            <div id="halo-import-error"
                 style="display:none;font-size:14px;color:#f97373;margin-top:8px;">
                Failed to load clients from HaloPSA. Please check API settings.
            </div>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
                <button type="button"
                        id="halo-import-cancel"
                        style="padding:8px 14px;border-radius:4px;border:1px solid #e5e7eb;
                               font-size:14px;background:transparent;">
                    Cancel
                </button>

                <button type="button"
                        id="halo-import-submit"
                        class="btn-accent"
                        style="padding:8px 14px;">
                    Import selected
                </button>
            </div>
        </div>
    </div>

    {{-- Inline JS so we don't rely on @stack('scripts') --}}
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // IDs MUST match the markup above
        const openBtn    = document.getElementById('btn-halo-import');
        const modal      = document.getElementById('halo-import-backdrop');
        const cancelBtn  = document.getElementById('halo-import-cancel');
        const confirmBtn = document.getElementById('halo-import-submit');
        const tbody      = document.getElementById('halo-import-tbody');
        const errorBox   = document.getElementById('halo-import-error');
        const loadingEl  = document.getElementById('halo-import-loading');
        const emptyEl    = document.getElementById('halo-import-empty');

        if (!openBtn || !modal || !tbody || !confirmBtn) {
            console.error('Halo import: required elements not found in DOM.');
            return;
        }

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

        function showModal() {
            if (errorBox) {
                errorBox.style.display = 'none';
                errorBox.textContent = '';
            }
            if (emptyEl) emptyEl.style.display = 'none';
            if (loadingEl) loadingEl.style.display = 'block';

            modal.style.display = 'flex';
            loadHaloClients();
        }

        function hideModal() {
            modal.style.display = 'none';
        }

        async function loadHaloClients() {
            tbody.innerHTML = '';
            if (errorBox) {
                errorBox.style.display = 'none';
                errorBox.textContent = '';
            }
            if (emptyEl) emptyEl.style.display = 'none';
            if (loadingEl) loadingEl.style.display = 'block';

            try {
                const res = await fetch('{{ route('admin.clients.haloClients') }}', {
                    headers: { 'Accept': 'application/json' }
                });

                const data = await res.json().catch(() => []);

                if (!res.ok) {
                    console.error('Halo clients load failed', res.status, data);
                    if (errorBox) {
                        errorBox.style.display = 'block';
                        errorBox.textContent =
                            'Failed to load clients from HaloPSA. Please check API settings.';
                    } else {
                        alert('Failed to load clients from HaloPSA. Please check API settings.');
                    }
                    return;
                }

                if (!Array.isArray(data) || data.length === 0) {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td colspan="3" style="padding:8px;color:#9ca3af;text-align:center;">' +
                        'No clients available to import.' +
                        '</td>';
                    tbody.appendChild(tr);
                    if (emptyEl) emptyEl.style.display = 'block';
                    return;
                }

                data.forEach(function (client) {
                    const tr = document.createElement('tr');

                    tr.innerHTML =
                        '<td style="padding:6px 8px;text-align:center;">' +
                            '<input type="checkbox" class="halo-client-checkbox" value="' + client.id + '">' +
                        '</td>' +
                        '<td style="padding:6px 8px;">' + (client.name || '') + '</td>' +
                        '<td style="padding:6px 8px;">' + (client.reference || '') + '</td>';

                    tbody.appendChild(tr);
                });

            } catch (e) {
                console.error('Halo clients load exception', e);
                if (errorBox) {
                    errorBox.style.display = 'block';
                    errorBox.textContent =
                        'Failed to load clients from HaloPSA (JS error). Check console & logs.';
                } else {
                    alert('Failed to load clients from HaloPSA. Check console & logs.');
                }
            } finally {
                if (loadingEl) loadingEl.style.display = 'none';
            }
        }

        async function importSelected() {
            const checkboxes = tbody.querySelectorAll('.halo-client-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => cb.value);

            if (ids.length === 0) {
                alert('Please select at least one Halo client to import.');
                return;
            }

            try {
                const res = await fetch('{{ route('admin.clients.importHalo') }}', {
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
                    console.error('Halo import failed', res.status, data);
                    alert('Import failed. Please check the logs / Halo API configuration.');
                    return;
                }

                const imported = data.imported ?? 0;
                const linked   = data.domains_linked ?? 0;

                alert('Imported ' + imported + ' client(s), linked ' + linked + ' domain(s).');

                hideModal();
                window.location.reload();

            } catch (e) {
                console.error('Halo import JS error', e);
                alert('Import failed. Please check browser console and Laravel logs.');
            }
        }

        openBtn.addEventListener('click', showModal);
        if (cancelBtn) cancelBtn.addEventListener('click', hideModal);
        confirmBtn.addEventListener('click', importSelected);
    });
    </script>
@endsection

