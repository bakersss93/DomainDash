@php
    $haloBilling = \App\Services\Halo\HaloBillingSettings::fromArray((array) old('halo.billing', ($settings['halo']['billing'] ?? [])));
    $previewServices = \App\Models\InternetService::with(['client', 'vocusPlan'])->whereNotNull('client_id')->latest('id')->limit(60)->get();
    $placeholders = \App\Services\Halo\HaloLineTemplate::PLACEHOLDERS;
@endphp

<p class="dd-set-note">Each internet service bills through a HaloPSA agreement: DomainDash finds the client's agreement and its recurring invoice, then adds or updates one line on it. Link every Vocus plan to a Halo item first (Vocus &gt; Plans). DomainDash never creates agreements or recurring invoices. The same settings will serve domains, hosting and SSL later.</p>

<x-settings.form-card area="halopsa" tab="billing" title="Agreements" description="Which of the client's HaloPSA agreements a service bills on. Most connections have their own agreement, so the rule is: Contract Type + Agreement Subtype + Agreement Reference." save="Save agreement rule">
    <div class="dd-set-stack">
        <div class="dd-set-form">
            <x-settings.select label="Which recurring invoice to bill on" name="halo[billing][strategy]" id="halo_billing_strategy"
                :options="['contract' => 'Find the client\'s agreement with the rule below', 'client' => 'Only the invoice chosen on each client']"
                :value="$haloBilling->strategy"
                help="An invoice chosen on a service always wins. The invoice chosen on a client is the fallback when the rule finds nothing usable." :wide="true" />

            <div class="dd-set-field">
                <div data-dd-picker="{{ json_encode(["source" => route("admin.settings.options", "halo.contract-types"), "label" => "Contract Type (optional)", "idInput" => "#halo_billing_contract_type", "placeholder" => "Any contract type", "help" => "Types come from your existing agreements.", "emptyHint" => "No contract types found in HaloPSA.", "fallbackLabel" => "Contract type"]) }}"></div>
                <input type="hidden" id="halo_billing_contract_type" name="halo[billing][contract_type_id]" value="{{ $haloBilling->contractTypeId }}">
            </div>

            <div class="dd-set-field">
                <div data-dd-picker="{{ json_encode(["source" => route("admin.settings.options", "halo.contract-subtypes"), "label" => "Agreement Subtype (optional)", "idInput" => "#halo_billing_contract_subtype", "placeholder" => "Any subtype", "help" => "Subtypes without a name show their id and an example agreement. Add the lookup id below to see names.", "emptyHint" => "No agreement subtypes found in HaloPSA.", "fallbackLabel" => "Agreement subtype"]) }}"></div>
                <input type="hidden" id="halo_billing_contract_subtype" name="halo[billing][contract_subtype_id]" value="{{ $haloBilling->contractSubtypeId }}">
            </div>

            <x-settings.input label="Agreement Reference matches (optional)" name="halo[billing][contract_ref]" id="halo_billing_contract_ref" :value="$haloBilling->contractRef" maxlength="120" placeholder="e.g. Connectivity - {plan} - {client_code}" wide="true"
                help="Plain text matches anywhere; * and ? are wildcards. Placeholders such as {plan}, {plan_id}, {client_code}, {service_id}, {username}, {avc_id} are filled from the service. A client with several agreements bills each service on the one whose reference names it." />

            <x-settings.input label="Halo lookup id of the subtype list (optional)" name="halo[billing][subtype_lookup_id]" id="halo_billing_subtype_lookup" type="number" min="1" :value="$haloBilling->subtypeLookupId"
                help="Halo does not return subtype names with agreements. If you know the lookup id of the Agreement Subtype list, subtype names are read from it." />
        </div>
    </div>
</x-settings.form-card>

<x-settings.form-card area="halopsa" tab="billing" title="Recurring invoice lines" description="The line DomainDash adds to the agreement's recurring invoice for each service: the plan's Halo item, the sell price, quantity 1, and the text below." save="Save line settings">
    <div class="dd-set-stack">
        <div>
            <x-settings.check label="Add or update the recurring invoice line" name="halo[billing][sync_lines]" :checked="$haloBilling->syncLines" help="Off: only subscriptions are synced." />
        </div>
        <div class="dd-set-form dd-set-form--one">
            <div class="dd-set-field">
                <label for="halo_billing_line_description" class="dd-set-label">Description</label>
                <input id="halo_billing_line_description" type="text" class="ui-input" name="halo[billing][line_description]" maxlength="255" value="{{ old('halo.billing.line_description', $haloBilling->lineDescription) }}" aria-describedby="halo_billing_line_description_help">
                <small id="halo_billing_line_description_help" class="dd-set-help">Halo item_shortdescription. Leave empty for the default.</small>
            </div>
            <div class="dd-set-field">
                <label for="halo_billing_line_long_description" class="dd-set-label">Long description</label>
                <textarea id="halo_billing_line_long_description" class="ui-input" name="halo[billing][line_long_description]" rows="5" maxlength="2000" aria-describedby="halo_billing_line_long_description_help">{{ old('halo.billing.line_long_description', $haloBilling->lineLongDescription) }}</textarea>
                <small id="halo_billing_line_long_description_help" class="dd-set-help">Halo item_longdescription. One line per row. Leave empty for the default.</small>
            </div>
            <div class="dd-set-field">
                <span class="dd-set-label">Placeholders</span>
                <p class="dd-set-help" style="margin:0">
                    @foreach ($placeholders as $key => $label)<code title="{{ $label }}">{{ '{' . $key . '}' }}</code> @endforeach
                </p>
                <small class="dd-set-help">Halo's own variables, such as <code>$PERIODSTARTDATE</code> and <code>$PERIODENDDATE</code> (and the agreement, recurring billing and asset variables Halo offers), are sent unchanged; Halo fills them in when it invoices.</small>
            </div>
        </div>
    </div>
