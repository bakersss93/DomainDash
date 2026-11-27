@extends('layouts.app')

@section('content')
<div id="ssl-sync-loading-overlay" style="position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:12000;display:none;align-items:center;justify-content:center;padding:20px;">
    <div style="min-width:220px;background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--text);">
        <span style="width:18px;height:18px;border:2px solid rgba(148,163,184,0.45);border-top-color:var(--text);border-radius:999px;display:inline-block;animation:ssl-sync-spin 0.7s linear infinite;"></span>
        <span>Syncing SSL certificates...</span>
    </div>
</div>
<div class="ui-page">
    <x-ui.page-header title="SSL Certificates" subtitle="Certificates, expiry and client assignment.">
        <x-slot name="actions">
            <form method="POST" action="{{ route('admin.services.ssl.sync') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    <x-ui.icon name="refresh" :size="16" /> Sync from Synergy
                </x-ui.button>
            </form>
        </x-slot>
    </x-ui.page-header>

    @if(session('ssl_action_message'))
        <div class="dd-alert dd-alert-success" style="margin-bottom:12px;">{{ session('ssl_action_message') }}</div>
    @endif

    <div class="ui-toolbar">
        <form method="GET" class="flex flex-1 flex-wrap items-center gap-2">
            <label for="ssl-client-filter" class="sr-only">Filter by client</label>
            <select id="ssl-client-filter" name="client_id" class="dd-input" style="flex:1;min-width:14rem;max-width:28rem;">
                <option value="">All Clients</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}" @selected(request('client_id')==$c->id)>{{ $c->business_name }}</option>
                @endforeach
            </select>
            <button type="submit" class="ui-btn ui-btn--primary">Filter</button>
        </form>
    </div>

    <x-ui.card :flush="true">
        <x-ui.table>
            <thead>
                <tr>
                    <th>SSL Certificate</th>
                    <th>Product</th>
                    <th>Certificate Expiry</th>
                    <th>Status</th>
                    <th>Client</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach($ssls as $ssl)
                @php
                    $rowId = 'ssl-'.$ssl->id;
                    $expiresInDays = null;
                    if (!empty($ssl->expire_date)) {
                        try {
                            $expiry = \Carbon\Carbon::parse($ssl->expire_date)->startOfDay();
                            $today = now()->startOfDay();
                            $expiresInDays = (int) floor($today->diffInDays($expiry, false));
                        } catch (\Throwable $e) {
                            $expiresInDays = null;
                        }
                    }
                @endphp

                <tr data-ssl-toggle="{{ $rowId }}" class="dd-domain-row" style="cursor:pointer;">
                    <td>{{ $ssl->common_name ?: '—' }}</td>
                    <td>{{ $ssl->display_product_name }}</td>
                    <td class="{{ $ssl->isExpiringSoon() ? 'danger' : '' }}">
                        @if($expiresInDays !== null)
                            {{ optional($ssl->expire_date)->toDateString() }} ({{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }})
                        @else
                            —
                        @endif
                    </td>
                    <td>@if($ssl->status)<x-ui.badge :status="$ssl->status">{{ ucfirst($ssl->status) }}</x-ui.badge>@else — @endif</td>
                    <td>{{ optional($ssl->client)->business_name ?: '—' }}</td>
                    <td>
                        <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;font-size:14px;">
                            <span class="ui-muted" title="Toggle details"><x-ui.icon name="chevron-down" :size="16" /></span>
                        </div>
                    </td>
                </tr>

                <x-ui.row-detail data-ssl-panel="{{ $rowId }}" :title="$ssl->common_name ?: 'Certificate #'.$ssl->id" :colspan="6">
                    <x-slot:meta>
                        {{ $ssl->status ?: 'Status unknown' }}@if($expiresInDays !== null) • expires in {{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }}@endif @if($ssl->cert_id) • Cert ID {{ $ssl->cert_id }}@endif
                    </x-slot:meta>
                    <x-slot:aside>Product: {{ $ssl->display_product_name }}</x-slot:aside>

                    <x-ui.action-grid label="Certificate actions">
                        <x-ui.action-tile :href="route('admin.services.ssl.show', $ssl)" icon="eye">Overview</x-ui.action-tile>
                        <x-ui.action-tile :action="route('admin.services.ssl.renew', $ssl)" confirm="Renew this SSL now?" icon="refresh">Renew</x-ui.action-tile>
                        <x-ui.action-tile icon="users" data-assign-client="{{ $ssl->id }}" data-ssl-name="{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}" data-client-id="{{ $ssl->client_id }}">Assign client</x-ui.action-tile>
                        <x-ui.action-tile icon="file" data-open-bundle="{{ $ssl->id }}" data-ssl-name="{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}">Get cert / bundle</x-ui.action-tile>
                        <x-ui.action-tile icon="key" data-open-rekey="{{ $ssl->id }}" data-ssl-name="{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}">Rekey / reissue</x-ui.action-tile>
                        <x-ui.action-tile :action="route('admin.services.ssl.resendCompletionEmail', $ssl)" icon="mail">Resend completion email</x-ui.action-tile>
                    </x-ui.action-grid>
                </x-ui.row-detail>
            @endforeach
            </tbody>
        </x-ui.table>

        @if ($ssls->hasPages())
            <div class="px-4 py-3">{{ $ssls->withQueryString()->links() }}</div>
        @endif
    </x-ui.card>

    <div id="dd-assign-modal" class="dd-modal" style="display:none;">
        <div class="dd-modal-backdrop"></div>
        <div class="dd-modal-dialog">
            <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;">Assign client</h2>
            <p style="font-size:14px;margin-bottom:12px;">Update client for <span id="dd-assign-ssl-name"></span></p>

            <form method="POST" id="dd-assign-form" data-action-template="{{ url('/admin/services/ssl/__SSL__/assign-client') }}" action="{{ url('/admin/services/ssl/0/assign-client') }}">
                @csrf
                <input type="hidden" id="dd-assign-ssl-id">
                <label for="dd-assign-client-select" style="font-size:14px;display:block;margin-bottom:6px;">Client organisation</label>
                <select id="dd-assign-client-select" name="client_id" class="dd-input" style="width:100%;">
                    <option value="">— No client —</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->business_name }}</option>
                    @endforeach
                </select>
                <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" class="btn-accent">Save</button>
                    <button type="button" class="btn-accent" id="dd-assign-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="dd-rekey-modal" class="dd-modal" style="display:none;">
        <div class="dd-modal-backdrop"></div>
        <div class="dd-modal-dialog" style="width:720px;max-width:96%;">
            <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;">Rekey / Reissue SSL</h2>
            <p style="font-size:14px;margin-bottom:12px;">Certificate: <span id="dd-rekey-ssl-name"></span></p>

            <label for="dd-rekey-csr" style="font-size:14px;display:block;margin-bottom:6px;">CSR</label>
            <textarea id="dd-rekey-csr" rows="7" class="dd-input" style="width:100%;font-family:monospace;" placeholder="-----BEGIN CERTIFICATE REQUEST-----"></textarea>

            <div style="margin-top:10px;display:flex;gap:10px;">
                <button type="button" class="btn-accent" id="dd-rekey-decode">Decode CSR</button>
                <button type="button" class="btn-accent" id="dd-rekey-submit" disabled>Confirm and submit reissue</button>
                <button type="button" class="btn-accent" id="dd-rekey-cancel">Cancel</button>
            </div>

            <div id="dd-rekey-status" style="margin-top:10px;font-size:14px;"></div>

            <div id="dd-rekey-decoded" style="display:none;margin-top:12px;padding:12px;border-radius:8px;border:1px solid var(--border-subtle);background:var(--surface-muted);">
                <div style="font-weight:600;margin-bottom:8px;">CSR decoded details</div>
                <div id="dd-rekey-decoded-grid" style="display:grid;grid-template-columns:160px 1fr;gap:6px;font-size:14px;"></div>
            </div>

            <form method="POST" id="dd-rekey-form" data-action-template="{{ url('/admin/services/ssl/__SSL__/rekey') }}" action="{{ url('/admin/services/ssl/0/rekey') }}" style="display:none;">
                @csrf
                <input type="hidden" name="csr" id="dd-rekey-csr-hidden">
            </form>
        </div>
    </div>

    <div id="dd-bundle-modal" class="dd-modal" style="display:none;">
        <div class="dd-modal-backdrop"></div>
        <div class="dd-modal-dialog" style="width:900px;max-width:97%;">
            <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;">Certificate Bundle</h2>
            <p style="font-size:14px;margin-bottom:12px;">Certificate: <span id="dd-bundle-ssl-name"></span></p>

            <div style="display:flex;gap:10px;margin-bottom:10px;">
                <a href="#" id="dd-bundle-download-link" class="btn-accent" style="text-decoration:none;pointer-events:none;opacity:.5;">Download ZIP file</a>
                <button type="button" id="dd-bundle-close" class="btn-accent">Close</button>
            </div>

            <div id="dd-bundle-status" style="margin-bottom:10px;font-size:14px;"></div>

            <div style="display:grid;grid-template-columns:1fr;gap:10px;">
                <div>
                    <label style="display:block;margin-bottom:6px;">Certificate (CER)</label>
                    <textarea id="dd-bundle-cer" rows="5" class="dd-input" style="width:100%;font-family:monospace;" readonly></textarea>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;">Certificate (P7B)</label>
                    <textarea id="dd-bundle-p7b" rows="5" class="dd-input" style="width:100%;font-family:monospace;" readonly></textarea>
                </div>
                <div>
                    <label style="display:block;margin-bottom:6px;">CA Bundle</label>
                    <textarea id="dd-bundle-ca" rows="5" class="dd-input" style="width:100%;font-family:monospace;" readonly></textarea>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var sslSyncForm = document.querySelector('form[action*="ssl/sync"]');
            var sslSyncOverlay = document.getElementById('ssl-sync-loading-overlay');
            if (sslSyncForm && sslSyncOverlay) {
                sslSyncForm.addEventListener('submit', function () {
                    sslSyncOverlay.style.display = 'flex';
                });
            }

            document.querySelectorAll('[data-ssl-toggle]').forEach(function (row) {
                row.addEventListener('click', function (e) {
                    if (e.target.closest('a,button,form,input,select,textarea')) return;

                    var id = this.dataset.sslToggle;
                    var panel = document.querySelector('[data-ssl-panel="' + id + '"]');
                    if (!panel) return;

                    panel.classList.toggle('open', !panel.classList.contains('open'));
                });
            });

            var assignModal = document.getElementById('dd-assign-modal');
            var assignForm = document.getElementById('dd-assign-form');
            var assignSslName = document.getElementById('dd-assign-ssl-name');
            var assignSelect = document.getElementById('dd-assign-client-select');
            var assignCancel = document.getElementById('dd-assign-cancel');

            function closeAssignModal() {
                assignModal.style.display = 'none';
            }

            document.querySelectorAll('[data-assign-client]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var sslId = this.getAttribute('data-assign-client');
                    var sslName = this.getAttribute('data-ssl-name') || '';
                    var clientId = this.getAttribute('data-client-id') || '';

                    assignSslName.textContent = sslName;
                    assignSelect.value = clientId;
                    assignForm.action = assignForm.dataset.actionTemplate.replace('__SSL__', sslId);
                    assignModal.style.display = 'flex';
                });
            });

            assignCancel.addEventListener('click', function (e) {
                e.preventDefault();
                closeAssignModal();
            });

            assignModal.querySelector('.dd-modal-backdrop').addEventListener('click', closeAssignModal);

            var rekeyModal = document.getElementById('dd-rekey-modal');
            var rekeyForm = document.getElementById('dd-rekey-form');
            var rekeySslName = document.getElementById('dd-rekey-ssl-name');
            var rekeyCsr = document.getElementById('dd-rekey-csr');
            var rekeyCsrHidden = document.getElementById('dd-rekey-csr-hidden');
            var rekeyDecodeBtn = document.getElementById('dd-rekey-decode');
            var rekeySubmitBtn = document.getElementById('dd-rekey-submit');
            var rekeyCancelBtn = document.getElementById('dd-rekey-cancel');
            var rekeyStatus = document.getElementById('dd-rekey-status');
            var rekeyDecoded = document.getElementById('dd-rekey-decoded');
            var rekeyDecodedGrid = document.getElementById('dd-rekey-decoded-grid');
            var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            function closeRekeyModal() {
                rekeyModal.style.display = 'none';
                rekeyCsr.value = '';
                rekeyCsrHidden.value = '';
                rekeyStatus.textContent = '';
                rekeyDecoded.style.display = 'none';
                rekeyDecodedGrid.innerHTML = '';
                rekeySubmitBtn.disabled = true;
            }

            document.querySelectorAll('[data-open-rekey]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var sslId = this.getAttribute('data-open-rekey');
                    var sslName = this.getAttribute('data-ssl-name') || '';

                    rekeySslName.textContent = sslName;
                    rekeyForm.action = rekeyForm.dataset.actionTemplate.replace('__SSL__', sslId);
                    rekeyModal.style.display = 'flex';
                });
            });

            rekeyDecodeBtn.addEventListener('click', async function () {
                var csrValue = rekeyCsr.value.trim();
                if (!csrValue) {
                    rekeyStatus.textContent = 'Please paste CSR data first.';
                    return;
                }

                rekeyDecodeBtn.disabled = true;
                rekeySubmitBtn.disabled = true;
                rekeyStatus.textContent = 'Decoding CSR...';
                rekeyDecoded.style.display = 'none';
                rekeyDecodedGrid.innerHTML = '';

                try {
                    var response = await fetch('{{ route('admin.services.ssl.decodeCsr') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ csr: csrValue })
                    });

                    var payload = await response.json();

                    if (!response.ok || !payload.success) {
                        rekeyStatus.textContent = payload.message || 'Unable to decode CSR.';
                        return;
                    }

                    var decoded = payload.decoded || {};
                    var fields = [
                        ['Common Name', decoded.commonName],
                        ['Organisation', decoded.organisation],
                        ['Org Unit', decoded.organisationUnit],
                        ['City', decoded.city],
                        ['State', decoded.state],
                        ['Country', decoded.country],
                        ['Email', decoded.emailAddress],
                        ['Key Length', decoded.privateKeyLength]
                    ];

                    fields.forEach(function (field) {
                        var label = document.createElement('strong');
                        label.textContent = field[0];
                        var value = document.createElement('span');
                        value.textContent = field[1] || '—';
                        rekeyDecodedGrid.appendChild(label);
                        rekeyDecodedGrid.appendChild(value);
                    });

                    rekeyDecoded.style.display = 'block';
                    rekeyStatus.textContent = 'CSR decoded successfully. Confirm to submit reissue.';
                    rekeyCsrHidden.value = csrValue;
                    rekeySubmitBtn.disabled = false;
                } catch (error) {
                    rekeyStatus.textContent = 'Decode failed: ' + error.message;
                } finally {
                    rekeyDecodeBtn.disabled = false;
                }
            });

            rekeySubmitBtn.addEventListener('click', function () {
                if (!rekeyCsrHidden.value) {
                    rekeyStatus.textContent = 'Decode CSR first before submitting.';
                    return;
                }

                if (!confirm('Submit this CSR for reissue to Synergy?')) {
                    return;
                }

                rekeyForm.submit();
            });

            rekeyCancelBtn.addEventListener('click', closeRekeyModal);
            rekeyModal.querySelector('.dd-modal-backdrop').addEventListener('click', closeRekeyModal);

            var bundleModal = document.getElementById('dd-bundle-modal');
            var bundleSslName = document.getElementById('dd-bundle-ssl-name');
            var bundleStatus = document.getElementById('dd-bundle-status');
            var bundleCer = document.getElementById('dd-bundle-cer');
            var bundleP7b = document.getElementById('dd-bundle-p7b');
            var bundleCa = document.getElementById('dd-bundle-ca');
            var bundleDownloadLink = document.getElementById('dd-bundle-download-link');
            var bundleCloseBtn = document.getElementById('dd-bundle-close');

            function closeBundleModal() {
                bundleModal.style.display = 'none';
                bundleStatus.textContent = '';
                bundleCer.value = '';
                bundleP7b.value = '';
                bundleCa.value = '';
                bundleDownloadLink.href = '#';
                bundleDownloadLink.style.pointerEvents = 'none';
                bundleDownloadLink.style.opacity = '0.5';
            }

            document.querySelectorAll('[data-open-bundle]').forEach(function (btn) {
                btn.addEventListener('click', async function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var sslId = this.getAttribute('data-open-bundle');
                    var sslName = this.getAttribute('data-ssl-name') || '';

                    bundleModal.style.display = 'flex';
                    bundleSslName.textContent = sslName;
                    bundleStatus.textContent = 'Loading certificate bundle...';
                    bundleCer.value = '';
                    bundleP7b.value = '';
                    bundleCa.value = '';
                    bundleDownloadLink.href = '/admin/services/ssl/' + sslId + '/bundle.zip';

                    try {
                        var response = await fetch('/admin/services/ssl/' + sslId + '/certificate', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json'
                            }
                        });

                        var payload = await response.json();
                        if (!response.ok || !payload.success) {
                            bundleStatus.textContent = payload.message || 'Unable to load certificate bundle.';
                            return;
                        }

                        bundleCer.value = payload.bundle.cer || '';
                        bundleP7b.value = payload.bundle.p7b || '';
                        bundleCa.value = payload.bundle.caBundle || '';
                        bundleStatus.textContent = 'Certificate bundle fetched from Synergy.';
                        bundleDownloadLink.style.pointerEvents = 'auto';
                        bundleDownloadLink.style.opacity = '1';
                    } catch (error) {
                        bundleStatus.textContent = 'Bundle fetch failed: ' + error.message;
                    }
                });
            });

            bundleCloseBtn.addEventListener('click', closeBundleModal);
            bundleModal.querySelector('.dd-modal-backdrop').addEventListener('click', closeBundleModal);
        });
    </script>

    <style>
        @keyframes ssl-sync-spin {
            to { transform: rotate(360deg); }
        }

        .dd-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 70;
        }

        .dd-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.75);
        }

        .dd-modal-dialog {
            position: relative;
            background: var(--surface-elevated);
            border-radius: 12px;
            padding: 16px 18px 18px;
            border: 1px solid var(--border-subtle);
            width: 420px;
            max-width: 95%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }
    </style>
</div>
@endsection
