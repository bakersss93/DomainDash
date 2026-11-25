@extends('layouts.app')

@section('content')
<div class="ui-page">
    <x-ui.page-header title="Internet Services" subtitle="Vocus services, plans, pricing and billing sync.">
        <x-slot name="actions">
            <x-ui.button variant="secondary" onclick="var p=document.getElementById('import-ids-panel');p.style.display=p.style.display==='none'?'block':'none';">Import service IDs</x-ui.button>
            <x-ui.button :href="route('admin.services.internet.qualify')" variant="primary">
                <x-ui.icon name="plus" :size="16" /> New order
            </x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @include('admin.sync._live')
    @include('admin.sync._worker-banner')

    @if(!empty($vocusProblem))
        <x-ui.alert variant="danger" title="Vocus is not ready" style="margin-bottom:1rem;">{{ $vocusProblem }}
            <a href="{{ route('admin.settings.area', 'vocus') }}">Open settings</a>
        </x-ui.alert>
    @endif

    {{-- Toolbar --}}
    <div class="ui-toolbar">
        <form method="GET" action="{{ route('admin.services.internet') }}" class="flex flex-1 flex-wrap items-center gap-2">
            <label for="internet-client-filter" class="sr-only">Filter by client</label>
            <select id="internet-client-filter" name="client_id" class="dd-input" style="flex:0 1 13rem;min-width:10rem;">
                <option value="">All clients</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" {{ (string)($clientId ?? '') === (string)$client->id ? 'selected' : '' }}>
                        {{ $client->business_name ?? $client->name ?? 'Client #'.$client->id }}
                    </option>
                @endforeach
            </select>
            <label for="internet-status-filter" class="sr-only">Filter by status</label>
            <select id="internet-status-filter" name="status" class="dd-input" style="flex:0 1 11rem;">
                <option value="" {{ $statusFilter === '' ? 'selected' : '' }}>All statuses</option>
                <option value="ACTIVE" {{ $statusFilter === 'ACTIVE' ? 'selected' : '' }}>Active</option>
                <option value="SUSPEND" {{ $statusFilter === 'SUSPEND' ? 'selected' : '' }}>Suspended</option>
                <option value="INACTIVE" {{ $statusFilter === 'INACTIVE' ? 'selected' : '' }}>Inactive</option>
            </select>
            <label for="internet-type-filter" class="sr-only">Filter by technology</label>
            <select id="internet-type-filter" name="service_type" class="dd-input" style="flex:0 1 12rem;">
                <option value="" {{ ($typeFilter ?? '') === '' ? 'selected' : '' }}>All technologies</option>
                @foreach(['FTTP','FTTC','FTTB','FTTN','HFC','WIRELESS'] as $type)
                    <option value="{{ $type }}" {{ ($typeFilter ?? '') === $type ? 'selected' : '' }}>{{ $type }}</option>
                @endforeach
            </select>
            <button type="submit" class="ui-btn ui-btn--primary">Filter</button>
        </form>

        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('admin.services.internet.sync') }}" data-vocus-queue>
                @csrf
                <button type="submit" class="ui-btn ui-btn--secondary"
                        onclick="return confirm('Refresh status for all known internet services from Vocus? This runs in the background (about 2.5 seconds per service).');">
                    <x-ui.icon name="refresh" :size="16" /> Sync
                </button>
            </form>
            @if(auth()->user()?->can('sync.run'))
                <form method="POST" action="{{ route('admin.services.internet.halo-sync') }}" data-vocus-queue>
                    @csrf
                    <button type="submit" class="ui-btn ui-btn--secondary"
                            onclick="return confirm('Sync all internet services that have a client to HaloPSA (subscriptions and recurring invoice lines)? Unchanged services are skipped. This runs in the background.');">
                        Sync to HaloPSA
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Import by Service ID panel --}}
    <x-ui.card id="import-ids-panel" style="display:none;" title="Import by service ID" description="Enter one or more Vocus Service IDs (comma, space, or newline separated). Each ID will be imported and its details fetched from Vocus.">
        <form method="POST" action="{{ route('admin.services.internet.import') }}" data-vocus-queue>
            @csrf
            <label for="import-service-ids" class="sr-only">Vocus service IDs</label>
            <textarea id="import-service-ids" name="service_ids" rows="3" class="dd-input"
                      placeholder="e.g. NBN-12345, NBN-67890"
                      style="width:100%;font-family:monospace;resize:vertical;box-sizing:border-box;"></textarea>
            <div style="margin-top:var(--dd-space-3);display:flex;gap:var(--dd-space-2);">
                <button type="submit" class="ui-btn ui-btn--primary">Import &amp; fetch from Vocus</button>
                <button type="button" class="ui-btn ui-btn--secondary"
                        onclick="document.getElementById('import-ids-panel').style.display='none';">Cancel</button>
            </div>
        </form>
    </x-ui.card>

    <div id="vocus-sync-msg" role="status" style="font-size:var(--dd-text-sm);margin-bottom:var(--dd-space-2);"></div>
    <div id="vocus-sync-runs" aria-live="polite"></div>

    {{-- Table --}}
    <x-ui.card :flush="true">
        <x-ui.table>
            <thead>
            <tr>
                <th>Service ID</th>
                <th>Address</th>
                <th>Technology</th>
                <th>Plan</th>
                <th class="is-numeric">Cost</th>
                <th class="is-numeric">Sell</th>
                <th class="is-numeric">Margin</th>
                <th>Client</th>
                <th>Status</th>
                <th>Halo</th>
                <th style="width:3rem;"></th>
            </tr>
            </thead>
            <tbody>
            @forelse($services as $service)
                @php
                    $rowId = 'internet-'.$service->id;
                    $clientLabel = optional($service->client)->business_name ?? optional($service->client)->name;
                    $statusVariant = match($service->service_status) {
                        'ACTIVE' => 'success', 'SUSPEND' => 'warning', 'INACTIVE' => 'danger', default => 'neutral',
                    };
                    $hs = $service->haloSyncState();
                    $addressLabel = $service->address_long ?? ($service->location_reference ? 'PC: '.$service->location_reference : '-');
                @endphp
                <tr data-row-toggle="{{ $rowId }}" style="cursor:pointer;">
                    <td style="font-family:monospace;">{{ $service->vocus_service_id ?? '-' }}</td>
                    <td>{{ $addressLabel }}</td>
                    <td>@if($service->service_type)<x-ui.badge variant="info" :dot="false">{{ $service->service_type }}</x-ui.badge>@else - @endif</td>
                    <td>{{ $service->plan_id ?? '-' }}</td>
                    <td class="is-numeric" style="white-space:nowrap;">{{ $service->cost_price !== null ? '$'.number_format((float) $service->cost_price, 2) : '-' }}</td>
                    <td class="is-numeric" style="white-space:nowrap;">{{ $service->sell_price !== null ? '$'.number_format((float) $service->sell_price, 2) : '-' }}</td>
                    <td class="is-numeric" style="white-space:nowrap;">
                        @if($service->margin !== null)
                            <span style="color:{{ (float) $service->margin < 0 ? 'var(--dd-danger-text)' : 'var(--dd-text)' }};">${{ number_format((float) $service->margin, 2) }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $clientLabel ?: '—' }}</td>
                    <td>@if($service->service_status)<x-ui.badge :variant="$statusVariant">{{ ucfirst(strtolower($service->service_status)) }}</x-ui.badge>@else — @endif</td>
                    <td>
                        @if($hs === 'error')
                            <x-ui.badge variant="danger" :title="$service->halo_sync_error">Error</x-ui.badge>
                        @elseif($hs === 'synced')
                            <x-ui.badge variant="success">Synced</x-ui.badge>
                        @else
                            <span class="ui-muted">-</span>
                        @endif
                    </td>
                    <td style="text-align:right;"><span class="ui-muted" title="Toggle details"><x-ui.icon name="chevron-down" :size="16" /></span></td>
                </tr>

                <x-ui.row-detail data-row-panel="{{ $rowId }}" :title="$service->vocus_service_id ?? 'Internet service'" :colspan="11">
                    <x-slot:meta>
                        {{ $addressLabel }}@if($service->last_transaction_state) • Last transaction: {{ $service->last_transaction_state }}@endif
                    </x-slot:meta>
                    <x-slot:aside>Client: {{ $clientLabel ?: 'Not assigned' }}</x-slot:aside>

                    <x-ui.action-grid label="Internet service actions">
                        <x-ui.action-tile :href="route('admin.services.internet.show', $service)" icon="eye">Overview</x-ui.action-tile>
                        <x-ui.action-tile :href="route('admin.services.internet.show', $service)" icon="users">Assign client</x-ui.action-tile>
                        @if(auth()->user()?->can('sync.run'))
                            <x-ui.action-tile :href="route('admin.services.internet.show', $service).'#halo-billing-card'" icon="list">Halo billing</x-ui.action-tile>
                        @endif
                    </x-ui.action-grid>
                </x-ui.row-detail>
            @empty
                <tr>
                    <td colspan="11" class="ui-muted" style="text-align:center;padding:2rem;">
                        No internet services found. <a href="{{ route('admin.services.internet.qualify') }}" style="color:var(--dd-accent-text);">Qualify an address</a> to get started.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </x-ui.table>

        @if ($services->hasPages())
            <div class="px-4 py-3">{{ $services->links() }}</div>
        @endif
    </x-ui.card>