</x-settings.form-card>

<x-settings.form-card area="halopsa" tab="billing" title="Subscriptions" description="A HaloPSA subscription per service on the client, attached to the recurring invoice line." save="Save subscription setting">
    <x-settings.check label="Sync subscriptions" name="halo[billing][sync_subscriptions]" :checked="$haloBilling->syncSubscriptions" help="Create or update the client's HaloPSA subscription for each service. Off by default, so nothing is pushed until you turn it on." />
</x-settings.form-card>

<x-ui.card title="Preview" description="Shows the line text and the agreement a service would bill on, using the values above even if not saved yet. Nothing is sent to HaloPSA." data-dd-billing-preview data-url="{{ route('admin.settings.halo.billing.preview') }}">
    <div class="dd-set-fieldrow">
        <select class="ui-input" data-preview-service aria-label="Service to preview">
            @forelse ($previewServices as $svc)
                <option value="{{ $svc->id }}">{{ trim(($svc->vocus_service_id ?: '#' . $svc->id) . ' - ' . ($svc->client?->business_name ?: $svc->client?->name) . ' - ' . ($svc->vocusPlan?->name ?: $svc->plan_id)) }}</option>
            @empty
                <option value="">No internet services with a client yet</option>
            @endforelse
        </select>
        <button type="button" class="ui-btn ui-btn--secondary" data-preview-run @disabled($previewServices->isEmpty())>Preview</button>
    </div>
    <div data-preview-out role="status" aria-live="polite" style="margin-top:.75rem"></div>
</x-ui.card>

<script>
(function () {
    var box = document.querySelector('[data-dd-billing-preview]');
    if (!box) { return; }
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var out = box.querySelector('[data-preview-out]');
    function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
    function row(label, text, mono) {
        var wrap = document.createElement('div'); wrap.style.marginBottom = '.5rem';
        var l = document.createElement('div'); l.className = 'dd-set-label'; l.textContent = label;
        var v = document.createElement('div'); v.className = 'dd-set-pre'; v.style.maxWidth = 'none'; v.style.padding = '.5rem .75rem';
        v.style.background = 'var(--dd-surface-muted)'; v.style.border = '1px solid var(--dd-border)'; v.style.borderRadius = 'var(--dd-radius)';
        if (mono) { v.style.fontFamily = 'ui-monospace, monospace'; }
        v.textContent = text || '(empty)';
        wrap.appendChild(l); wrap.appendChild(v);
        return wrap;
    }
    box.querySelector('[data-preview-run]').addEventListener('click', function () {
        var id = box.querySelector('[data-preview-service]').value;
        if (!id) { return; }
        out.textContent = 'Working...';
        fetch(box.dataset.url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({
                service_id: parseInt(id, 10),
                line_description: val('halo_billing_line_description') || null,
                line_long_description: val('halo_billing_line_long_description') || null,
                strategy: val('halo_billing_strategy') || null,
                contract_type_id: val('halo_billing_contract_type') || null,
                contract_subtype_id: val('halo_billing_contract_subtype') || null,
                contract_ref: val('halo_billing_contract_ref') || null
            })
        }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; }); })
        .then(function (r) {
            out.textContent = '';
            if (!r.ok) { var e = r.json.errors ? Object.values(r.json.errors).flat()[0] : null; out.textContent = e || r.json.message || 'Could not build the preview.'; return; }
            var j = r.json;
            out.appendChild(row('Description', j.description));
            out.appendChild(row('Long description', j.long_description));
            out.appendChild(row('Item and price', (j.item || 'No Halo item linked') + ', quantity 1, price ' + Number(j.price).toFixed(2)));
            out.appendChild(row('Agreement', j.agreement.message));
            if (j.note) { out.appendChild(row('Note', j.note)); }
        }).catch(function () { out.textContent = 'Could not reach the server.'; });
    });
})();
</script>
