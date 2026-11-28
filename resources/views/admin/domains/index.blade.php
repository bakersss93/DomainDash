@extends('layouts.app')

@section('content')
    <div id="bulk-sync-loading-overlay" style="position:fixed;inset:0;background:rgba(2,6,23,0.6);backdrop-filter:blur(2px);z-index:12000;display:none;align-items:center;justify-content:center;padding:20px;">
        <div style="min-width:220px;background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:10px;color:var(--text);">
            <span style="width:18px;height:18px;border:2px solid rgba(148,163,184,0.45);border-top-color:var(--text);border-radius:999px;display:inline-block;animation:bulk-sync-spin 0.7s linear infinite;"></span>
            <span>Syncing domains...</span>
        </div>
    </div>

    <div class="ui-page">
    @php
        // Staff-only controls: shown only when the matching server-side check (route middleware / policy) would pass.
        $ddUser = auth()->user();
        $ddIsStaff = (bool) $ddUser?->isStaff();
        $ddCanSync = $ddIsStaff && $ddUser->can('sync.run');
        $ddCanRenew = $ddIsStaff && $ddUser->can('domains.renew');
        $ddCanAssign = $ddIsStaff && $ddUser->can('domains.manage');
        $ddCanAuthCode = $ddUser?->can('domains.transfer');
    @endphp
    <x-ui.page-header title="Domains" subtitle="{{ $ddIsStaff ? 'Registered domains, expiry and client assignment.' : 'Your domains and when they expire.' }}">
        <x-slot name="actions">
            @if($ddCanSync)
            <form method="POST" action="{{ route('admin.domains.bulkSync') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    <x-ui.icon name="refresh" :size="16" /> Bulk domain sync
                </x-ui.button>
            </form>
            @endif
        </x-slot>
    </x-ui.page-header>

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
    <div class="ui-toolbar">
        <form method="GET"
              action="{{ route('admin.domains') }}"
              class="flex flex-1 flex-wrap items-center gap-2">
            <label for="domain-search" class="sr-only">Search domains</label>
            <input
                type="search"
                id="domain-search"
                name="q"
                value="{{ $search }}"
                placeholder="Search domains…"
                class="dd-input"
                style="flex:1;min-width:14rem;max-width:32rem;"
            >
            <button type="submit" class="ui-btn ui-btn--primary">Search</button>
        </form>
    </div>

    <x-ui.card :flush="true">
    <x-ui.table>
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
                <td><x-ui.badge :status="$domain->status">{{ $domain->status ?? '—' }}</x-ui.badge></td>
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
                        <span title="Auto renew status"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg></span>

                        {{-- Assign / change client icon (staff only) --}}
                        @if($ddCanAssign)
                        <button type="button"
                                data-assign-client="{{ $domain->id }}"
                                data-domain-name="{{ $domain->name }}"
                                style="background:none;border:none;padding:0;cursor:pointer;">
                            @if($hasClient)
                                <span title="Client assigned"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            @else
                                <span title="Assign client"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span>
                            @endif
                        </button>
                        @endif

                        <span class="ui-muted" title="Toggle details"><x-ui.icon name="chevron-down" :size="16" /></span>
                    </div>
                </td>
            </tr>

            {{-- DETAILS ROW --}}
            <x-ui.row-detail data-domain-panel="{{ $rowId }}" :title="$domain->name" :colspan="6">
                <x-slot:meta>
                    {{ $domain->status ?? 'Status unknown' }}@if($expiresInDays !== null) • @if($expiresInDays < 0)expired {{ abs($expiresInDays) }} day{{ abs($expiresInDays) === 1 ? '' : 's' }} ago @elseif($expiresInDays === 0)expires today @else expires in {{ $expiresInDays }} day{{ $expiresInDays === 1 ? '' : 's' }}@endif @endif
                </x-slot:meta>
                <x-slot:aside>DNS: {{ $dnsLabel }}</x-slot:aside>

                <x-ui.action-grid label="Domain actions">
                    <x-ui.action-tile :href="route('admin.domains.show', $domain)" icon="eye">Overview</x-ui.action-tile>
                    <x-ui.action-tile :href="route('dns.index', $domain->id)" icon="server">Nameservers &amp; DNS</x-ui.action-tile>
                    @if($ddCanRenew)
                    <x-ui.action-tile :action="route('admin.domains.renew', $domain)" confirm="Renew this domain now?" icon="refresh">Renew</x-ui.action-tile>
                    @endif
                    @if($ddCanAssign)
                    <x-ui.action-tile icon="users" data-assign-client="{{ $domain->id }}" data-domain-name="{{ $domain->name }}">Assign client</x-ui.action-tile>
                    @endif
                    @if($ddIsStaff)
                    {{-- Not wired up yet: shown disabled instead of linking to "#" --}}
                    <x-ui.action-tile icon="upload-cloud" disabled title="Not available yet">Initiate transfer</x-ui.action-tile>
                    <x-ui.action-tile icon="list" disabled title="Not available yet">Transactions</x-ui.action-tile>
                    @endif
                    @if($ddCanAuthCode)
                    <x-ui.action-tile :href="route('admin.domains.show', $domain).'#auth-code'" icon="lock">Password / auth code</x-ui.action-tile>
                    @endif
                    @if($ddCanSync)
                    <x-ui.action-tile icon="search" onclick="syncDomainWhois({{ $domain->id }}, @js($domain->name))">WHOIS sync</x-ui.action-tile>
                    @endif
                    @if($ddIsStaff)
                    <x-ui.action-tile icon="trash" variant="danger" disabled title="Not available yet">Delete domain</x-ui.action-tile>
                    @endif
                </x-ui.action-grid>
            </x-ui.row-detail>
        @endforeach
        </tbody>
    </x-ui.table>

    @if ($domains->hasPages())
        <div class="px-4 py-3">{{ $domains->links() }}</div>
    @endif
    </x-ui.card>

    {{-- Assign client modal --}}
    @if($ddCanAssign)
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
                    <button type="button" class="btn-secondary" id="dd-assign-cancel">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- Bulk sync loading overlay ----------------------------------
            var bulkSyncForm = document.querySelector('form[action*="bulk-sync"]');
            var bulkSyncOverlay = document.getElementById('bulk-sync-loading-overlay');
            if (bulkSyncForm && bulkSyncOverlay) {
                bulkSyncForm.addEventListener('submit', function () {
                    bulkSyncOverlay.style.display = 'flex';
                });
            }

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
        @keyframes bulk-sync-spin {
            to { transform: rotate(360deg); }
        }
    </style>
    </div>
@endsection
