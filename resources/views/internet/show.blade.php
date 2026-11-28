@extends('layouts.app')

@section('content')
@php
    $statusVariant = match ($service['status']) {
        'ACTIVE' => 'success', 'SUSPEND' => 'warning', 'INACTIVE' => 'danger', default => 'neutral',
    };
    $rows = [
        'Service ID' => $service['service_id'],
        'Address' => $service['address'],
        'Plan' => $service['plan'],
        'Speed' => $service['speed'],
        'Technology' => $service['technology'],
        'IP type' => $service['ip_type'],
        'IP address' => $service['ip_address'],
        'Your reference' => $service['reference'],
    ];
@endphp
<div class="ui-page">
    <x-ui.page-header :title="$service['service_id'] ?? 'Internet service'" :subtitle="$service['address']">
        <x-slot name="actions">
            @if ($service['status'])<x-ui.badge :variant="$statusVariant">{{ ucfirst(strtolower($service['status'])) }}</x-ui.badge>@endif
            <x-ui.button variant="secondary" :href="route('internet.index')">All services</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert variant="danger" title="We could not log your request">
            <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-ui.alert>
    @endif

    <x-ui.card title="Service details">
        <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1.5rem;margin:0;">
            @foreach ($rows as $label => $value)
                @if ($value)
                    <dt class="ui-muted">{{ $label }}</dt>
                    <dd style="margin:0;">{{ $value }}</dd>
                @endif
            @endforeach
        </dl>
    </x-ui.card>

    @if ($canDiagnose)
        <x-ui.card title="Connection test" description="Checks the recent sign-in attempts of your connection. It is read-only and does not interrupt your service." style="margin-top:1rem;">
            <form method="POST" action="{{ route('internet.diagnostic', $serviceId) }}">
                @csrf
                <input type="hidden" name="type" value="AUTH-LOG">
                <button type="submit" class="ui-btn ui-btn--primary">Run connection test</button>
            </form>

            @foreach ($history as $h)
                <div style="margin-top:1rem;">
                    <p style="margin:0 0 .5rem;"><strong>{{ $h['ran_at']?->diffForHumans() }}</strong>:
                        @if ($h['total'] === 0)
                            no recent sign-in attempts were recorded.
                        @elseif ($h['failed'] === 0)
                            all {{ $h['total'] }} recent sign-in attempts succeeded.
                        @else
                            {{ $h['passed'] }} of {{ $h['total'] }} recent sign-in attempts succeeded, {{ $h['failed'] }} failed. If this keeps happening, contact support.
                        @endif
                    </p>
                    @if ($h['rows'])
                        <x-ui.table :compact="true">
                            <thead><tr><th>Time</th><th>Result</th></tr></thead>
                            <tbody>
                            @foreach ($h['rows'] as $row)
                                <tr><td>{{ $row['time'] }}</td><td><x-ui.badge :variant="$row['ok'] ? 'success' : 'danger'">{{ $row['ok'] ? 'Connected' : 'Failed' }}</x-ui.badge></td></tr>
                            @endforeach
                            </tbody>
                        </x-ui.table>
                    @endif
                </div>
            @endforeach
        </x-ui.card>
    @endif

    @if ($canRequestCancellation)
        <x-ui.card title="Request cancellation" description="This logs a request with our support team. Your service stays active until we confirm the cancellation with you." style="margin-top:1rem;">
            <form method="POST" action="{{ route('internet.request-cancellation', $serviceId) }}"
                  onsubmit="return confirm('Log a cancellation request for this service?');">
                @csrf
                <label for="reason">Reason (optional)</label>
                <textarea id="reason" name="reason" class="dd-input" rows="3" maxlength="2000" style="margin-bottom:.75rem;"></textarea>
                <button type="submit" class="ui-btn ui-btn--danger">Request cancellation</button>
            </form>
        </x-ui.card>
    @endif
</div>
@endsection
