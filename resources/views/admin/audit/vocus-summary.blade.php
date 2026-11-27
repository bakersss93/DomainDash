<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>DomainDash - Vocus WSM {{ $filters['environment'] }} transactions</title>
    <style>
        :root {
            --s-bg: #ffffff; --s-text: #1b1d22; --s-soft: #565c68; --s-border: #d5d9e0; --s-muted: #f2f4f7;
            --s-ok: #14532d; --s-ok-bg: #e3f4ea; --s-warn: #6b3a05; --s-warn-bg: #fdf1d6; --s-bad: #8f1d1d; --s-bad-bg: #fde8e8;
            --s-accent: #266a3e;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--s-bg); color: var(--s-text); font: 14px/1.5 'Figtree', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        main { max-width: 960px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 17px; margin: 28px 0 8px; padding-bottom: 4px; border-bottom: 2px solid var(--s-border); }
        h3 { font-size: 14px; margin: 18px 0 4px; }
        .sub { color: var(--s-soft); margin: 0 0 12px; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 8px 16px; margin: 12px 0; padding: 12px; background: var(--s-muted); border-radius: 8px; }
        .meta dt { font-size: 12px; color: var(--s-soft); margin: 0; }
        .meta dd { margin: 0; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 4px 0 8px; }
        th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--s-border); vertical-align: top; }
        th { font-size: 12px; color: var(--s-soft); font-weight: 600; background: var(--s-muted); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
        .nowrap { white-space: nowrap; }
        .tag { display: inline-block; padding: 0 8px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
        .tag--ok { background: var(--s-ok-bg); color: var(--s-ok); }
        .tag--warn { background: var(--s-warn-bg); color: var(--s-warn); }
        .tag--bad { background: var(--s-bad-bg); color: var(--s-bad); }
        .journey { break-inside: avoid; margin-bottom: 14px; }
        .journey__head { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: baseline; justify-content: space-between; }
        .note { font-size: 12px; color: var(--s-soft); }
        .toolbar { display: flex; gap: 8px; margin-bottom: 16px; }
        .toolbar a, .toolbar button { font: inherit; font-size: 13px; padding: 6px 14px; border: 1px solid var(--s-border); border-radius: 8px; background: var(--s-bg); color: var(--s-accent); text-decoration: none; cursor: pointer; }
        .toolbar button { background: var(--s-accent); color: #fff; border-color: var(--s-accent); }
        @media print {
            .toolbar { display: none; }
            main { padding: 0; max-width: none; }
            h2 { break-after: avoid; }
            tr { break-inside: avoid; }
            @page { margin: 14mm; }
        }
    </style>
</head>
<body>
@php
    $stats = $data['stats'];
    $env = $filters['environment'];
    $range = ($filters['from'] || $filters['to']) ? (($filters['from'] ?: 'start') . ' to ' . ($filters['to'] ?: 'today')) : 'All recorded activity';
    $journeyOf = [];
    foreach ($data['journeys'] as $j) {
        foreach ($j['steps'] as $st) {
            if ($st['transaction_id'] !== null) { $journeyOf[$st['transaction_id']] = $j['id']; }
        }
    }
@endphp
<main>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print or save as PDF</button>
        <a href="{{ route('admin.audit.vocus', array_filter($filters)) }}">Back to Vocus transactions</a>
    </div>

    <h1>DomainDash &mdash; Vocus WSM {{ $env }} transactions</h1>
    <p class="sub">TransactionIDs from the Vocus Wholesale Service Management {{ $env }} environment, listed per API method, and the order journeys they belong to.</p>

    <dl class="meta">
        <div><dt>Environment</dt><dd>{{ ucfirst($env) }}</dd></div>
        <div><dt>Period</dt><dd>{{ $range }}</dd></div>
        <div><dt>Generated</dt><dd>{{ $generatedAt->format('d M Y H:i') }} {{ $generatedAt->format('T') }}</dd></div>
        <div><dt>TransactionIDs</dt><dd>{{ $stats['transactions'] }}</dd></div>
        <div><dt>Journeys</dt><dd>{{ $stats['journeys'] }} ({{ $stats['complete'] }} complete)</dd></div>
    </dl>
    @if ($stats['from_service_record'] > 0)
        <p class="note">{{ $stats['from_service_record'] }} entries are marked "service record": they come from older records that kept the TransactionID but no call log, so their response type is not available.</p>
    @endif

    <h2>TransactionIDs by API method</h2>
    @forelse ($data['methods'] as $m)
        <h3>{{ $m['label'] }} <span class="note">{{ $m['count'] }} {{ \Illuminate\Support\Str::plural('call', $m['count']) }}@if ($m['ok'] < $m['count']), {{ $m['count'] - $m['ok'] }} with a fault @endif</span></h3>
        <table>
            <thead><tr><th>TransactionID</th><th>Timestamp</th><th>Response type</th><th>Result</th><th>Journey</th></tr></thead>
            <tbody>
                @foreach ($m['calls'] as $c)
                    <tr>
                        <td class="mono">{{ $c['transaction_id'] }}</td>
                        <td class="nowrap">{{ $c['at']->format('d M Y H:i:s') }}</td>
                        <td>{{ $c['response_type'] ?? '' }}</td>
                        <td><span class="tag {{ $c['ok'] ? 'tag--ok' : 'tag--bad' }}">{{ $c['result'] ?? ($c['ok'] ? 'OK' : 'Error') }}</span></td>
                        <td>{{ $journeyOf[$c['transaction_id']] ?? 'single call' }}@if ($c['source'] !== 'recorded') <span class="note">(service record)</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="note">No {{ $env }} transactions were recorded in this period.</p>
    @endforelse

    <h2>Journeys</h2>
    <p class="note">Each journey follows one location or service: address lookup, service qualification (SQ), order, then the asynchronous follow-up and the service record.</p>
    @forelse ($data['journeys'] as $j)
        <section class="journey">
            <div class="journey__head">
                <strong>{{ $j['id'] }} &middot; {{ $j['title'] }}</strong>
                <span class="tag {{ $j['complete'] ? 'tag--ok' : 'tag--warn' }}">{{ $j['complete'] ? 'Complete' : 'Incomplete: missing ' . implode(', ', $j['missing']) }}</span>
            </div>
            <table>
                <thead><tr><th>#</th><th>Method</th><th>TransactionID</th><th>Timestamp</th><th>Response</th><th>Result</th></tr></thead>
                <tbody>
                    @foreach ($j['steps'] as $n => $st)
                        <tr>
                            <td>{{ $n + 1 }}</td>
                            <td>{{ $st['label'] }}</td>
                            <td class="mono">{{ $st['transaction_id'] ?? 'not stored' }}</td>
                            <td class="nowrap">{{ $st['at']->format('d M Y H:i:s') }}</td>
                            <td>{{ $st['response_type'] ?? '' }}</td>
                            <td>{{ $st['result'] ?? ($st['ok'] ? 'OK' : 'Error') }}@if (! empty($st['meta']['transaction_state'])) ({{ $st['meta']['transaction_state'] }})@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p class="note">No journeys in this period.</p>
    @endforelse

    @if ($data['singles'])
        <h2>Single calls (not part of a journey)</h2>
        @foreach ($data['singles'] as $g)
            <h3>{{ $g['label'] }}</h3>
            <table>
                <thead><tr><th>TransactionID</th><th>Timestamp</th><th>ServiceID</th><th>Result</th></tr></thead>
                <tbody>
                    @foreach ($g['calls'] as $c)
                        <tr>
                            <td class="mono">{{ $c['transaction_id'] }}</td>
                            <td class="nowrap">{{ $c['at']->format('d M Y H:i:s') }}</td>
                            <td class="mono">{{ $c['service_id'] }}</td>
                            <td>{{ $c['result'] ?? 'OK' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</main>
</body>
</html>
