@extends('layouts.app')

@section('content')
<div class="ui-page">
    <x-ui.page-header title="Internet Services" subtitle="Your internet connections. Contact support to change a service." />

    @if ($errors->any())
        <x-ui.alert variant="danger" title="We could not log your request">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-ui.alert>
    @endif

    <x-ui.card :flush="true">
        <x-ui.table>
            <thead>
            <tr>
                <th>Service ID</th>
                <th>Address</th>
                <th>Plan</th>
                <th>Technology</th>
                <th>Status</th>
                <th style="width:3rem;"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($services as $svc)
                @php
                    $statusVariant = match ($svc['status']) {
                        'ACTIVE' => 'success', 'SUSPEND' => 'warning', 'INACTIVE' => 'danger', default => 'neutral',
                    };
                @endphp
                <tr>
                    <td style="font-family:monospace;">{{ $svc['service_id'] ?? '-' }}</td>
                    <td>{{ $svc['address'] ?? '-' }}@if (count($clients) > 1 && $svc['client'])<div class="ui-muted" style="font-size:12px;">{{ $svc['client'] }}</div>@endif</td>
                    <td>{{ trim(($svc['plan'] ?? '') . ' ' . ($svc['speed'] ?? '')) ?: '-' }}</td>
                    <td>@if ($svc['technology'])<x-ui.badge variant="info" :dot="false">{{ $svc['technology'] }}</x-ui.badge>@else - @endif</td>
                    <td>@if ($svc['status'])<x-ui.badge :variant="$statusVariant">{{ ucfirst(strtolower($svc['status'])) }}</x-ui.badge>@else - @endif</td>
                    <td style="text-align:right;"><a href="{{ route('internet.show', $svc['id']) }}" class="ui-btn ui-btn--secondary ui-btn--sm">View</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="ui-muted" style="text-align:center;padding:2rem;">No internet services found for your account.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    @if ($canRequestNew)
        <x-ui.card title="Request a new service" description="Tell us where you need a connection. We will log a request with our support team; nothing is ordered until we confirm with you." style="margin-top:1rem;">
            <form method="POST" action="{{ route('internet.request-new') }}" class="dd-ticket-grid" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));">
                @csrf
                @if (count($clients) > 1)
                    <div>
                        <label for="client_id">Account</label>
                        <select id="client_id" name="client_id" class="dd-input" required>
                            @foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->business_name }}</option>@endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="client_id" value="{{ $clients->first()?->id }}">
                @endif
                <div>
                    <label for="address">Service address</label>
                    <input id="address" name="address" type="text" class="dd-input" maxlength="255" required value="{{ old('address') }}">
                </div>
                <div>
                    <label for="speed">Preferred speed (optional)</label>
                    <input id="speed" name="speed" type="text" class="dd-input" maxlength="100" value="{{ old('speed') }}">
                </div>
                <div style="grid-column:1/-1;">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" class="dd-input" rows="3" maxlength="2000">{{ old('notes') }}</textarea>
                </div>
                <div><button type="submit" class="ui-btn ui-btn--primary">Request new service</button></div>
            </form>
        </x-ui.card>
    @endif
</div>
@endsection
