@php
    $haloBilling = \App\Services\Halo\HaloBillingSettings::fromArray((array) old('halo.billing', ($settings['halo']['billing'] ?? [])));
@endphp
<x-settings.form-card area="halopsa" tab="billing" title="Billing for internet services" description="Each Vocus internet service bills through a HaloPSA subscription on the client, attached to a line on the client's recurring invoice. Link every Vocus plan to a Halo item first (Vocus > Plans). DomainDash never creates recurring invoices." save="Save billing settings">
    <div class="dd-set-stack">
        <div>
            <x-settings.check label="Sync subscriptions" name="halo[billing][sync_subscriptions]" :checked="$haloBilling->syncSubscriptions" help="Create or update the client's HaloPSA subscription for each service." />
            <x-settings.check label="Add or update the recurring invoice line" name="halo[billing][sync_lines]" :checked="$haloBilling->syncLines" help="The line that bills the subscription." />
        </div>

        <div class="dd-set-form">
            <x-settings.select label="Which recurring invoice to bill on" name="halo[billing][strategy]" id="halo_billing_strategy"
                :options="['contract' => 'The recurring invoice of the client\'s active contract', 'client' => 'Only the invoice chosen on each client']"
                :value="$haloBilling->strategy"
                help="An invoice chosen on a client (Clients > edit) always wins." :wide="true" />

            <div class="dd-set-field">
                <div data-dd-picker='@json(["source" => route("admin.settings.options", "halo.contract-types"), "label" => "Contract type (optional)", "idInput" => "#halo_billing_contract_type", "placeholder" => "Any contract type", "help" => "Only contracts of this type are considered. HaloPSA has no contract-type list, so types come from your existing contracts.", "emptyHint" => "No contract types found in HaloPSA.", "fallbackLabel" => "Contract type"])'></div>
                <input type="hidden" id="halo_billing_contract_type" name="halo[billing][contract_type_id]" value="{{ $haloBilling->contractTypeId }}">
            </div>

            <x-settings.input label="Contract reference contains (optional)" name="halo[billing][contract_ref]" id="halo_billing_contract_ref" :value="$haloBilling->contractRef" maxlength="120" placeholder="e.g. Managed Services or MS-*" help="Plain text matches anywhere; * and ? are wildcards." />
        </div>
    </div>
</x-settings.form-card>
