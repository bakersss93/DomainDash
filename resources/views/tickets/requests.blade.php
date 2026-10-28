@extends('layouts.app')

@section('content')
<div class="ui-page">
    <div id="ticket-loading-overlay" role="status" aria-live="polite" style="position:fixed;inset:0;background:var(--dd-backdrop);backdrop-filter:blur(2px);z-index:12000;display:none;align-items:center;justify-content:center;padding:20px;">
        <div class="ui-card" style="min-width:14rem;padding:1rem 1.25rem;display:flex;align-items:center;gap:0.75rem;box-shadow:var(--dd-shadow-lg);">
            <span class="spinner" style="margin:0;" aria-hidden="true"></span>
            <span id="ticket-loading-text">Loading tickets...</span>
        </div>
    </div>
    <x-ui.page-header title="Support Requests" subtitle="HaloPSA tickets limited to the ticket types configured in Admin Settings.">
        <x-slot name="actions">
            <x-ui.button :href="route('tickets.create')" variant="primary">
                <x-ui.icon name="plus" :size="16" /> Log Support Ticket
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if($clients->isNotEmpty())
        <form id="ticket-filter-form" method="GET" action="{{ route('tickets.index') }}" class="ui-card" style="margin-bottom:1rem;">
            <div class="ui-card__body" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));align-items:end;">
                <div class="ui-field">
                    <label for="client_id" class="ui-label">Client</label>
                    <select id="client_id" name="client_id" class="ui-input">
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}" @selected($selectedClientId === $client->id)>{{ $client->business_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field">
                    <label for="service_category" class="ui-label">Filter by Service</label>
                    <select id="service_category" name="service_category" class="ui-input">
                        <option value="">All Services</option>
                        @foreach($serviceOptions as $serviceOption)
                            <option value="{{ $serviceOption }}" @selected($selectedServiceCategory === $serviceOption)>{{ $serviceOption }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field">
                    <label for="ticket_type_id" class="ui-label">Filter by Type</label>
                    <select id="ticket_type_id" name="ticket_type_id" class="ui-input">
                        <option value="">All Configured Types</option>
                        @foreach($ticketMappings as $mapping)
                            <option value="{{ $mapping['halo_ticket_type_id'] }}" @selected((int) $selectedTicketTypeId === (int) $mapping['halo_ticket_type_id'])>
                                {{ $mapping['halo_ticket_type_name'] ?? ('Type #' . $mapping['halo_ticket_type_id']) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button class="ui-btn ui-btn--primary" type="submit">Apply</button>
                    <a href="{{ route('tickets.index', ['client_id' => $selectedClientId]) }}" class="ui-btn ui-btn--secondary">Clear</a>
                </div>
            </div>
        </form>
    @else
        <x-ui.alert variant="warning">No client assignments were found for your account.</x-ui.alert>
    @endif

    @if($error)
        <x-ui.alert variant="warning" class="mb-4">{{ $error }}</x-ui.alert>
    @endif

    <x-ui.table>
        <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Summary</th>
                <th scope="col">Service</th>
                <th scope="col">Type</th>
                <th scope="col">Status</th>
                <th scope="col">Updated</th>
            </tr>
        </thead>
        <tbody id="support-ticket-table-body">
            @forelse($tickets as $ticket)
                <tr data-ticket-link="{{ route('tickets.show', ['ticketId' => $ticket['id'] ?? 0, 'client_id' => $selectedClientId, 'service' => $ticket['service'] ?? '']) }}" style="cursor:pointer;" tabindex="0">
                    <td>{{ $ticket['id'] ?? '-' }}</td>
                    <td>{{ $ticket['summary'] ?? '-' }}</td>
                    <td>{{ $ticket['service'] ?? '-' }}</td>
                    <td>{{ $ticket['type'] ?? 'Unknown' }}</td>
                    <td><x-ui.badge :status="$ticket['status'] ?? null">{{ $ticket['status'] ?? '-' }}</x-ui.badge></td>
                    <td>{{ $ticket['updated'] ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state title="No support requests found" text="No support requests were found for this filter." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div class="ui-muted" style="font-size:13px;">Page <span id="support-ticket-current-page">{{ $page }}</span> (25 per load)</div>
        @if($hasMore)
            <button id="support-ticket-load-more" class="ui-btn ui-btn--secondary" type="button">Load Next 25</button>
        @endif
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const loadingOverlay = document.getElementById('ticket-loading-overlay');
        const loadingText = document.getElementById('ticket-loading-text');
        const filterForm = document.getElementById('ticket-filter-form');
        const loadMoreButton = document.getElementById('support-ticket-load-more');
        const tableBody = document.getElementById('support-ticket-table-body');

        const showLoading = (text) => {
            if (!loadingOverlay || !loadingText) {
                return;
            }

            loadingText.textContent = text;
            loadingOverlay.style.display = 'flex';
        };

        const hideLoading = () => {
            if (!loadingOverlay) {
                return;
            }

            loadingOverlay.style.display = 'none';
        };

        const wireTicketRowClicks = (scope) => {
            scope.querySelectorAll('tr[data-ticket-link]').forEach((row) => {
                const open = function () {
                    const targetUrl = row.getAttribute('data-ticket-link');
                    if (!targetUrl) {
                        return;
                    }

                    showLoading('Loading ticket details...');
                    window.location.href = targetUrl;
                };
                row.addEventListener('click', open);
                row.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        open();
                    }
                });
            });
        };

        if (filterForm) {
            filterForm.addEventListener('submit', function () {
                showLoading('Loading tickets...');
            });
        }

        wireTicketRowClicks(document);

        if (!loadMoreButton) {
            return;
        }

        const currentPageLabel = document.getElementById('support-ticket-current-page');
        let currentPage = Number(currentPageLabel.textContent || '1');

        const selectedClientId = @json($selectedClientId);
        // Ticket fields come from the PSA: always HTML-escape before insertion.
        const tkEsc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const buildRow = (row) => `
            <tr data-ticket-link="{{ url('/tickets') }}/${encodeURIComponent(row.id)}?client_id=${encodeURIComponent(selectedClientId ?? '')}&service=${encodeURIComponent(row.service || '')}" style="cursor:pointer;" tabindex="0">
                <td>${tkEsc(row.id)}</td>
                <td>${tkEsc(row.summary)}</td>
                <td>${tkEsc(row.service)}</td>
                <td>${tkEsc(row.type)}</td>
                <td><span class="ui-badge ui-badge--neutral ui-badge--dot">${tkEsc(row.status)}</span></td>
                <td>${tkEsc(row.updated)}</td>
            </tr>
        `;

        loadMoreButton.addEventListener('click', async function () {
            const params = new URLSearchParams(window.location.search);
            params.set('page', String(currentPage + 1));

            showLoading('Loading more tickets...');
            loadMoreButton.disabled = true;
            loadMoreButton.textContent = 'Loading...';

            try {
                const response = await fetch(`{{ route('tickets.index') }}?${params.toString()}`, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                const rows = Array.isArray(data.rows) ? data.rows : [];

                rows.forEach(row => {
                    tableBody.insertAdjacentHTML('beforeend', buildRow(row));
                });
                wireTicketRowClicks(tableBody);

                currentPage = Number(data.page || currentPage + 1);
                currentPageLabel.textContent = String(currentPage);

                if (!data.has_more || rows.length === 0) {
                    loadMoreButton.remove();
                } else {
                    loadMoreButton.disabled = false;
                    loadMoreButton.textContent = 'Load Next 25';
                }
            } catch (error) {
                loadMoreButton.disabled = false;
                loadMoreButton.textContent = 'Load Next 25';
                alert('Unable to load next ticket page.');
            } finally {
                hideLoading();
            }
        });
    });
</script>
<style>
    @keyframes ticket-spin {
        to { transform: rotate(360deg); }
    }
</style>
@endsection
