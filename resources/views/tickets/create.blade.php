@extends('layouts.app')

@section('content')
<div class="dd-ticket-wrap">
    <h1 class="dd-page-title">Log Support Ticket</h1>
    <p class="dd-ticket-intro">Create a HaloPSA request for Domain, Web Hosting, or SSL services.</p>

    <style>
        .dd-ticket-wrap { max-width: 980px; }
        .dd-ticket-intro { margin: 0 0 18px; color: var(--text-muted); }
        .dd-ticket-card {
            background: var(--surface-elevated);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 18px;
        }
        .dd-ticket-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .dd-ticket-field { display: grid; gap: 6px; }
        .dd-ticket-field label { font-weight: 600; }
        .dd-ticket-field input,
        .dd-ticket-field textarea,
        .dd-ticket-field select {
            width: 100%;
            min-width: 0;
            border: 1px solid var(--border-subtle);
            border-radius: 10px;
            padding: 10px 11px;
            background: var(--bg);
            color: var(--text);
            box-sizing: border-box;
        }
        .dd-ticket-actions { margin-top: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    </style>

    @if($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.35);color:#ef4444;border-radius:12px;padding:12px 14px;margin-bottom:16px;">
            <strong>Unable to submit ticket.</strong>
            <ul style="margin:8px 0 0 18px;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($clients->isEmpty())
        <div class="dd-ticket-card">
            <p style="margin:0;color:var(--warning-text);">No client assignments were found for your account. Please contact an administrator.</p>
        </div>
    @else
        <form method="POST" action="{{ route('tickets.store') }}" class="dd-ticket-card" novalidate>
            @csrf

            <div class="dd-ticket-field" style="margin-bottom:12px;">
                <label for="ticket_subject">Subject</label>
                <input id="ticket_subject" type="text" name="subject" value="{{ old('subject') }}" required>
            </div>

            <div class="dd-ticket-field" style="margin-bottom:12px;">
                <label for="ticket_message">Message</label>
                <textarea id="ticket_message" name="message" rows="5" required>{{ old('message') }}</textarea>
            </div>

            <div class="dd-ticket-grid" style="margin-bottom:12px;">
                <div class="dd-ticket-field">
                    <label for="client_id">Client</label>
                    <select id="client_id" name="client_id" required>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}" @selected((int) old('client_id', $clients->first()->id) === $client->id)>
                                {{ $client->business_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="dd-ticket-field">
                    <label for="ticket_type">Ticket Type</label>
                    <select id="ticket_type" name="ticket_type" required>
                        @foreach($ticketTypes as $ticketType)
                            <option value="{{ $ticketType }}" @selected(old('ticket_type', 'Support/Issue') === $ticketType)>{{ $ticketType }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="dd-ticket-grid">
                <div class="dd-ticket-field">
                    <label for="reference_type">Service Category</label>
                    <select id="reference_type" name="reference_type" required>
                        <option value="domain" @selected(old('reference_type', 'domain') === 'domain')>Domain</option>
                        <option value="service" @selected(old('reference_type') === 'service')>Web Hosting</option>
                        <option value="ssl" @selected(old('reference_type') === 'ssl')>SSL</option>
                    </select>
                </div>

                <div class="dd-ticket-field">
                    <label for="reference_id">Related Service</label>
                    <select id="reference_id" name="reference_id" required>
                        @foreach($domains as $domain)
                            <option data-reference-type="domain" data-client-id="{{ $domain->client_id }}" value="{{ $domain->id }}" @selected(old('reference_type', 'domain') === 'domain' && (int) old('reference_id') === $domain->id)>
                                Domain: {{ $domain->name }}
                            </option>
                        @endforeach
                        @foreach($services as $service)
                            <option data-reference-type="service" data-client-id="{{ $service->client_id }}" value="{{ $service->id }}" @selected(old('reference_type') === 'service' && (int) old('reference_id') === $service->id)>
                                Hosting: {{ $service->username ?: 'Service #' . $service->id }}
                            </option>
                        @endforeach
                        @foreach($sslCertificates as $ssl)
                            <option data-reference-type="ssl" data-client-id="{{ $ssl->client_id }}" value="{{ $ssl->id }}" @selected(old('reference_type') === 'ssl' && (int) old('reference_id') === $ssl->id)>
                                SSL: {{ $ssl->common_name ?: 'Certificate #' . $ssl->id }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="dd-ticket-actions">
                <button type="submit" class="btn-accent">Submit Ticket</button>
                <a href="{{ route('tickets.index') }}" style="color:var(--text-muted);text-decoration:none;">View Support Requests</a>
            </div>
        </form>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const clientSelect = document.getElementById('client_id');
        const referenceTypeSelect = document.getElementById('reference_type');
        const referenceSelect = document.getElementById('reference_id');

        if (!clientSelect || !referenceTypeSelect || !referenceSelect) {
            return;
        }

        const syncReferenceOptions = () => {
            const selectedClientId = clientSelect.value;
            const selectedReferenceType = referenceTypeSelect.value;
            let firstEnabledOption = null;

            for (const option of referenceSelect.options) {
                const visible = option.dataset.referenceType === selectedReferenceType
                    && option.dataset.clientId === selectedClientId;
                option.hidden = !visible;
                option.disabled = !visible;

                if (visible && !firstEnabledOption) {
                    firstEnabledOption = option;
                }
            }

            if (!firstEnabledOption) {
                referenceSelect.selectedIndex = -1;
                return;
            }

            if (referenceSelect.selectedIndex < 0 || referenceSelect.selectedOptions[0].disabled) {
                firstEnabledOption.selected = true;
            }
        };

        clientSelect.addEventListener('change', syncReferenceOptions);
        referenceTypeSelect.addEventListener('change', syncReferenceOptions);
        syncReferenceOptions();
    });
</script>
@endsection
