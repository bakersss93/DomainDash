@extends('layouts.app')

@section('content')
@php
    $ticketSummary = $ticket['summary'] ?? $ticket['Summary'] ?? 'Support ticket details';
    if (!is_string($ticketSummary) || trim($ticketSummary) === '') {
        $ticketSummary = 'Support ticket details';
    }

    $ticketStatus = $ticket['status_name'] ?? $ticket['StatusName'] ?? $ticket['status'] ?? '-';
    if (is_array($ticketStatus)) {
        $ticketStatus = $ticketStatus['name'] ?? $ticketStatus['Name'] ?? '-';
    }
    $ticketStatus = is_string($ticketStatus) ? $ticketStatus : '-';

    $ticketType = $ticket['tickettype_name'] ?? $ticket['TicketTypeName'] ?? $ticket['tickettype'] ?? '-';
    if (is_array($ticketType)) {
        $ticketType = $ticketType['name'] ?? $ticketType['Name'] ?? '-';
    }
    $ticketType = is_string($ticketType) ? $ticketType : '-';

    $ticketUpdated = $ticket['lastactiondate'] ?? $ticket['LastActionDate'] ?? $ticket['datecreated'] ?? '-';
    if (is_array($ticketUpdated)) {
        $ticketUpdated = '-';
    }
    $ticketUpdated = is_string($ticketUpdated) ? $ticketUpdated : '-';
@endphp
<div style="max-width:1080px;display:grid;gap:14px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div>
            <h1 class="dd-page-title" style="font-size:1.6rem;margin-bottom:6px;">Ticket #{{ $ticketId }}</h1>
            <p style="margin:0;color:var(--text-muted);">{{ $ticketSummary }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="{{ route('tickets.index', ['client_id' => $selectedClientId]) }}" style="padding:9px 12px;border-radius:10px;border:1px solid var(--border-subtle);text-decoration:none;color:var(--text-muted);">Back to tickets</a>
            @if($portalUrl)
                <a href="{{ $portalUrl }}" target="_blank" rel="noopener noreferrer" class="btn-accent" style="text-decoration:none;">Open in Client Portal</a>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div style="background:rgba(16,185,129,0.14);border:1px solid rgba(16,185,129,0.45);color:#10b981;border-radius:12px;padding:12px 14px;">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.35);color:#ef4444;border-radius:12px;padding:12px 14px;">
            <strong>Unable to complete action.</strong>
            <ul style="margin:8px 0 0 18px;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:12px 14px;display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
        <div><strong>Client</strong><br>{{ $selectedClient->business_name }}</div>
        <div><strong>Status</strong><br>{{ $ticketStatus }}</div>
        <div><strong>Type</strong><br>{{ $ticketType }}</div>
        <div><strong>Last Update</strong><br>{{ $ticketUpdated }}</div>
    </div>

    <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;overflow:hidden;">
        <div style="padding:12px 14px;border-bottom:1px solid var(--border-subtle);font-weight:600;">Client Visible Communications / Email Actions</div>
        <div style="max-height:460px;overflow:auto;">
            @forelse($actions as $action)
                @php
                    $isClientVisible = (bool) ($action['clientvisible'] ?? $action['ClientVisible'] ?? $action['is_client_visible'] ?? true);
                    $actionAuthor = $action['agent_name'] ?? $action['AgentName'] ?? $action['who'] ?? 'Update';
                    if (!is_string($actionAuthor) || trim($actionAuthor) === '') {
                        $actionAuthor = 'Update';
                    }

                    $actionDate = $action['datecreated'] ?? $action['DateCreated'] ?? $action['date'] ?? '-';
                    if (!is_string($actionDate) || trim($actionDate) === '') {
                        $actionDate = '-';
                    }

                    $actionDetails = $action['details'] ?? $action['Details'] ?? $action['note'] ?? '-';
                    if (is_array($actionDetails)) {
                        $actionDetails = $actionDetails['text'] ?? $actionDetails['Text'] ?? '-';
                    }
                    if (!is_string($actionDetails) || trim($actionDetails) === '') {
                        $actionDetails = '-';
                    }
                @endphp
                @if($isClientVisible)
                    <div style="padding:12px 14px;border-bottom:1px solid var(--border-subtle);display:grid;gap:6px;">
                        <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                            <strong>{{ $actionAuthor }}</strong>
                            <span style="color:var(--text-muted);font-size:12px;">{{ $actionDate }}</span>
                        </div>
                        <div style="white-space:pre-wrap;">{{ $actionDetails }}</div>
                    </div>
                @endif
            @empty
                <div style="padding:14px;color:var(--text-muted);">No client-visible communications were returned for this ticket.</div>
            @endforelse
        </div>
    </div>

    <form method="POST" action="{{ route('tickets.reply', ['ticketId' => $ticketId]) }}" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:14px;display:grid;gap:10px;">
        @csrf
        <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
        <label for="ticket_reply" style="font-weight:600;">Reply to technician / provide update</label>
        <textarea id="ticket_reply" name="message" rows="5" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:10px 11px;background:var(--bg);color:var(--text);">{{ old('message') }}</textarea>
        <div>
            <button class="btn-accent" type="submit">Submit Reply</button>
        </div>
    </form>
</div>
@endsection
