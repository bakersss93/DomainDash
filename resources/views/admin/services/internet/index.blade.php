@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card">
    <h1 class="dd-page-title" style="font-size:1.45rem;">Internet Services</h1>

    @if(session('status'))
        <div style="margin-bottom:12px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;color:#15803d;font-size:14px;">
            {{ session('status') }}
        </div>
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
                @foreach(['FTTP','FTTC','FTTB','FTTN','HFC','FIXED-WIRELESS'] as $type)
                    <option value="{{ $type }}" {{ ($typeFilter ?? '') === $type ? 'selected' : '' }}>{{ $type }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-accent dd-pill-btn">Filter</button>
        </form>

        <div style="display:flex;gap:8px;">
            <a href="{{ route('admin.services.internet.qualify') }}" class="btn-accent dd-pill-btn">Qualify Address</a>
            <a href="{{ route('admin.services.internet.order') }}" class="btn-accent dd-pill-btn">New Order</a>
            <form method="POST" action="{{ route('admin.services.internet.sync') }}">
                @csrf
                <button type="submit" class="btn-accent dd-pill-btn"
                        onclick="return confirm('Refresh status for all known internet services from Vocus?');">
                    Sync
                </button>
            </form>
        </div>
    </div>

    {{-- Table --}}
    <div class="dd-services-table-wrapper">
        <table class="dd-services-table">
            <thead>
            <tr>
                <th>Service ID</th>
                <th>Address</th>
                <th>Technology</th>
                <th>Plan</th>
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
                            <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:#dbeafe;color:#1e40af;">
                                {{ $service->service_type }}
                            </span>
                        @else
                            -
                        @endif
                    </td>
                    <td style="font-size:13px;">{{ $service->plan_id ?? '-' }}</td>
                    <td>{{ optional($service->client)->business_name ?? optional($service->client)->name ?? '—' }}</td>
                    <td>
                        @php
                            $color = match($service->service_status) {
                                'ACTIVE'   => ['bg'=>'#dcfce7','text'=>'#15803d'],
                                'SUSPEND'  => ['bg'=>'#fef9c3','text'=>'#854d0e'],
                                'INACTIVE' => ['bg'=>'#fee2e2','text'=>'#b91c1c'],
                                default    => ['bg'=>'#f1f5f9','text'=>'#475569'],
                            };
                        @endphp
                        <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;background:{{ $color['bg'] }};color:{{ $color['text'] }};">
                            {{ $service->service_status }}
                        </span>
                    </td>
                    <td style="font-size:12px;color:#6b7280;">
                        @if($service->last_transaction_state)
                            @php
                                $txColor = match($service->last_transaction_state) {
                                    'SUCCESS'    => '#15803d',
                                    'FAILED'     => '#b91c1c',
                                    'QUEUED','PROCESSING' => '#d97706',
                                    default      => '#6b7280',
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
                    <td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;">
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
@endsection
