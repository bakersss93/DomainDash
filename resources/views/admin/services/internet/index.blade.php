@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card">
    <h1 class="dd-page-title">Internet Services</h1>

    @include('admin.sync._live')
    @include('admin.sync._worker-banner')

    @if(!empty($vocusProblem))
        <x-ui.alert variant="danger" title="Vocus is not ready" style="margin-bottom:1rem;">{{ $vocusProblem }}
            <a href="{{ route('admin.settings') }}">Open settings</a>
        </x-ui.alert>
    @endif

    {{-- Toolbar --}}
    <div class="dd-services-toolbar">
        <form method="GET" action="{{ route('admin.services.internet') }}" class="dd-services-filter" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="client_id" class="dd-input dd-input-inline">
                <option value="">All clients</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" {{ (string)($clientId ?? '') === (string)$client->id ? 'selected' : '' }}>
                        {{ $client->business_name ?? $client->name ?? 'Client #'.$client->id }}
                    </option>
                @endforeach
            </select>
            <select name="status" class="dd-input dd-input-inline">
                <option value="" {{ $statusFilter === '' ? 'selected' : '' }}>All statuses</option>
                <option value="ACTIVE" {{ $statusFilter === 'ACTIVE' ? 'selected' : '' }}>Active</option>
                <option value="SUSPEND" {{ $statusFilter === 'SUSPEND' ? 'selected' : '' }}>Suspended</option>
                <option value="INACTIVE" {{ $statusFilter === 'INACTIVE' ? 'selected' : '' }}>Inactive</option>
            </select>
            <select name="service_type" class="dd-input dd-input-inline">
                <option value="" {{ ($typeFilter ?? '') === '' ? 'selected' : '' }}>All technologies</option>
                @foreach(['FTTP','FTTC','FTTB','FTTN','HFC','WIRELESS'] as $type)
                    <option value="{{ $type }}" {{ ($typeFilter ?? '') === $type ? 'selected' : '' }}>{{ $type }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-accent dd-pill-btn">Filter</button>
        </form>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <a href="{{ route('admin.services.internet.qualify') }}" class="btn-accent dd-pill-btn">Qualify Address</a>
            <a href="{{ route('admin.services.internet.order') }}" class="btn-accent dd-pill-btn">New Order</a>
            <form method="POST" action="{{ route('admin.services.internet.sync') }}" data-vocus-queue>
                @csrf
                <button type="submit" class="btn-accent dd-pill-btn"
                        onclick="return confirm('Refresh status for all known internet services from Vocus? This runs in the background (about 2.5 seconds per service).');">
                    Sync
                </button>
            </form>
            <button type="button" class="btn-accent dd-pill-btn"
                    onclick="document.getElementById('import-ids-panel').style.display = document.getElementById('import-ids-panel').style.display === 'none' ? 'block' : 'none';">
                Import Service IDs
            </button>
        </div>
    </div>

    {{-- Import by Service ID panel --}}
    <div id="import-ids-panel" style="display:none;margin-bottom:16px;padding:14px 16px;background:var(--card-bg,#1e293b);border:1px solid var(--border,#334155);border-radius:8px;">
        <p style="margin:0 0 8px;font-size:13px;color:var(--text-muted,#94a3b8);">
            Enter one or more Vocus Service IDs (comma, space, or newline separated). Each ID will be imported and its details fetched from Vocus.
        </p>
        <form method="POST" action="{{ route('admin.services.internet.import') }}" data-vocus-queue>
            @csrf
            <textarea name="service_ids" rows="3"
                      placeholder="e.g. NBN-12345, NBN-67890"
                      style="width:100%;font-family:monospace;resize:vertical;box-sizing:border-box;"></textarea>
            <div style="margin-top:8px;display:flex;gap:8px;">
                <button type="submit" class="btn-accent dd-pill-btn">Import &amp; Fetch from Vocus</button>
                <button type="button" class="dd-pill-btn"
                        onclick="document.getElementById('import-ids-panel').style.display='none';"
                        style="background:transparent;border:1px solid var(--border,#334155);">Cancel</button>
            </div>
        </form>
    </div>

    <div id="vocus-sync-msg" role="status" style="font-size:13px;margin-bottom:8px;"></div>
    <div id="vocus-sync-runs" aria-live="polite"></div>

    {{-- Table --}}
    <div class="dd-services-table-wrapper">
        <table class="dd-services-table">
            <thead>
            <tr>
                <th>Service ID</th>
                <th>Address</th>
                <th>Technology</th>
                <th>Plan</th>
                <th style="text-align:right;">Cost</th>
                <th style="text-align:right;">Sell</th>
                <th style="text-align:right;">Margin</th>
                <th>Client</th>
                <th>Status</th>
                <th>Transaction</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($services as $service)
                <tr>
                    <td style="font-family:monospace;font-size:13px;">{{ $service->vocus_service_id ?? '-' }}</td>
                    <td>{{ $service->address_long ?? ($service->location_reference ? 'PC: '.$service->location_reference : '-') }}</td>
                    <td>
                        @if($service->service_type)
                            <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:var(--dd-status-info-bg);color:var(--dd-status-info-fg);">
                                {{ $service->service_type }}
                            </span>
                        @else
                            -
                        @endif
                    </td>
                    <td style="font-size:13px;">{{ $service->plan_id ?? '-' }}</td>
                    <td style="text-align:right;font-size:13px;white-space:nowrap;">{{ $service->cost_price !== null ? '$'.number_format((float) $service->cost_price, 2) : '-' }}</td>
                    <td style="text-align:right;font-size:13px;white-space:nowrap;">{{ $service->sell_price !== null ? '$'.number_format((float) $service->sell_price, 2) : '-' }}</td>
                    <td style="text-align:right;font-size:13px;white-space:nowrap;">
                        @if($service->margin !== null)
                            <span style="color:{{ (float) $service->margin < 0 ? 'var(--dd-status-danger-fg)' : 'var(--dd-status-success-fg)' }};">${{ number_format((float) $service->margin, 2) }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ optional($service->client)->business_name ?? optional($service->client)->name ?? '—' }}</td>
                    <td>
                        @php
                            $color = match($service->service_status) {
                                'ACTIVE'   => ['bg'=>'var(--dd-status-success-bg)','text'=>'var(--dd-status-success-fg)'],
                                'SUSPEND'  => ['bg'=>'var(--dd-status-warning-bg)','text'=>'var(--dd-status-warning-fg)'],
                                'INACTIVE' => ['bg'=>'var(--dd-status-danger-bg)','text'=>'var(--dd-status-danger-fg)'],
                                default    => ['bg'=>'var(--dd-status-muted-bg)','text'=>'var(--dd-status-muted-fg)'],
                            };
                        @endphp
                        <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:{{ $color['bg'] }};color:{{ $color['text'] }};">
                            {{ $service->service_status }}
                        </span>
                    </td>
                    <td style="font-size:12px;color:var(--dd-text-soft);">
                        @if($service->last_transaction_state)
                            @php
                                $txColor = match($service->last_transaction_state) {
                                    'SUCCESS'    => 'var(--dd-status-success-fg)',
                                    'FAILED'     => 'var(--dd-status-danger-fg)',
                                    'QUEUED','PROCESSING' => 'var(--dd-status-warning-fg)',
                                    default      => 'var(--dd-status-muted-fg)',
                                };
                            @endphp
                            <span style="color:{{ $txColor }};">{{ $service->last_transaction_state }}</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('admin.services.internet.show', $service) }}"
                           style="font-size:13px;color:var(--accent);text-decoration:none;">
                            View →
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align:center;padding:32px;color:var(--dd-text-soft);">
                        No internet services found. <a href="{{ route('admin.services.internet.qualify') }}" style="color:var(--accent);">Qualify an address</a> to get started.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;">
        {{ $services->links() }}
    </div>
</div>
</div>

<script>
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
