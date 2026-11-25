<div class="dd-set-split">
    <x-ui.card title="PPPoE realms" description="Offered as the NBN domain on order and transfer forms. The username sent to Vocus is username@realm. Changes save immediately.">
        <x-ui.mapping-table
            id="vocus-realms-table"
            url="{{ route('admin.settings.mappings.index', 'vocus.realms') }}"
            :columns="[['key' => 'realm', 'label' => 'Realm']]"
            :fields="[['key' => 'realm', 'label' => 'Realm', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. wba.example.com.au', 'help' => 'A hostname such as example.com.au.']]"
            :labels="['title' => 'Realm', 'add' => 'Add', 'empty' => 'No realms yet.', 'entity' => 'realm']" />
    </x-ui.card>

    <x-ui.card title="Service scopes" description="Only enabled scopes appear on the order and transfer forms.">
        <p class="dd-set-note">{{ implode(', ', \App\Services\Vocus\VocusOptions::enabledScopes()) }}</p>
        <x-slot name="footer">
            <div class="dd-set-actions"><button type="button" class="ui-btn ui-btn--secondary" data-modal-open="vocus-scopes-modal">Edit scopes</button></div>
        </x-slot>
    </x-ui.card>
</div>

@php
    $__vo = \App\Services\Vocus\VocusOptions::class;
    $__od = $__vo::orderDefaults();
    $__cpeOptions = function (string $tech) use ($__vo) {
        $out = ['' => 'None'];
        foreach ($__vo::NBN_CPE_PLANS[$tech] ?? [] as $value => $cpe) {
            if (($cpe[2] ?? false) !== true) {
                $out[$value] = $cpe[0] . ' (' . $value . ')';
            }
        }

        return $out;
    };
@endphp
<x-settings.form-card area="vocus" tab="ordering" title="Order defaults" description="What the order and transfer forms pre-select. Each order can still change them." save="Save order defaults">
    <div class="dd-set-form">
        <x-settings.select label="Authentication type (AuthType)" name="vocus[order_defaults][auth_type]" id="vo-def-auth"
            :options="array_combine($__vo::AUTH_TYPES, $__vo::AUTH_TYPES)" :value="$__od['auth_type']" help="Reseller-Connect only." />
        <x-settings.select label="IP type (IPAssignment)" name="vocus[order_defaults][ip_assignment]" id="vo-def-ip"
            :options="array_combine($__vo::IP_ASSIGNMENTS, $__vo::IP_ASSIGNMENTS)" :value="$__od['ip_assignment']" help="Reseller-Connect only." />
        <x-settings.select label="NBN service level" name="vocus[order_defaults][service_level]" id="vo-def-level"
            :options="array_combine($__vo::SERVICE_LEVELS, $__vo::SERVICE_LEVELS)" :value="$__od['service_level']" help="Home tier plans always use STANDARD." />
        @foreach ($__vo::CPE_TECHNOLOGIES as $__tech)
            <x-settings.select :label="'NBN installation option for ' . $__tech" :name="'vocus[order_defaults][nbn_cpe][' . $__tech . ']'" :id="'vo-def-cpe-' . strtolower($__tech)"
                :options="$__cpeOptions($__tech)" :value="$__od['nbn_cpe'][$__tech]" help="NBNCPEPlanID. Options that ship hardware are not offered." />
        @endforeach
    </div>
</x-settings.form-card>
