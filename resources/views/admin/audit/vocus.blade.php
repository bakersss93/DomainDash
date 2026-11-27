@extends('layouts.app')

@section('content')
@php
    $q = fn (array $extra = []) => array_filter(array_merge($filters, $extra), fn ($v) => $v !== '' && $v !== null);
    $env = $filters['environment'];
    $stats = $data['stats'];
    $preset = fn (?int $days) => $days === null ? ['from' => '', 'to' => ''] : ['from' => now()->subDays($days - 1)->toDateString(), 'to' => now()->toDateString()];
    $resultVariant = fn ($step) => $step['ok'] ? 'success' : 'danger';
    $limit = 40;
@endphp
<div class="ui-page" id="vocus-cert-page">
    <x-ui.page-header title="Vocus transactions" subtitle="Every Vocus WSM call DomainDash recorded, grouped into journeys from address lookup to the asynchronous follow-up. Send the summary to Vocus for production certification.">
        <x-slot name="actions">
            <x-ui.button variant="secondary" size="sm" :href="route('admin.audit.index')">Back to Audit Logs</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <x-ui.card :flush="false">
        <form method="GET" action="{{ route('admin.audit.vocus') }}" class="vc-filters">
            <x-ui.field label="Environment" name="environment" as="select">
                <option value="sandbox" @selected($env === 'sandbox')>Sandbox</option>
                <option value="production" @selected($env === 'production')>Production</option>
            </x-ui.field>
            <x-ui.field label="From" name="from" type="date" :value="$filters['from']" />
            <x-ui.field label="To" name="to" type="date" :value="$filters['to']" />
            <div class="vc-filters__actions">
                <x-ui.button type="submit" variant="primary">Apply</x-ui.button>
                <span class="vc-chips" role="group" aria-label="Date range">
                    <a class="vc-chip" href="{{ route('admin.audit.vocus', $q($preset(1))) }}">Today</a>
                    <a class="vc-chip" href="{{ route('admin.audit.vocus', $q($preset(7))) }}">7 days</a>
                    <a class="vc-chip" href="{{ route('admin.audit.vocus', $q($preset(30))) }}">30 days</a>
                    <a class="vc-chip" href="{{ route('admin.audit.vocus', ['environment' => $env]) }}">All time</a>
                </span>
            </div>
        </form>
    </x-ui.card>

    <div class="vc-stats">
        <x-ui.stat label="Calls recorded" :value="$stats['calls']" />
        <x-ui.stat label="Transaction IDs" :value="$stats['transactions']" />
        <x-ui.stat label="Journeys" :value="$stats['journeys']" :hint="$stats['complete'] . ' complete'" :variant="$stats['complete'] > 0 ? 'success' : null" />
        <x-ui.stat label="From service records" :value="$stats['from_service_record']" hint="Older transactions, no call log" />
    </div>

    <x-ui.card title="Send to Vocus" description="Vocus reviews the TransactionIDs from your sandbox and grants production access per API method.">
        <x-ui.action-grid label="Vocus exports">
            <x-ui.action-tile icon="download" :href="route('admin.audit.vocus.csv', $q())">Download CSV</x-ui.action-tile>
            <x-ui.action-tile icon="list" :href="route('admin.audit.vocus.summary', $q())" target="_blank">Printable summary (PDF)</x-ui.action-tile>
            <x-ui.action-tile icon="cog" :href="route('admin.settings.area', ['area' => 'vocus', 'tab' => 'sandbox'])">Sandbox checklist</x-ui.action-tile>
        </x-ui.action-grid>
    </x-ui.card>

    @if ($env === 'sandbox')
        <x-ui.card title="Sandbox journey checklist">
            <x-vocus.checklist :checklist="$checklist" />
        </x-ui.card>
    @endif

    <h2 class="vc-h">Journeys</h2>
    @forelse ($data['journeys'] as $j)
        <x-ui.card :flush="true" class="vc-journey">
            <div class="vc-journey__head">
                <div class="min-w-0">
                    <p class="vc-journey__title">{{ $j['id'] }} &middot; {{ $j['title'] }}</p>
                    <p class="vc-journey__meta">
                        @if ($j['directory_id']) Location {{ $j['directory_id'] }} @endif
                        @if ($j['order_transaction_id']) &middot; order TransactionID {{ $j['order_transaction_id'] }} @endif
                        @if ($j['poll_count']) &middot; {{ $j['poll_count'] }} {{ \Illuminate\Support\Str::plural('poll', $j['poll_count']) }}@if ($j['final_state']), last state {{ $j['final_state'] }}@endif @endif
                    </p>
                </div>
                @if ($j['complete'])
                    <x-ui.badge variant="success">Complete</x-ui.badge>
                @else
                    <x-ui.badge variant="warning">Incomplete: missing {{ implode(', ', $j['missing']) }}</x-ui.badge>
                @endif
            </div>
            <x-ui.table :compact="true">
                <thead><tr><th scope="col">#</th><th scope="col">Method</th><th scope="col">TransactionID</th><th scope="col">When</th><th scope="col">Response</th><th scope="col">Result</th><th scope="col">Source</th></tr></thead>
                <tbody>
                    @foreach (array_slice($j['steps'], 0, $limit) as $n => $step)
                        <tr>
                            <td>{{ $n + 1 }}</td>
                            <td>{{ $step['label'] }}</td>
                            <td class="vc-mono">{{ $step['transaction_id'] ?? 'not stored' }}</td>
                            <td class="vc-nowrap">{{ $step['at']->format('d M Y H:i:s') }}</td>
                            <td>{{ $step['response_type'] ?? '' }}</td>
                            <td><x-ui.badge :variant="$resultVariant($step)">{{ $step['result'] ?? ($step['ok'] ? 'OK' : 'Error') }}@if (! empty($step['meta']['transaction_state'])) ({{ $step['meta']['transaction_state'] }})@endif</x-ui.badge></td>
                            <td>@if ($step['source'] !== 'recorded')<x-ui.badge variant="info">From service record</x-ui.badge>@else Recorded @endif</td>
                        </tr>
                    @endforeach
                    @if (count($j['steps']) > $limit)
                        <tr><td colspan="7" class="vc-more">{{ count($j['steps']) - $limit }} more steps are in the CSV export.</td></tr>
                    @endif
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="No {{ $env }} journeys recorded" text="Qualify an address, place an order and open the service: every Vocus call is recorded here with its TransactionID." icon="activity">
            <x-ui.button variant="secondary" size="sm" :href="route('admin.services.internet.qualify')">Qualify an address</x-ui.button>
        </x-ui.empty-state>
    @endforelse

    @if ($data['singles'])
        <h2 class="vc-h">Single calls (not part of a journey)</h2>
        @foreach ($data['singles'] as $g)
            <x-ui.card :title="$g['label']" :description="count($g['calls']) . ' ' . \Illuminate\Support\Str::plural('call', count($g['calls']))" :flush="true">
                <x-ui.table :compact="true">
                    <thead><tr><th scope="col">TransactionID</th><th scope="col">When</th><th scope="col">Service</th><th scope="col">Response</th><th scope="col">Result</th><th scope="col">Source</th></tr></thead>
                    <tbody>
                        @foreach (array_slice($g['calls'], 0, $limit) as $step)
                            <tr>
                                <td class="vc-mono">{{ $step['transaction_id'] }}</td>
                                <td class="vc-nowrap">{{ $step['at']->format('d M Y H:i:s') }}</td>
                                <td class="vc-mono">{{ $step['service_id'] }}</td>
                                <td>{{ $step['response_type'] ?? '' }}</td>
                                <td><x-ui.badge :variant="$resultVariant($step)">{{ $step['result'] ?? 'OK' }}</x-ui.badge></td>
                                <td>@if ($step['source'] !== 'recorded')<x-ui.badge variant="info">From service record</x-ui.badge>@else Recorded @endif</td>
                            </tr>
                        @endforeach
                        @if (count($g['calls']) > $limit)
                            <tr><td colspan="6" class="vc-more">{{ count($g['calls']) - $limit }} more calls are in the CSV export.</td></tr>
                        @endif
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endforeach
    @endif
