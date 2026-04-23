@extends('layouts.app')

@section('content')
<div style="max-width:900px;">
    <h1 class="dd-page-title" style="font-size:1.6rem;margin-bottom:12px;">Log Support Ticket</h1>
    <p style="margin:0 0 18px;color:var(--text-muted);">Create a HaloPSA request for Domain, Web Hosting, or SSL services.</p>

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

    <form method="POST" action="{{ route('tickets.store') }}" style="background:var(--surface-elevated);border:1px solid var(--border-subtle);border-radius:14px;padding:18px;display:grid;gap:14px;">
        @csrf

        <div>
            <label for="ticket_subject" style="display:block;font-weight:600;margin-bottom:6px;">Subject</label>
            <input id="ticket_subject" type="text" name="subject" value="{{ old('subject') }}" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
        </div>

        <div>
            <label for="ticket_message" style="display:block;font-weight:600;margin-bottom:6px;">Message</label>
            <textarea id="ticket_message" name="message" rows="5" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">{{ old('message') }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
            <div>
                <label for="client_id" style="display:block;font-weight:600;margin-bottom:6px;">Client</label>
                <select id="client_id" name="client_id" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) old('client_id', $clients->first()?->id) === $client->id)>
                            {{ $client->business_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="ticket_type" style="display:block;font-weight:600;margin-bottom:6px;">Ticket Type</label>
                <select id="ticket_type" name="ticket_type" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    @foreach($ticketTypes as $ticketType)
                        <option value="{{ $ticketType }}" @selected(old('ticket_type', 'Support/Issue') === $ticketType)>{{ $ticketType }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="sub_category" style="display:block;font-weight:600;margin-bottom:6px;">Sub-category</label>
                <select id="sub_category" name="sub_category" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    @foreach($subCategories as $subCategory)
                        <option value="{{ $subCategory }}" @selected(old('sub_category', 'Domain') === $subCategory)>{{ $subCategory }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
            <div>
                <label for="reference_type" style="display:block;font-weight:600;margin-bottom:6px;">Reference Type</label>
                <select id="reference_type" name="reference_type" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
                    <option value="domain" @selected(old('reference_type', 'domain') === 'domain')>Domain</option>
                    <option value="service" @selected(old('reference_type') === 'service')>Web Hosting</option>
                    <option value="ssl" @selected(old('reference_type') === 'ssl')>SSL</option>
                </select>
            </div>

            <div>
                <label for="reference_id" style="display:block;font-weight:600;margin-bottom:6px;">Reference Item</label>
                <select id="reference_id" name="reference_id" required style="width:100%;border:1px solid var(--border-subtle);border-radius:10px;padding:9px 11px;background:var(--bg);color:var(--text);">
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

        <div>
            <button type="submit" class="btn-accent">Submit Ticket</button>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const clientSelect = document.getElementById('client_id');
        const referenceTypeSelect = document.getElementById('reference_type');
        const referenceSelect = document.getElementById('reference_id');

        const syncReferenceOptions = () => {
            const selectedClientId = clientSelect.value;
            const selectedReferenceType = referenceTypeSelect.value;
            let firstEnabledOption = null;

            for (const option of referenceSelect.options) {
                const visible = option.dataset.referenceType === selectedReferenceType
                    && option.dataset.clientId === selectedClientId;
                option.hidden = !visible;
                option.disabled = !visible;

                if (visible && firstEnabledOption === null) {
                    firstEnabledOption = option;
                }
            }

            if (!referenceSelect.selectedOptions.length || referenceSelect.selectedOptions[0].disabled) {
                if (firstEnabledOption) {
                    firstEnabledOption.selected = true;
                }
            }
        };

        clientSelect.addEventListener('change', syncReferenceOptions);
        referenceTypeSelect.addEventListener('change', syncReferenceOptions);
        syncReferenceOptions();
    });
</script>
@endsection
