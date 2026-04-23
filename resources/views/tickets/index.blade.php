@extends('layouts.app')

@section('content')
<div style="max-width:1000px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
        <div>
            <h1 class="dd-page-title" style="font-size:1.6rem;margin-bottom:10px;">Support Requests</h1>
            <p style="margin:0 0 14px;color:var(--text-muted);">Viewing HaloPSA tickets for the selected client.</p>
        </div>
        <a href="{{ route('tickets.create') }}" class="btn-accent" style="text-decoration:none;">Log Support Ticket</a>
    </div>

    <form method="GET" action="{{ route('tickets.index') }}" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:12px 14px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div style="min-width:300px;flex:1;">
            <label for="client_id" style="display:block;font-weight:600;margin-bottom:6px;">Client</label>
            <select id="client_id" name="client_id" style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" @selected($selectedClientId === $client->id)>{{ $client->business_name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-accent" type="submit">Refresh</button>
    </form>

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
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Type</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Status</th>
                    <th style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">Updated</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tickets as $ticket)
                    @php
                        $ticketTypeLabel = $ticket['tickettype_name'] ?? $ticket['TicketTypeName'] ?? $ticket['tickettype'] ?? $ticket['TicketType'] ?? 'Unknown';
                    @endphp
                    <tr>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['id'] ?? $ticket['Id'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['summary'] ?? $ticket['Summary'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticketTypeLabel }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['status_name'] ?? $ticket['StatusName'] ?? $ticket['status'] ?? '-' }}</td>
                        <td style="padding:10px 12px;border-bottom:1px solid var(--border-subtle);">{{ $ticket['lastactiondate'] ?? $ticket['LastActionDate'] ?? $ticket['datecreated'] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:14px 12px;color:var(--text-muted);">No support requests were found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
