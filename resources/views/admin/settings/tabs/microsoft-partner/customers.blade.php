@php
    $filter = in_array(request('filter'), ['all', 'unmapped', 'mapped'], true) ? request('filter') : 'all';
    $q = trim((string) request('q', ''));

    $query = \App\Models\PartnerCustomerTenant::query()->with('client:id,business_name,microsoft_tenant_id')->orderBy('display_name');
    if ($filter === 'mapped') {
        $query->whereHas('client');
    } elseif ($filter === 'unmapped') {
        $query->whereDoesntHave('client');
    }
    if ($q !== '') {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $query->where(fn ($w) => $w->where('display_name', 'like', $like)->orWhere('default_domain', 'like', $like)->orWhere('tenant_id', 'like', $like));
    }
    $tenants = $query->paginate(50)->appends(['tab' => 'customers', 'filter' => $filter, 'q' => $q]);

    $total = \App\Models\PartnerCustomerTenant::count();
    $mappedCount = \App\Models\PartnerCustomerTenant::whereHas('client')->count();
    $allClients = \App\Models\Client::query()->orderBy('business_name')->get(['id', 'business_name', 'microsoft_tenant_id']);
    $suggester = app(\App\Services\Microsoft\Partner\PartnerTenantSuggester::class);
    $unmappedClients = $allClients->filter(fn ($c) => trim((string) $c->microsoft_tenant_id) === '')->values();
@endphp
@if (session('partner_error'))
    <x-ui.alert variant="danger" title="Microsoft Partner">{{ session('partner_error') }}</x-ui.alert>
@endif

<x-ui.card title="Customer tenants" :description="$total.' tenants synced, '.$mappedCount.' mapped to a DomainDash client. Mapping a tenant lets that client\'s users sign in with Microsoft from that tenant only. Changing a mapping signs the client\'s users out.'">
    <form method="GET" action="{{ route('admin.settings.area', 'microsoft-partner') }}" class="dd-set-actions" style="justify-content:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
        <input type="hidden" name="tab" value="customers">
        <input type="search" name="q" value="{{ $q }}" class="ui-input" style="max-width:260px;" placeholder="Search name, domain or tenant ID" aria-label="Search customer tenants">
        <select name="filter" class="ui-input" style="max-width:160px;" aria-label="Filter">
            @foreach (['all' => 'All tenants', 'unmapped' => 'Not mapped', 'mapped' => 'Mapped'] as $v => $l)
                <option value="{{ $v }}" @selected($filter === $v)>{{ $l }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
    </form>
    <form method="POST" action="{{ route('admin.partner.sync') }}" style="margin-bottom:12px;">@csrf
        <x-ui.button type="submit" variant="secondary" :disabled="! \App\Services\Microsoft\Partner\PartnerSettings::configured()">Sync now</x-ui.button>
    </form>

    @if ($total === 0)
        <x-ui.empty-state title="No customer tenants yet" description="Configure the connection, then use Sync now." />
    @else
        <datalist id="partner-clients">
            @foreach ($allClients as $c)
                <option value="{{ $c->business_name }} (#{{ $c->id }})"></option>
            @endforeach
        </datalist>

        <x-ui.table>
            <thead>
                <tr><th>Customer tenant</th><th>Default domain</th><th>DomainDash client</th><th>Action</th></tr>
            </thead>
            <tbody>
                @forelse ($tenants as $t)
                    @php
                        $mapped = $t->client;
                        $suggestion = $mapped ? null : $suggester->suggest($t, $unmappedClients);
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $t->display_name ?? 'Unnamed tenant' }}</strong>
                            @if ($t->relationship_status === 'not_seen') <x-ui.badge variant="warning">Not in last sync</x-ui.badge> @endif
                            <div class="dd-set-help" style="font-family:monospace;">{{ $t->tenant_id }}</div>
                        </td>
                        <td>{{ $t->default_domain ?? '-' }}</td>
                        <td>
                            @if ($mapped)
                                <x-ui.badge variant="success">Mapped</x-ui.badge>
                                <a href="{{ route('admin.clients.edit', $mapped) }}">{{ $mapped->business_name }}</a>
                            @elseif ($suggestion)
                                <x-ui.badge variant="info">Suggested</x-ui.badge>
                                {{ $suggestion['client']->business_name }}
                                <div class="dd-set-help">{{ $suggestion['reason'] }}</div>
                            @else
                                <span class="dd-set-help">No suggestion</span>
                            @endif
                        </td>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                                @if ($suggestion)
                                    <form method="POST" action="{{ route('admin.partner.map', $t) }}">@csrf
                                        <input type="hidden" name="client" value="{{ $suggestion['client']->id }}">
                                        <x-ui.button type="submit" size="sm">Confirm suggestion</x-ui.button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.partner.map', $t) }}" style="display:flex;gap:6px;">@csrf
                                    <input type="text" name="client" list="partner-clients" class="ui-input" style="min-width:180px;" placeholder="{{ $mapped ? 'Change client' : 'Choose client' }}" aria-label="Client for {{ $t->display_name }}" autocomplete="off">
                                    <x-ui.button type="submit" variant="secondary" size="sm">{{ $mapped ? 'Change' : 'Map' }}</x-ui.button>
                                </form>
                                @if ($mapped)
                                    <form method="POST" action="{{ route('admin.partner.clear', $t) }}" onsubmit="return confirm('Remove this mapping? The client\'s users will be signed out.');">@csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="danger" size="sm">Clear</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="dd-set-help">No tenants match.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <div style="margin-top:12px;">{{ $tenants->links() }}</div>
    @endif
</x-ui.card>