</div>

<script>
// Expand / collapse the details row under a service.
document.querySelectorAll('[data-row-toggle]').forEach(function (row) {
    row.addEventListener('click', function (e) {
        if (e.target.closest('a,button,form,input,select,textarea')) return;
        var panel = document.querySelector('[data-row-panel="' + this.dataset.rowToggle + '"]');
        if (panel) panel.classList.toggle('open');
    });
});

// Sync / import are queued: submit through fetch, then follow the run in the shared live panel.
document.querySelectorAll('form[data-vocus-queue]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const msg = document.getElementById('vocus-sync-msg');
        const mount = document.getElementById('vocus-sync-runs');
        const buttons = form.querySelectorAll('button[type="submit"]');
        buttons.forEach(function (b) { b.disabled = true; });
        msg.textContent = '';
        msg.style.color = '';

        const body = new URLSearchParams(new FormData(form));
        fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: body.toString(),
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
        }).then(function (r) {
            const data = r.data || {};
            const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
            if (!r.ok && !(data.runs && data.runs.length)) {
                msg.style.color = 'var(--dd-danger-text)';
                msg.textContent = data.error || firstError || data.message || ('Request failed (HTTP ' + r.status + ')');
                buttons.forEach(function (b) { b.disabled = false; });
                return;
            }
            if (!r.ok) {
                msg.style.color = 'var(--dd-danger-text)';
                msg.textContent = data.error || 'A sync is already running.';
            } else {
                msg.textContent = data.message || '';
            }
            DDSync.updateWorker(data.worker);
            DDSync.track(data.runs, mount, {
                onDone: function () { buttons.forEach(function (b) { b.disabled = false; }); },
            });
            setTimeout(function () { buttons.forEach(function (b) { b.disabled = false; }); }, 3000);
        }).catch(function (e) {
            msg.style.color = 'var(--dd-danger-text)';
            msg.textContent = 'Could not queue the sync: ' + e.message;
            buttons.forEach(function (b) { b.disabled = false; });
        });
    });
});
</script>
@endsection
