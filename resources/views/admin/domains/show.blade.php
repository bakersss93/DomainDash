@extends('layouts.app')

@section('content')
    <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">
        Domain overview – {{ $domain->name }}
    </h1>

    @php
        // dnsLabel should be passed from the controller (same mapping used on the list)
        $dnsText = $dnsLabel ?? ($domain->dns_config ?? '—');
        $clientsList = $clients;
    @endphp

    <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1.5fr);gap:16px;margin-bottom:20px;">
        {{-- Left: domain information --}}
        <div style="background:#020617;border:1px solid #1f2937;border-radius:8px;padding:14px 16px;">
            <div style="font-weight:600;margin-bottom:8px;">Domain information</div>
            <table style="width:100%;font-size:14px;border-collapse:collapse;">
                <tbody>
                <tr>
                    <td style="padding:4px 4px;width:150px;opacity:.8;">Domain name</td>
                    <td style="padding:4px 4px;">{{ $domain->name }}</td>
                </tr>
                <tr>
                    <td style="padding:4px 4px;opacity:.8;">Status</td>
                    <td style="padding:4px 4px;">{{ $domain->status ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:4px 4px;opacity:.8;">Expiry date</td>
                    <td style="padding:4px 4px;">{{ $domain->expiry_date ?? '—' }}</td>
                </tr>
                <tr id="auth-code">
                    <td style="padding:4px 4px;opacity:.8;">Password / auth code</td>
                    <td style="padding:4px 4px;display:flex;align-items:center;gap:8px;">
                        <span>••••••••••</span>
                        <button type="button" id="dd-auth-btn" class="btn-accent">
                            Get auth code
                        </button>
                    </td>
                </tr>
                <tr>
                    <td style="padding:4px 4px;opacity:.8;">Auto renewal</td>
                    <td style="padding:4px 4px;">
                        {{ $domain->auto_renew ? 'Enabled' : 'Disabled' }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:4px 4px;opacity:.8;">DNS configuration</td>
                    <td style="padding:4px 4px;">{{ $dnsText }}</td>
                </tr>
                </tbody>
            </table>
        </div>

        {{-- Right: Nameservers + transactions placeholder --}}
        <div style="display:flex;flex-direction:column;gap:16px;">
            <div style="background:#020617;border:1px solid #1f2937;border-radius:8px;padding:14px 16px;">
                <div style="font-weight:600;margin-bottom:8px;">Nameserver information</div>
                <div style="font-size:14px;opacity:.8;margin-bottom:4px;">Nameservers</div>
                <div style="font-size:14px;">
                    @if($domain->name_servers && is_array($domain->name_servers))
                        @foreach($domain->name_servers as $nameserver)
                            @if($nameserver)
                                <div>{{ $nameserver }}</div>
                            @endif
                        @endforeach
                    @elseif($domain->name_servers)
                        <div>{{ $domain->name_servers }}</div>
                    @else
                        <div style="opacity:.6;">No nameservers configured</div>
                    @endif
                </div>
            </div>

            <div style="background:#020617;border:1px solid #1f2937;border-radius:8px;padding:14px 16px;">
                <div style="font-weight:600;margin-bottom:8px;">WHOIS Information</div>
                @if($domain->whois_data && is_array($domain->whois_data))
                    <div style="font-size:13px;">
                        @if(isset($domain->whois_data['registrant']))
                            @php
                                $registrant = $domain->whois_data['registrant'];
                            @endphp
                            @if(!empty($registrant['name']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Registrant:</span>
                                    <span>{{ $registrant['name'] }}</span>
                                </div>
                            @endif
                            @if(!empty($registrant['organization']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Organization:</span>
                                    <span>{{ $registrant['organization'] }}</span>
                                </div>
                            @endif
                            @if(!empty($registrant['country']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Country:</span>
                                    <span>{{ $registrant['country'] }}</span>
                                </div>
                            @endif
                        @endif

                        @if(isset($domain->whois_data['registrar']))
                            @php
                                $registrar = $domain->whois_data['registrar'];
                            @endphp
                            @if(!empty($registrar['name']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Registrar:</span>
                                    <span>{{ $registrar['name'] }}</span>
                                </div>
                            @endif
                        @endif

                        @if(isset($domain->whois_data['domain']))
                            @php
                                $domainInfo = $domain->whois_data['domain'];
                            @endphp
                            @if(!empty($domainInfo['created_date']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Created:</span>
                                    <span>{{ $domainInfo['created_date'] }}</span>
                                </div>
                            @endif
                            @if(!empty($domainInfo['updated_date']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Updated:</span>
                                    <span>{{ $domainInfo['updated_date'] }}</span>
                                </div>
                            @endif
                            @if(!empty($domainInfo['expires_date']))
                                <div style="margin-bottom:6px;">
                                    <span style="opacity:.7;">Expires:</span>
                                    <span>{{ $domainInfo['expires_date'] }}</span>
                                </div>
                            @endif
                        @endif

                        @if($domain->whois_synced_at)
                            <div style="margin-top:10px;font-size:12px;opacity:.6;">
                                Last synced: {{ $domain->whois_synced_at->format('Y-m-d H:i') }}
                            </div>
                        @endif
                    </div>
                @else
                    <div style="font-size:14px;opacity:.6;">
                        WHOIS data not available.
                        @if(!$domain->whois_synced_at)
                            Sync WHOIS data from the domain list.
                        @endif
                    </div>
                @endif
            </div>

            <div style="background:#020617;border:1px solid #1f2937;border-radius:8px;padding:14px 16px;">
                <div style="font-weight:600;margin-bottom:8px;">Transactions</div>
                <div style="font-size:14px;opacity:.8;">
                    Full transaction history integration will be added here. For now this is a placeholder.
                </div>
            </div>
        </div>
    </div>

    {{-- Client assignment box --}}
    <div style="background:#020617;border:1px solid #1f2937;border-radius:8px;padding:14px 16px;max-width:720px;">
        <div style="font-weight:600;margin-bottom:8px;">Client / categories</div>

        <form method="POST" action="{{ url('/admin/domains/'.$domain->id.'/assign') }}">
            @csrf

            <label for="dd-overview-client-select" style="font-size:13px;display:block;margin-bottom:4px;">
                Client organisation
            </label>

            {{-- Searchable combobox (uses hidden <select>) --}}
            <div class="dd-combobox" data-source-select="dd-overview-client-select">
                <input
                    type="text"
                    class="dd-combobox-input"
                    placeholder="— No client —"
                    autocomplete="off"
                >
                <div class="dd-combobox-arrow">▾</div>
                <div class="dd-combobox-list"></div>

                <select
                    id="dd-overview-client-select"
                    name="client_id"
                    class="dd-hidden-select"
                >
                    <option value="">— No client —</option>
                    @foreach($clientsList as $client)
                        <option value="{{ $client->id }}"
                            {{ $domain->client_id === $client->id ? 'selected' : '' }}>
                            {{ $client->business_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="margin-top:10px;">
                <button type="submit" class="btn-accent">
                    Update client
                </button>
            </div>
        </form>
    </div>

    {{-- Auth code popup --}}
    <div id="dd-auth-overlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.75);align-items:center;justify-content:center;z-index:70;">
        <div style="background:#020617;border-radius:12px;padding:16px 18px;border:1px solid #1f2937;width:420px;max-width:95%;box-shadow:0 20px 40px rgba(0,0,0,.6);">
            <div style="font-weight:600;margin-bottom:8px;">Auth code for {{ $domain->name }}</div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <input id="dd-auth-value"
                       type="text"
                       readonly
                       style="flex:1;padding:6px 10px;border-radius:9999px;border:1px solid #4b5563;background:#020617;color:#f9fafb;">
                <button type="button" id="dd-auth-copy" class="btn-accent" style="white-space:nowrap;">
                    Copy
                </button>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" id="dd-auth-close" class="btn-accent" style="background:#4b5563;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Init searchable comboboxes for this page
            initDdComboboxes();

            // Auth code popup
            var authBtn    = document.getElementById('dd-auth-btn');
            var overlay    = document.getElementById('dd-auth-overlay');
            var authValue  = document.getElementById('dd-auth-value');
            var authCopy   = document.getElementById('dd-auth-copy');
            var authClose  = document.getElementById('dd-auth-close');

            authBtn.addEventListener('click', function () {
                fetch('{{ route('admin.domains.auth-code', $domain) }}')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) {
                            alert(data.message || 'Unable to fetch auth code');
                            return;
                        }
                        authValue.value = data.code;
                        overlay.style.display = 'flex';
                        authValue.select();
                    })
                    .catch(function () {
                        alert('Unable to fetch auth code');
                    });
            });

            authCopy.addEventListener('click', function () {
                authValue.select();
                try {
                    document.execCommand('copy');
                } catch (e) {}
            });

            authClose.addEventListener('click', function () {
                overlay.style.display = 'none';
            });

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.style.display = 'none';
                }
            });
        });

        /**
         * Simple “searchable select” widget.
         * Looks for .dd-combobox[data-source-select="<select-id>"].
         */
        function initDdComboboxes() {
            var combos = document.querySelectorAll('.dd-combobox');
            combos.forEach(function (combo) {
                var selectId = combo.dataset.sourceSelect;
                var select   = document.getElementById(selectId);
                if (!select) return;

                var input = combo.querySelector('.dd-combobox-input');
                var list  = combo.querySelector('.dd-combobox-list');

                var options = Array.prototype.map.call(select.options, function (opt) {
                    return {
                        value: opt.value,
                        label: opt.text
                    };
                });

                function render(filterText) {
                    var term = (filterText || '').toLowerCase();
                    list.innerHTML = '';

                    options
                        .filter(function (o) {
                            return !term || o.label.toLowerCase().includes(term);
                        })
                        .forEach(function (opt) {
                            var item = document.createElement('div');
                            item.className = 'dd-combobox-list-item';
                            item.textContent = opt.label;
                            item.dataset.value = opt.value;

                            item.addEventListener('mousedown', function (e) {
                                e.preventDefault(); // avoid blur
                                select.value = opt.value;
                                input.value  = opt.label;
                                closeList();
                            });

                            list.appendChild(item);
                        });
                }

                function openList() {
                    if (combo.classList.contains('open')) return;
                    combo.classList.add('open');
                    render(input.value);
                }

                function closeList() {
                    combo.classList.remove('open');
                }

                // initial text = selected option label (if any)
                var selected = select.options[select.selectedIndex];
                if (selected && selected.value !== '') {
                    input.value = selected.text;
                }

                combo.addEventListener('click', function () {
                    input.focus();
                    openList();
                });

                input.addEventListener('focus', openList);

                input.addEventListener('input', function () {
                    openList();
                    render(input.value);
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        closeList();
                        input.blur();
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!combo.contains(e.target)) {
                        closeList();
                    }
                });
            });
        }
    </script>

    <style>
        /* ---------- Searchable combobox styling ---------- */
        .dd-combobox {
            position: relative;
            display: flex;
            align-items: center;
            background: #0f172a;
            border-radius: 9999px;
            border: 1px solid #1f2937;
            padding: 0 36px 0 14px;
            min-height: 44px;
            cursor: text;
        }

        .dd-combobox-input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: #e5e7eb;
            font-size: 14px;
        }

        .dd-combobox-arrow {
            position: absolute;
            right: 12px;
            font-size: 12px;
            color: #9ca3af;
            pointer-events: none;
        }

        .dd-combobox-list {
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            background: #020617;
            border-radius: 8px;
            border: 1px solid #1f2937;
            box-shadow: 0 15px 35px rgba(0,0,0,.65);
            max-height: 260px;
            overflow-y: auto;
            z-index: 80;
            display: none;
        }

        .dd-combobox.open .dd-combobox-list {
            display: block;
        }

        .dd-combobox-list-item {
            padding: 8px 12px;
            font-size: 14px;
            cursor: pointer;
        }

        .dd-combobox-list-item:hover,
        .dd-combobox-list-item.is-highlighted {
            background: #111827;
        }

        /* Hide the real select – it still gets submitted */
        .dd-hidden-select {
            display: none;
        }
    </style>
@endsection