</div>

<style>
    #vocus-cert-page > * + * { margin-top: 1rem; }
    .vc-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; align-items: end; }
    .vc-filters__actions { grid-column: 1 / -1; }
    .vc-filters__actions { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
    .vc-chips { display: inline-flex; flex-wrap: wrap; gap: .3rem; }
    .vc-chip { font-size: 12px; font-weight: 500; padding: .25rem .7rem; border: 1px solid var(--dd-border); border-radius: var(--dd-radius-pill); background: var(--dd-surface); color: var(--dd-text-soft); text-decoration: none; }
    .vc-chip:hover { background: var(--dd-surface-muted); color: var(--dd-text); }
    .vc-chip:focus-visible { outline: 2px solid var(--dd-focus); outline-offset: 1px; }
    .vc-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; }
    .vc-h { margin: 1.5rem 0 0; font-size: var(--dd-text-lg); font-weight: 600; color: var(--dd-text); }
    .vc-journey__head { display: flex; flex-wrap: wrap; gap: .5rem 1rem; justify-content: space-between; align-items: flex-start; padding: .85rem 1rem; border-bottom: 1px solid var(--dd-border); }
    .vc-journey__title { margin: 0; font-weight: 600; color: var(--dd-text); }
    .vc-journey__meta { margin: .15rem 0 0; font-size: 13px; color: var(--dd-text-soft); word-break: break-word; }
    .vc-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
    .vc-nowrap { white-space: nowrap; }
    .vc-more { text-align: center; color: var(--dd-text-soft); font-size: 13px; }
</style>
@endsection
