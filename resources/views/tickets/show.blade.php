@extends('layouts.app')

@section('content')
@php
    $ticketSummary = $ticket['summary'] ?? $ticket['Summary'] ?? 'Support ticket details';
    if (!is_string($ticketSummary) || trim($ticketSummary) === '') {
        $ticketSummary = 'Support ticket details';
    }

    $ticketStatus = $ticketStatusLabel ?? $ticket['status_name'] ?? $ticket['StatusName'] ?? $ticket['status'] ?? '-';
    if (is_array($ticketStatus)) {
        $ticketStatus = $ticketStatus['name'] ?? $ticketStatus['Name'] ?? '-';
    }
    $ticketStatus = is_string($ticketStatus) ? $ticketStatus : '-';

    $ticketType = $ticketTypeLabel ?? $ticket['tickettype_name'] ?? $ticket['TicketTypeName'] ?? $ticket['tickettype'] ?? '-';
    if (is_array($ticketType)) {
        $ticketType = $ticketType['name'] ?? $ticketType['Name'] ?? '-';
    }
    $ticketType = is_string($ticketType) ? $ticketType : '-';

    $ticketService = $ticketServiceLabel ?? $ticket['category_1'] ?? $ticket['Category1'] ?? $ticket['category1'] ?? '-';
    if (is_array($ticketService)) {
        $ticketService = $ticketService['name'] ?? $ticketService['Name'] ?? '-';
    }
    $ticketService = is_string($ticketService) ? $ticketService : '-';

    $ticketUpdated = $ticketUpdatedLabel ?? $ticket['lastactiondate'] ?? $ticket['LastActionDate'] ?? $ticket['datecreated'] ?? '-';
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
            <button type="button" id="open-reply-modal" class="btn-accent">Submit Reply</button>
            <form method="POST" action="{{ route('tickets.close', ['ticketId' => $ticketId]) }}" style="margin:0;">
                @csrf
                <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                <button type="submit" style="padding:9px 12px;border-radius:10px;border:1px solid rgba(239,68,68,0.45);background:rgba(239,68,68,0.15);color:#ef4444;cursor:pointer;">Close Ticket</button>
            </form>
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

    <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:12px 14px;display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
        <div><strong>Client</strong><br>{{ $selectedClient->business_name }}</div>
        <div><strong>Status</strong><br>{{ $ticketStatus }}</div>
        <div><strong>Service</strong><br>{{ $ticketService }}</div>
        <div><strong>Type</strong><br>{{ $ticketType }}</div>
        <div><strong>Last Update</strong><br>{{ $ticketUpdated }}</div>
    </div>

    <div style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;overflow:hidden;">
        <div style="padding:12px 14px;border-bottom:1px solid var(--border-subtle);font-weight:600;">Client Visible Communications / Email Actions</div>
        <div style="max-height:520px;overflow:auto;padding:10px;">
            @forelse($actions as $action)
                @php
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
                <div style="padding:14px;border:1px solid var(--border-subtle);border-radius:10px;display:grid;gap:8px;margin-bottom:10px;background:rgba(15,23,42,0.36);">
                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                        <strong>{{ $actionAuthor }}</strong>
                        <span style="color:var(--text-muted);font-size:12px;">{{ $actionDate }}</span>
                    </div>
                    <div style="white-space:pre-wrap;">{{ $actionDetails }}</div>
                </div>
            @empty
                <div style="padding:14px;color:var(--text-muted);">No client-visible communications were returned for this ticket.</div>
            @endforelse
        </div>
    </div>

    <div id="reply-modal" style="display:none;position:fixed;inset:0;background:rgba(2,6,23,0.72);z-index:12050;align-items:center;justify-content:center;padding:16px;">
        <form method="POST" action="{{ route('tickets.reply', ['ticketId' => $ticketId]) }}" style="width:min(720px, 96vw);background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:12px;padding:14px;display:grid;gap:10px;">
            @csrf
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                <label for="ticket_reply" style="font-weight:600;">Reply to technician / provide update</label>
                <button type="button" id="close-reply-modal" style="padding:6px 10px;border-radius:8px;border:1px solid var(--border-subtle);background:transparent;color:var(--text-muted);cursor:pointer;">Close</button>
            </div>
            <textarea id="ticket_reply" name="message" rows="8" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:10px 11px;background:var(--bg);color:var(--text);">{{ old('message') }}</textarea>
            <div>
                <button class="btn-accent" type="submit">Submit Reply</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const openButton = document.getElementById('open-reply-modal');
        const closeButton = document.getElementById('close-reply-modal');
        const modal = document.getElementById('reply-modal');

        if (!openButton || !closeButton || !modal) {
            return;
        }

        const openModal = () => {
            modal.style.display = 'flex';
        };
        const closeModal = () => {
            modal.style.display = 'none';
        };

        openButton.addEventListener('click', openModal);
        closeButton.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        @if($errors->any())
            openModal();
        @endif
    });
</script>
@endsection
