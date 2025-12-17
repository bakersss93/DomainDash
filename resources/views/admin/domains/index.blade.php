@extends('layouts.app')

@section('content')
    <h1 style="font-size:18px;font-weight:600;margin-bottom:16px;">Domains</h1>

    @php
        $currentSort = request('sort', $sort ?? 'name');
        $currentDir  = request('dir',  $dir  ?? 'asc');
        $search      = $search ?? request('q');

        // anonymous helper for sortable column headers
        $ddSortLink = function ($column, $label) use ($currentSort, $currentDir) {
            $isCurrent = $currentSort === $column;
            $nextDir   = $isCurrent && $currentDir === 'asc' ? 'desc' : 'asc';

            $icon = '';
            if ($isCurrent) {
                $icon = $currentDir === 'asc' ? ' ▲' : ' ▼';
            }

            $query = array_merge(
                request()->except(['sort','dir','page']),
                ['sort' => $column, 'dir' => $nextDir]
            );
            $url = request()->url().'?'.http_build_query($query);

            return '<a href="'.$url.'" style="color:inherit;text-decoration:none;">'
                .e($label).$icon.'</a>';
        };

        // DNS code -> label
        $dnsMap = [
            1 => 'Custom Nameservers',
            2 => 'URL & Email Forwarding',
            3 => 'Domain Parking',
            4 => 'DNS Hosting',
        ];
    @endphp

    {{-- Search + bulk sync row --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;">
        <form method="GET"
              action="{{ route('admin.domains') }}"
              style="display:flex;align-items:center;gap:8px;flex:1;">
            <input
                type="text"
                name="q"
                value="{{ $search }}"
                placeholder="Search domains…"
                style="flex:1;padding:6px 10px;border-radius:9999px;border:1px solid #4b5563;background:#020617;color:#f9fafb;"
            >
            <button type="submit" class="btn-accent">Search</button>
        </form>

        <form method="POST" action="{{ route('admin.domains.bulkSync') }}">
            @csrf
            <button type="submit" class="btn-accent" style="white-space:nowrap;">
                Bulk domain sync
            </button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>{!! $ddSortLink('name', 'Domain Name') !!}</th>
            <th>{!! $ddSortLink('status', 'Status') !!}</th>
            <th>{!! $ddSortLink('expiry_date', 'Expiry') !!}</th>
            <th>{!! $ddSortLink('dns_config', 'DNS Configuration') !!}</th>
            <th>Client</th>
            <th style="width:140px;">Options</th>
        </tr>
        </thead>
        <tbody>
        @foreach($domains as $domain)
            @php
                $rowId = 'domain-'.$domain->id;

                // whole days remaining
                $expiresInDays = null;
                if (!empty($domain->expiry_date)) {
                    try {
                        $expiry = \Carbon\Carbon::parse($domain->expiry_date)->startOfDay();
                        $today  = now()->startOfDay();
                        $expiresInDays = $today->diffInDays($expiry, false);
                        $expiresInDays = (int) floor($expiresInDays);
                    } catch (\Throwable $e) {
                        $expiresInDays = null;
                    }
                }
                $isDanger = $expiresInDays !== null && $expiresInDays <= 30;

                $code     = is_null($domain->dns_config) ? null : (int) $domain->dns_config;
                $dnsLabel = $dnsMap[$code] ?? ($domain->dns_config ?? '—');

                $clientName = optional($domain->client)->business_name;
                $hasClient  = ! is_null($domain->client_id);
            @endphp

            {{-- SUMMARY ROW --}}
            <tr data-domain-toggle="{{ $rowId }}" class="dd-domain-row" style="cursor:pointer;">
                <td>{{ $domain->name }}</td>
                <td>{{ $domain->status ?? '—' }}</td>
                <td class="{{ $isDanger ? 'danger' : '' }}">
                    @if($expiresInDays !== null)
                        {{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }}
                    @else
                        —
                    @endif
                </td>
                <td>{{ $dnsLabel }}</td>
                <td>{{ $clientName ?: '—' }}</td>
                <td>
                    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;font-size:14px;">
                        {{-- Auto-renew icon placeholder --}}
                        <span title="Auto renew status">🔁</span>

                        {{-- Assign / change client icon --}}
                        <button type="button"
                                data-assign-client="{{ $domain->id }}"
                                data-domain-name="{{ $domain->name }}"
                                style="background:none;border:none;padding:0;cursor:pointer;">
                            @if($hasClient)
                                <span title="Client assigned">👥</span>
                            @else
                                <span title="Assign client">➕</span>
                            @endif
                        </button>

                        <span title="Toggle details">▾</span>
                    </div>
                </td>
            </tr>

            {{-- DETAILS ROW --}}
            <tr data-domain-panel="{{ $rowId }}" class="dd-domain-panel">
                <td colspan="6">
                    <div class="dd-domain-panel-inner">
                        <div class="dd-domain-panel-header">
                            <div>
                                <div style="font-weight:600;">{{ $domain->name }}</div>
                                <div style="font-size:13px;opacity:.8;">
                                    {{ $domain->status ?? 'Status unknown' }}
                                    @if($expiresInDays !== null)
                                        • expires in {{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }}
                                    @endif
                                </div>
                            </div>
                            <div style="font-size:13px;opacity:.7;">
                                DNS: {{ $dnsLabel }}
                            </div>
                        </div>

                        <div class="dd-domain-options-grid">
                            {{-- Overview --}}
                            <a href="{{ route('admin.domains.show', $domain) }}" class="dd-domain-option">
                                <div class="dd-domain-option-icon">👁️</div>
                                <div class="dd-domain-option-label">Overview</div>
                            </a>

                            {{-- DNS / Nameservers --}}
                            <a href="{{ route('dns.index', $domain->id) }}" class="dd-domain-option">
                                <div class="dd-domain-option-icon">🧩</div>
                                <div class="dd-domain-option-label">Nameservers &amp; DNS</div>
                            </a>

                            {{-- Renew --}}
                            <form method="POST"
                                  action="{{ route('admin.domains.renew', $domain) }}"
                                  class="dd-domain-option"
                                  onsubmit="return confirm('Renew this domain now?');">
                                @csrf
                                <button type="submit" class="dd-domain-option-btn">
                                    <div class="dd-domain-option-icon">🔄</div>
                                    <div class="dd-domain-option-label">Renew</div>
                                </button>
                            </form>

                            {{-- Assign client (same popup) --}}
                            <button type="button"
                                    class="dd-domain-option"
                                    data-assign-client="{{ $domain->id }}"
                                    data-domain-name="{{ $domain->name }}">
                                <div class="dd-domain-option-icon">👥</div>
                                <div class="dd-domain-option-label">Assign client</div>
                            </button>

                            {{-- Transfer placeholder --}}
                            <a href="#" class="dd-domain-option">
                                <div class="dd-domain-option-icon">📤</div>
                                <div class="dd-domain-option-label">Initiate transfer</div>
                            </a>

                            {{-- Transactions placeholder --}}
                            <a href="#" class="dd-domain-option">
                                <div class="dd-domain-option-icon">📜</div>
                                <div class="dd-domain-option-label">Transactions</div>
                            </a>

                            {{-- Auth code (takes you to overview section) --}}
                            <a href="{{ route('admin.domains.show', $domain) }}#auth-code" class="dd-domain-option">
                                <div class="dd-domain-option-icon">🔐</div>
                                <div class="dd-domain-option-label">Password / auth code</div>
                            </a>

                            {{-- WHOIS sync --}}
                            <button type="button" class="dd-domain-option" onclick="syncDomainWhois({{ $domain->id }}, '{{ $domain->name }}')">
                                <div class="dd-domain-option-icon">🔍</div>
                                <div class="dd-domain-option-label">WHOIS sync</div>
                            </button>

                            {{-- Delete placeholder --}}
                            <a href="#" class="dd-domain-option dd-domain-option-danger">
                                <div class="dd-domain-option-icon">✖️</div>
                                <div class="dd-domain-option-label">Delete domain</div>
                            </a>
                        </div>
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{ $domains->links() }}

    {{-- Assign client modal --}}
    <div id="dd-assign-modal" class="dd-modal" style="display:none;">
        <div class="dd-modal-backdrop"></div>
        <div class="dd-modal-dialog">
            <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;">Assign client</h2>
            <p style="font-size:14px;margin-bottom:12px;">
                Assign client for <span id="dd-assign-domain-name"></span>
            </p>

            <form method="POST"
                  id="dd-assign-form"
                  data-action-template="{{ url('/admin/domains/__DOMAIN__/assign') }}"
                  action="{{ url('/admin/domains/0/assign') }}">
                @csrf
                <input type="hidden" name="domain_id" id="dd-assign-domain-id">

                <label for="dd-assign-client-select" style="font-size:14px;display:block;margin-bottom:6px;">
                    Client organisation
                </label>

                <div class="dd-combobox" data-source-select="dd-assign-client-select">
                    <input type="text"
                           class="dd-combobox-input"
                           placeholder="— No client —"
                           autocomplete="off">
                    <div class="dd-combobox-arrow">▾</div>
                    <div class="dd-combobox-list"></div>

                    <select id="dd-assign-client-select"
                            name="client_id"
                            class="dd-hidden-select">
                        <option value="">— No client —</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->business_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="submit" class="btn-accent">Save</button>
                    <button type="button" class="btn-accent" id="dd-assign-cancel">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- Expand / collapse domain details row -----------------------
            document.querySelectorAll('[data-domain-toggle]').forEach(function (row) {
                row.addEventListener('click', function (e) {
                    // Let links / buttons behave normally
                    if (e.target.closest('a,button,form,input,select,textarea')) return;

                    var id    = this.dataset.domainToggle;
                    var panel = document.querySelector('[data-domain-panel="' + id + '"]');
                    if (!panel) return;

                    var isOpen = !panel.classList.contains('open');
                    panel.classList.toggle('open', isOpen);
                });
            });

            // --- Initialise searchable selects (client picker) --------------
            initDdComboboxes();

            // --- Assign client modal wiring --------------------------------
            var assignModal    = document.getElementById('dd-assign-modal');
            var assignForm     = document.getElementById('dd-assign-form');
            var assignDomainId = document.getElementById('dd-assign-domain-id');
            var assignNameSpan = document.getElementById('dd-assign-domain-name');
            var assignCancel   = document.getElementById('dd-assign-cancel');
            var backdrop       = assignModal ? assignModal.querySelector('.dd-modal-backdrop') : null;

            function openAssignModal(domainId, domainName) {
                if (!assignModal || !assignForm) return;

                assignDomainId.value        = domainId;
                assignNameSpan.textContent  = domainName || '';

                var template = assignForm.dataset.actionTemplate;
                assignForm.action = template.replace('__DOMAIN__', domainId);

                assignModal.style.display = 'flex';
            }

            function closeAssignModal() {
                if (!assignModal) return;
                assignModal.style.display = 'none';
            }

            // Any element with data-assign-client opens the modal
            document.querySelectorAll('[data-assign-client]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var domainId   = this.getAttribute('data-assign-client');
                    var domainName = this.getAttribute('data-domain-name') || '';

                    openAssignModal(domainId, domainName);
                });
            });

            if (assignCancel) {
                assignCancel.addEventListener('click', function (e) {
                    e.preventDefault();
                    closeAssignModal();
                });
            }

            if (backdrop) {
                backdrop.addEventListener('click', function () {
                    closeAssignModal();
                });
            }
        });

        async function syncDomainWhois(domainId, domainName) {
            if (!confirm(`Sync WHOIS data for ${domainName}?`)) {
                return;
            }

            showGlobalSpinner('Syncing WHOIS…');

            try {
                const response = await fetch('/admin/sync/ip2whois/domains/sync', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ items: [{ id: domainId }] })
                });

                const data = await response.json();

                if (data.success) {
                    alert(`WHOIS sync completed (updated ${data.synced_count} domain${data.synced_count === 1 ? '' : 's'})`);
                } else {
                    alert('WHOIS sync failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                alert('WHOIS sync failed: ' + error.message);
            } finally {
                hideGlobalSpinner();
            }
        }

        /**
         * Tiny “searchable select” widget.
         * Looks for .dd-combobox[data-source-select="<select-id>"]
         */
        function initDdComboboxes() {
            document.querySelectorAll('.dd-combobox').forEach(function (combo) {
                var selectId = combo.dataset.sourceSelect;
                var select   = document.getElementById(selectId);
                if (!select) return;

                var input = combo.querySelector('.dd-combobox-input');
                var list  = combo.querySelector('.dd-combobox-list');

                var options = Array.from(select.options).map(function (opt) {
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
                                // prevent input blur before we set the value
                                e.preventDefault();
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

                // initial text = current option label (if any)
                var selected = select.options[select.selectedIndex];
                if (selected && selected.value !== '') {
                    input.value = selected.text;
                }

                // clicking anywhere on the pill focuses the input
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
        /* Hide the details row when closed so there is no blank space */
        tr[data-domain-panel] {
            display: none;
            height: 0;
        }
        tr[data-domain-panel] > td {
            padding: 0;
            border: 0;
        }
        tr[data-domain-panel].open {
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
            transition:
                max-height 0.25s ease,
                opacity 0.2s ease,
                transform 0.2s ease,
                padding 0.2s ease,
                margin-top 0.2s ease,
                border-width 0.2s ease;
        }

        tr[data-domain-panel].open > td > .dd-domain-panel-inner {
            max-height: 600px;
            opacity: 1;
            transform: translateY(0);
            padding: 16px 18px 18px;
            margin-top: 0;
            border-radius: 8px;
            border: 1px solid #1f2937;
            background: #0f172a;
        }

        .dd-domain-panel-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:8px 12px;
            border-radius:6px;
            background:#020617;
            border:1px solid #1f2937;
            margin-bottom:14px;
        }

        .dd-domain-options-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap:10px;
        }

        .dd-domain-option,
        .dd-domain-option-btn {
            display:flex;
            align-items:center;
            padding:10px 14px;
            border-radius:9999px;
            background:#020617;
            border:1px solid #1f2937;
            text-decoration:none;
            font-size:14px;
            cursor:pointer;
            width:100%;
            transition:background 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
        }

        .dd-domain-option:hover,
        .dd-domain-option-btn:hover {
            background:#0f172a;
            border-color:var(--accent);
            transform:translateY(-1px);
        }

        .dd-domain-option-btn {
            background:transparent;
            color:inherit;
        }

        .dd-domain-option-icon {
            width:24px;
            display:flex;
            align-items:center;
            justify-content:center;
            margin-right:8px;
        }

        .dd-domain-option-label {
            flex:1;
        }

        .dd-domain-option-danger {
            border-color:#b91c1c;
        }

        .dd-domain-option-danger:hover {
            background:#7f1d1d;
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
            background: rgba(15,23,42,0.75);
        }
        .dd-modal-dialog {
            position: relative;
            background:#020617;
            border-radius:12px;
            padding:16px 18px 18px;
            border:1px solid #1f2937;
            width:420px;
            max-width:95%;
            box-shadow:0 20px 40px rgba(0,0,0,.6);
        }

        /* Searchable combobox */
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
        .dd-combobox select.dd-hidden-select {
            display: none;
        }
    </style>
@endsection
