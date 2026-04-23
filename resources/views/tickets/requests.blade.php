@extends('layouts.app')

@section('content')
<div style="max-width:1080px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
        <div>
            <h1 class="dd-page-title" style="font-size:1.6rem;margin-bottom:10px;">Support Requests</h1>
            <p style="margin:0 0 14px;color:var(--text-muted);">Showing HaloPSA tickets limited to ticket types configured in Admin Settings.</p>
        </div>
        <a href="{{ route('tickets.create') }}" class="btn-accent" style="text-decoration:none;">Log Support Ticket</a>
    </div>

    @if($clients->isNotEmpty())
        <form method="GET" action="{{ route('tickets.index') }}" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:12px 14px;margin-bottom:16px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:end;">
            <div>
                <label for="client_id" style="display:block;font-weight:600;margin-bottom:6px;">Client</label>
                <select id="client_id" name="client_id" style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected($selectedClientId === $client->id)>{{ $client->business_name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="service_category" style="display:block;font-weight:600;margin-bottom:6px;">Filter by Service</label>
                <select id="service_category" name="service_category" style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    <option value="">All Services</option>
                    @foreach($serviceOptions as $serviceOption)
                        <option value="{{ $serviceOption }}" @selected($selectedServiceCategory === $serviceOption)>{{ $serviceOption }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="ticket_type_id" style="display:block;font-weight:600;margin-bottom:6px;">Filter by Type</label>
                <select id="ticket_type_id" name="ticket_type_id" style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    <option value="">All Configured Types</option>
                    @foreach($ticketMappings as $mapping)
                        <option value="{{ $mapping['halo_ticket_type_id'] }}" @selected((int) $selectedTicketTypeId === (int) $mapping['halo_ticket_type_id'])>
                            {{ $mapping['halo_ticket_type_name'] ?? ('Type #' . $mapping['halo_ticket_type_id']) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="display:flex;gap:8px;align-items:end;">
                <button class="btn-accent" type="submit">Apply</button>
                <a href="{{ route('tickets.index', ['client_id' => $selectedClientId]) }}" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border-subtle);text-decoration:none;color:var(--text-muted);">Clear</a>
            </div>
        </form>
    @else
        <div style="background:rgba(245,158,11,0.14);border:1px solid rgba(245,158,11,0.4);color:var(--warning-text);border-radius:12px;padding:12px 14px;margin-bottom:16px;">
            No client assignments were found for your account.
        </div>
    @endif

    @if($error)
        <div style="background:rgba(245,158,11,0.14);border:1px solid rgba(245,158,11,0.4);color:var(--warning-text);border-radius:12px;padding:12px 14px;margin-bottom:16px;">
            {{ $error }}
        </div>
    @endif

    <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;overflow:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="background:var(--surface-muted);text-align:left;">
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">ID</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Summary</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Service</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Type</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Status</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Updated</th>
                </tr>
            </thead>
            <tbody id="support-ticket-table-body">
                @forelse($tickets as $ticket)
                    <tr onclick="window.location='{{ route('tickets.show', ['ticketId' => $ticket['id'] ?? 0, 'client_id' => $selectedClientId]) }}'" style="cursor:pointer;">
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['id'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['summary'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['service'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['type'] ?? 'Unknown' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['status'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['updated'] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding:14px 12px;color:var(--text-muted);">No support requests were found for this filter.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px;">
        <div style="color:var(--text-muted);font-size:13px;">Page <span id="support-ticket-current-page">{{ $page }}</span> (25 per load)</div>
        @if($hasMore)
            <button id="support-ticket-load-more" class="btn-accent" type="button">Load Next 25</button>
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

        const selectedClientId = @json($selectedClientId);
        const buildRow = (row) => `
            <tr onclick="window.location='{{ url('/tickets') }}/${row.id}?client_id=${selectedClientId}'" style="cursor:pointer;">
                <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">${row.id}</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">${row.summary}</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">${row.service}</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">${row.type}</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">${row.status}</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">${row.updated}</td>
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
