@extends('layouts.app')

@section('content')
<div class="dd-page">
    <h1 class="dd-page-title" style="font-size:1.45rem;">SSL Certificates</h1>

    @if(session('status'))
        <div class="dd-alert dd-alert-success" style="margin-bottom:12px;">{{ session('status') }}</div>
    @endif

    @if(session('ssl_action_message'))
        <div class="dd-alert dd-alert-success" style="margin-bottom:12px;">{{ session('ssl_action_message') }}</div>
    @endif

    <div class="dd-toolbar" style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;margin-bottom:14px;">
        <form method="GET" style="display:flex;align-items:center;gap:8px;flex:1;flex-wrap:nowrap;">
            <select name="client_id" class="dd-input dd-input-inline" style="flex:1;min-width:240px;">
                <option value="">All Clients</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}" @selected(request('client_id')==$c->id)>{{ $c->business_name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-accent">Filter</button>
        </form>

        <form method="POST" action="{{ route('admin.services.ssl.sync') }}" style="flex:0 0 auto;">
            @csrf
            <button type="submit" class="btn-accent" style="white-space:nowrap;">Sync from Synergy</button>
        </form>
    </div>

    <div class="dd-card">
        <table>
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
                    <td>{{ $ssl->status ?: '—' }}</td>
                    <td>{{ optional($ssl->client)->business_name ?: '—' }}</td>
                    <td>
                        <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;font-size:14px;">
                            <span title="Toggle details">▾</span>
                        </div>
                    </td>
                </tr>

                <tr data-ssl-panel="{{ $rowId }}" class="dd-domain-panel">
                    <td colspan="6">
                        <div class="dd-domain-panel-inner">
                            <div class="dd-domain-panel-header">
                                <div>
                                    <div style="font-weight:600;">{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}</div>
                                    <div style="font-size:13px;opacity:.8;">
                                        {{ $ssl->status ?: 'Status unknown' }}
                                        @if($expiresInDays !== null)
                                            • expires in {{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }}
                                        @endif
                                        @if($ssl->cert_id)
                                            • Cert ID {{ $ssl->cert_id }}
                                        @endif
                                    </div>
                                </div>
                                <div style="font-size:13px;opacity:.7;">Product: {{ $ssl->display_product_name }}</div>
                            </div>

                            <div class="dd-domain-options-grid">
                                <a href="{{ route('admin.services.ssl.show', $ssl) }}" class="dd-domain-option">
                                    <div class="dd-domain-option-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
                                    <div class="dd-domain-option-label">Overview</div>
                                </a>

                                <form method="POST" action="{{ route('admin.services.ssl.renew', $ssl) }}" class="dd-domain-option-form" onsubmit="return confirm('Renew this SSL now?');">
                                    @csrf
                                    <button type="submit" class="dd-domain-option-btn">
                                        <div class="dd-domain-option-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></div>
                                        <div class="dd-domain-option-label">Renew</div>
                                    </button>
                                </form>

                                <button type="button" class="dd-domain-option" data-assign-client="{{ $ssl->id }}" data-ssl-name="{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}" data-client-id="{{ $ssl->client_id }}">
                                    <div class="dd-domain-option-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                                    <div class="dd-domain-option-label">Assign client</div>
                                </button>

                                <button type="button" class="dd-domain-option" data-open-bundle="{{ $ssl->id }}" data-ssl-name="{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}">
                                    <div class="dd-domain-option-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                                    <div class="dd-domain-option-label">Get cert / bundle</div>
                                </button>

                                <button type="button" class="dd-domain-option" data-open-rekey="{{ $ssl->id }}" data-ssl-name="{{ $ssl->common_name ?: 'Certificate #'.$ssl->id }}">
                                    <div class="dd-domain-option-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg></div>
                                    <div class="dd-domain-option-label">Rekey / reissue</div>
                                </button>

                                <form method="POST" action="{{ route('admin.services.ssl.resendCompletionEmail', $ssl) }}" class="dd-domain-option-form">
                                    @csrf
                                    <button type="submit" class="dd-domain-option-btn">
                                        <div class="dd-domain-option-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
                                        <div class="dd-domain-option-label">Resend completion email</div>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{ $ssls->withQueryString()->links() }}
    </div>

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
        tr[data-ssl-panel] {
            display: none;
            height: 0;
        }
        tr[data-ssl-panel] > td {
            padding: 0;
            border: 0;
        }
        tr[data-ssl-panel].open {
            display: table-row;
            height: auto;
        }

        .dd-domain-panel-inner {
            max-height: 0;
            padding: 0;
            margin-top: 0;
            border: 0;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-4px);
            transition: max-height 0.25s ease, opacity 0.2s ease, transform 0.2s ease, padding 0.2s ease, margin-top 0.2s ease, border-width 0.2s ease;
        }

        tr[data-ssl-panel].open > td > .dd-domain-panel-inner {
            max-height: 700px;
            opacity: 1;
            transform: translateY(0);
            padding: 16px 18px 18px;
            margin-top: 0;
            border-radius: 8px;
            border: 1px solid var(--border-subtle);
            background: var(--surface-elevated);
        }

        .dd-domain-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-radius: 6px;
            background: var(--surface-muted);
            border: 1px solid var(--border-subtle);
            margin-bottom: 14px;
        }

        .dd-domain-options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
        }

        .dd-domain-option,
        .dd-domain-option-btn {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 12px;
            background: var(--bg);
            border: 1px solid var(--border-subtle);
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            width: 100%;
            min-height: 52px;
            box-sizing: border-box;
            transition: background 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
        }

        .dd-domain-option:hover,
        .dd-domain-option-btn:hover {
            background: var(--surface-muted);
            border-color: var(--accent);
            transform: translateY(-1px);
        }

        .dd-domain-option-btn {
            background: transparent;
            color: inherit;
            height: 100%;
        }

        .dd-domain-option-form {
            margin: 0;
        }

        .dd-domain-option-icon {
            width: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
        }

        .dd-domain-option-label {
            flex: 1;
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
