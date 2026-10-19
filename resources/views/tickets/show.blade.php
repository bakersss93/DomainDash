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
<style>
    .dd-ticket-action-content img {
        max-width: 100%;
        height: auto;
        border-radius: 8px;
    }
</style>
<div class="ui-page" style="display:grid;gap:1rem;">
    <x-ui.page-header :title="'Ticket #'.$ticketId" :subtitle="$ticketSummary">
        <x-slot name="actions">
            <x-ui.button :href="route('tickets.index', ['client_id' => $selectedClientId])" variant="ghost">Back to tickets</x-ui.button>
            @if($portalUrl)
                <x-ui.button :href="$portalUrl" variant="secondary" target="_blank" rel="noopener noreferrer">Open in Client Portal <x-ui.icon name="external" :size="14" /></x-ui.button>
            @endif
            <button type="button" id="open-close-modal" class="ui-btn ui-btn--danger">Close Ticket</button>
            <button type="button" id="open-reply-modal" class="ui-btn ui-btn--primary">Submit Reply</button>
        </x-slot>
    </x-ui.page-header>

    @if($errors->any())
        <x-ui.alert variant="danger"><strong>Unable to complete action.</strong>
            <ul style="margin:8px 0 0 18px;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul></x-ui.alert>
    @endif

    <div class="ui-stat-grid">
        <x-ui.stat label="Client" :value="$selectedClient->business_name" />
        <div class="ui-stat">
            <span class="ui-stat__label">Status</span>
            <span><x-ui.badge :status="$ticketStatus">{{ $ticketStatus }}</x-ui.badge></span>
        </div>
        <x-ui.stat label="Service" :value="$ticketService" />
        <x-ui.stat label="Type" :value="$ticketType" />
        <x-ui.stat label="Last Update" :value="$ticketUpdated" />
    </div>

    <div class="ui-card" style="overflow:hidden;">
        <div class="ui-card__header"><h2 class="ui-card__title">Client Visible Communications / Email Actions</h2></div>
        <div style="max-height:520px;overflow:auto;padding:10px;">
            @forelse($actions as $action)
                @php
                    $actionAuthor = $action['agent_name'] ?? $action['AgentName'] ?? $action['who'] ?? 'Update';
                    if (!is_string($actionAuthor) || trim($actionAuthor) === '') {
                        $actionAuthor = 'Update';
                    }

                    $actionDate = $action['datecreated']
                        ?? $action['DateCreated']
                        ?? $action['date']
                        ?? $action['datetime']
                        ?? $action['DateTime']
                        ?? $action['startdate']
                        ?? $action['StartDate']
                        ?? '-';
                    if (!is_string($actionDate) || trim($actionDate) === '') {
                        $actionDate = '-';
                    }

                    $actionDetailsHtml = $action['_display_note_html'] ?? null;
                    if (!is_string($actionDetailsHtml) || trim($actionDetailsHtml) === '') {
                        $actionDetails = $action['details'] ?? $action['Details'] ?? $action['note'] ?? '-';
                        if (is_array($actionDetails)) {
                            $actionDetails = $actionDetails['text'] ?? $actionDetails['Text'] ?? '-';
                        }
                        if (!is_string($actionDetails) || trim($actionDetails) === '') {
                            $actionDetails = '-';
                        }

                        $actionDetailsHtml = nl2br(e($actionDetails));
                    }
                @endphp
                <div style="padding:0.875rem 1rem;border:1px solid var(--dd-border);border-radius:var(--dd-radius);display:grid;gap:0.5rem;margin-bottom:0.625rem;background:var(--dd-surface-soft);">
                    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                        <strong>{{ $actionAuthor }}</strong>
                        <span class="ui-muted" style="font-size:12px;">{{ $actionDate }}</span>
                    </div>
                    <div class="dd-ticket-action-content" style="white-space:normal;overflow-wrap:anywhere;">{!! $actionDetailsHtml !!}</div>
                </div>
            @empty
                <x-ui.empty-state title="No communications yet" text="No client-visible communications were returned for this ticket." />
            @endforelse
        </div>
    </div>

    <div id="reply-modal" role="dialog" aria-modal="true" aria-labelledby="ticket-reply-title" style="display:none;position:fixed;inset:0;background:var(--dd-backdrop);z-index:12050;align-items:center;justify-content:center;padding:1rem;">
        <form method="POST" action="{{ route('tickets.reply', ['ticketId' => $ticketId]) }}" class="ui-modal__dialog" style="width:min(720px, 96vw);display:grid;gap:0.75rem;">
            @csrf
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                <label for="ticket_reply_editor" id="ticket-reply-title" class="ui-label">Reply to technician / provide update</label>
                <button type="button" id="close-reply-modal" class="ui-btn ui-btn--ghost ui-btn--sm">Close</button>
            </div>
            <input type="hidden" id="ticket_reply" name="message" value="{{ old('message') }}">
            <div id="ticket_reply_editor" contenteditable="true" role="textbox" aria-multiline="true" class="ui-input" style="width:100%;min-height:220px;overflow:auto;"></div>
            <div>
                <button class="ui-btn ui-btn--primary" type="submit">Submit Reply</button>
            </div>
        </form>
    </div>

    <div id="close-modal" role="dialog" aria-modal="true" aria-labelledby="ticket-close-title" style="display:none;position:fixed;inset:0;background:var(--dd-backdrop);z-index:12050;align-items:center;justify-content:center;padding:1rem;">
        <form method="POST" action="{{ route('tickets.close', ['ticketId' => $ticketId]) }}" class="ui-modal__dialog" style="width:min(640px, 96vw);display:grid;gap:0.75rem;">
            @csrf
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                <label for="close_reason_editor" id="ticket-close-title" class="ui-label">Reason for closure</label>
                <button type="button" id="close-close-modal" class="ui-btn ui-btn--ghost ui-btn--sm">Close</button>
            </div>
            <input type="hidden" id="close_reason" name="reason" value="{{ old('reason') }}">
            <div id="close_reason_editor" contenteditable="true" role="textbox" aria-multiline="true" class="ui-input" style="width:100%;min-height:180px;overflow:auto;"></div>
            <div>
                <button type="submit" class="ui-btn ui-btn--danger">Confirm Close Ticket</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const openButton = document.getElementById('open-reply-modal');
        const closeButton = document.getElementById('close-reply-modal');
        const modal = document.getElementById('reply-modal');
        const openCloseButton = document.getElementById('open-close-modal');
        const closeCloseButton = document.getElementById('close-close-modal');
        const closeModal = document.getElementById('close-modal');
        const replyEditor = document.getElementById('ticket_reply_editor');
        const replyInput = document.getElementById('ticket_reply');
        const closeReasonEditor = document.getElementById('close_reason_editor');
        const closeReasonInput = document.getElementById('close_reason');

        if (!openButton || !closeButton || !modal || !openCloseButton || !closeCloseButton || !closeModal || !replyEditor || !replyInput || !closeReasonEditor || !closeReasonInput) {
            return;
        }

        const openReplyModal = () => {
            modal.style.display = 'flex';
        };
        const hideReplyModal = () => {
            modal.style.display = 'none';
        };
        const openCloseModal = () => {
            closeModal.style.display = 'flex';
        };
        const hideCloseModal = () => {
            closeModal.style.display = 'none';
        };

        const syncEditorToInput = (editor, input) => {
            input.value = editor.innerHTML.trim();
        };
        const hydrateEditor = (editor, input) => {
            if (input.value.trim() !== '') {
                editor.innerHTML = input.value;
            }
        };

        hydrateEditor(replyEditor, replyInput);
        hydrateEditor(closeReasonEditor, closeReasonInput);
        syncEditorToInput(replyEditor, replyInput);
        syncEditorToInput(closeReasonEditor, closeReasonInput);

        openButton.addEventListener('click', openReplyModal);
        closeButton.addEventListener('click', hideReplyModal);
        openCloseButton.addEventListener('click', openCloseModal);
        closeCloseButton.addEventListener('click', hideCloseModal);
        replyEditor.addEventListener('input', () => syncEditorToInput(replyEditor, replyInput));
        closeReasonEditor.addEventListener('input', () => syncEditorToInput(closeReasonEditor, closeReasonInput));

        modal.querySelector('form')?.addEventListener('submit', function () {
            syncEditorToInput(replyEditor, replyInput);
        });
        closeModal.querySelector('form')?.addEventListener('submit', function () {
            syncEditorToInput(closeReasonEditor, closeReasonInput);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                hideReplyModal();
            }
        });
        closeModal.addEventListener('click', function (event) {
            if (event.target === closeModal) {
                hideCloseModal();
            }
        });

        @if($errors->any())
            @if(old('reason'))
                openCloseModal();
            @else
                openReplyModal();
            @endif
        @endif
    });
</script>
@endsection
