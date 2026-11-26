@php
    $__attention = \App\Http\Controllers\Admin\VocusSettingsController::ATTENTION_STATES;
    $__show = request('records') === 'all' ? 'all' : 'attention';
    $__q = trim((string) request('q', ''));
    $__records = \App\Models\InternetService::query()
        ->with('client:id,business_name,name')
        ->when($__show === 'attention', fn ($q) => $q->where(fn ($w) => $w->whereIn('last_transaction_state', $__attention)
            ->orWhere(fn ($n) => $n->whereNull('vocus_service_id')->orWhere('vocus_service_id', ''))))
        ->when($__q !== '', fn ($q) => $q->where(fn ($w) => $w->where('vocus_service_id', 'like', '%' . $__q . '%')
            ->orWhere('address_long', 'like', '%' . $__q . '%')->orWhere('directory_id', 'like', '%' . $__q . '%')))
        ->latest('id')->limit(200)->get();
@endphp

<x-ui.card title="Service records" description="Remove internet service records that failed or were only tests. Removing a record deletes it from DomainDash only: nothing is sent to Vocus, so a live service is not cancelled (use Cancel on the service page for that). Every removal is recorded in the Audit Logs.">
    <form method="GET" action="{{ url('/admin/settings/vocus') }}" class="dd-set-fieldrow" style="margin-bottom:12px;flex-wrap:wrap;" role="search">
        <input type="hidden" name="tab" value="records">
        <label class="sr-only" for="vr-show">Show</label>
        <select id="vr-show" name="records" class="ui-input" style="max-width:260px;">
            <option value="attention" @selected($__show === 'attention')>Needs attention (failed, unknown, expired, withdrawn)</option>
            <option value="all" @selected($__show === 'all')>All records</option>
        </select>
        <label class="sr-only" for="vr-q">Search</label>
        <input id="vr-q" type="search" name="q" value="{{ $__q }}" class="ui-input" placeholder="Service ID, LOC ID or address" style="max-width:280px;">
        <button type="submit" class="ui-btn ui-btn--secondary">Show</button>
    </form>

    @if ($__records->isEmpty())
        <p class="dd-set-note">{{ $__show === 'attention' ? 'No records need attention.' : 'No records match.' }}</p>
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr><th scope="col">Service ID</th><th scope="col">Address</th><th scope="col">Transaction</th><th scope="col">Status</th><th scope="col">Client</th><th scope="col">Created</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($__records as $__r)
                        @php
                            $__label = $__r->vocus_service_id ?: '#' . $__r->id;
                            $__bad = in_array($__r->last_transaction_state, $__attention, true);
                            $__halo = $__r->halo_subscription_id || $__r->halo_recurring_line_id;
                        @endphp
                        <tr>
                            <td><a href="{{ route('admin.services.internet.show', $__r) }}" style="font-family:monospace;">{{ $__label }}</a></td>
                            <td>{{ $__r->address_long ?: ($__r->location_reference ? 'PC: ' . $__r->location_reference : '-') }}</td>
                            <td>
                                @if ($__r->last_transaction_state)
                                    <x-ui.badge :variant="$__bad ? 'danger' : 'neutral'">{{ $__r->last_transaction_state }}</x-ui.badge>
                                    @if ($__r->last_fault_code)<code style="font-size:11px;">{{ $__r->last_fault_code }}</code>@endif
                                @else - @endif
                            </td>
                            <td>{{ $__r->service_status ?: '-' }}</td>
                            <td>{{ $__r->client?->business_name ?: ($__r->client?->name ?: '-') }}</td>
                            <td>{{ $__r->created_at?->format('d M Y') }}</td>
                            <td style="text-align:right;">
                                <button type="button" class="ui-btn ui-btn--danger ui-btn--sm" data-record-remove data-modal-open="vocus-record-remove-modal"
                                        data-url="{{ route('admin.settings.vocus.services.destroy', $__r) }}" data-label="{{ $__label }}" data-halo="{{ $__halo ? '1' : '0' }}"
                                        data-active="{{ strtoupper((string) $__r->service_status) === 'ACTIVE' && ! $__bad ? '1' : '0' }}">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($__records->count() === 200)<p class="dd-set-note">Showing the newest 200. Search to narrow the list.</p>@endif
    @endif
</x-ui.card>

<x-ui.modal id="vocus-record-remove-modal" title="Remove service record">
    <form method="POST" id="vr-form" action="" style="padding:16px 20px;">
        @csrf
        @method('DELETE')
        <p style="font-size:13px;margin:0 0 10px;">This removes <strong id="vr-label"></strong> from DomainDash. Nothing is sent to Vocus.</p>
        {{-- Wrappers carry [hidden]: the alert's own display would override the attribute. --}}
        <div id="vr-active" hidden><x-ui.alert variant="warning" style="margin-bottom:10px;">This record shows ACTIVE. Removing it does not cancel the service at Vocus, and it may come back with the next Vocus sync or import.</x-ui.alert></div>
        <div id="vr-halo" hidden><x-ui.alert variant="warning" style="margin-bottom:10px;">This record has a HaloPSA subscription or invoice line. It is not removed from HaloPSA.</x-ui.alert></div>
        <label for="vr-confirm" class="dd-set-label">Type the service ID to confirm</label>
        <input id="vr-confirm" name="confirm" type="text" class="ui-input" autocomplete="off" spellcheck="false" required style="width:100%;font-family:monospace;">
    </form>
    <x-slot:footer>
        <button type="button" class="ui-btn ui-btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" form="vr-form" id="vr-submit" class="ui-btn ui-btn--danger" disabled>Remove record</button>
    </x-slot:footer>
</x-ui.modal>

<script>
(function () {
    var form = document.getElementById('vr-form'), input = document.getElementById('vr-confirm'), submit = document.getElementById('vr-submit');
    if (!form) { return; }
    var expected = '';
    document.querySelectorAll('[data-record-remove]').forEach(function (b) {
        b.addEventListener('click', function () {
            expected = b.getAttribute('data-label');
            form.action = b.getAttribute('data-url');
            document.getElementById('vr-label').textContent = expected;
            document.getElementById('vr-active').hidden = b.getAttribute('data-active') !== '1';
            document.getElementById('vr-halo').hidden = b.getAttribute('data-halo') !== '1';
            input.value = ''; submit.disabled = true;
            // The modal itself is opened by the shared data-modal-open handler (app.js).
            setTimeout(function () { input.focus(); }, 50);
        });
    });
    input.addEventListener('input', function () { submit.disabled = input.value.trim() !== expected; });
})();
</script>
