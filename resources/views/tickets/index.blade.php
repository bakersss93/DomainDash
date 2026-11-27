@extends('layouts.app')

@section('content')
<div class="ui-page">
    <x-ui.page-header title="Support Requests" subtitle="Support & Service Request tickets linked to your account.">
        <x-slot name="actions">
            <x-ui.button :href="route('tickets.create')" variant="primary">
                <x-ui.icon name="plus" :size="16" /> Log Support Ticket
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if($clients->isNotEmpty())
        <form method="GET" action="{{ route('tickets.index') }}" class="ui-card" style="margin-bottom:1rem;">
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
                @php
                    $ticketTypeLabel = $ticket['tickettype_name'] ?? $ticket['TicketTypeName'] ?? $ticket['tickettype'] ?? $ticket['TicketType'] ?? 'Unknown';
                    $ticketService = $ticket['category_1'] ?? $ticket['Category1'] ?? $ticket['category1'] ?? '-';
                    $ticketStatus = $ticket['status_name'] ?? $ticket['StatusName'] ?? $ticket['status'] ?? '-';
                @endphp
                <tr>
                    <td>{{ $ticket['id'] ?? $ticket['Id'] ?? '-' }}</td>
                    <td>{{ $ticket['summary'] ?? $ticket['Summary'] ?? '-' }}</td>
                    <td>{{ $ticketService }}</td>
                    <td>{{ $ticketTypeLabel }}</td>
                    <td><x-ui.badge :status="is_string($ticketStatus) ? $ticketStatus : null">{{ $ticketStatus }}</x-ui.badge></td>
                    <td>{{ $ticket['lastactiondate'] ?? $ticket['LastActionDate'] ?? $ticket['datecreated'] ?? '-' }}</td>
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
        const loadMoreButton = document.getElementById('support-ticket-load-more');
        if (!loadMoreButton) {
            return;
        }

        const tableBody = document.getElementById('support-ticket-table-body');
        const currentPageLabel = document.getElementById('support-ticket-current-page');
        let currentPage = Number(currentPageLabel.textContent || '1');

        // Ticket fields come from the PSA: always HTML-escape before insertion.
        const tkEsc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        const buildRow = (row) => `
            <tr>
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
            }
        });
    });
</script>
@endsection
