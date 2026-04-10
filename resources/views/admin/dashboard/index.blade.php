@extends('layouts.app')

@section('content')
    @php
        $cpuValue = number_format($cpuUsage, 1);
        $diskUsedGb = $diskUsed / 1024 / 1024 / 1024;
        $diskFreeGb = $diskFree / 1024 / 1024 / 1024;
        $diskTotalGb = $diskTotal / 1024 / 1024 / 1024;
        $memoryUsedGb = $memUsedKB / 1024 / 1024;
        $memoryTotalGb = $memTotalKB / 1024 / 1024;

        $diskUsedPercent = $diskTotal > 0 ? ($diskUsed / $diskTotal) * 100 : 0;
        $memoryUsedPercent = $memTotalKB > 0 ? ($memUsedKB / $memTotalKB) * 100 : 0;

        $statusCards = [
            [
                'label' => 'CPU Usage',
                'value' => $cpuValue.'%',
                'sub' => '1 minute load adjusted by core count',
                'meter' => $cpuUsage,
            ],
            [
                'label' => 'RAM Usage',
                'value' => number_format($memoryUsedGb, 2).' / '.number_format($memoryTotalGb, 2).' GB',
                'sub' => number_format($memoryUsedPercent, 1).'% used',
                'meter' => $memoryUsedPercent,
            ],
            [
                'label' => 'Free Disk Space',
                'value' => number_format($diskFreeGb, 2).' GB',
                'sub' => number_format($diskUsedGb, 2).' / '.number_format($diskTotalGb, 2).' GB used',
                'meter' => $diskUsedPercent,
            ],
            [
                'label' => 'Domains',
                'value' => number_format($counts['domains']),
                'sub' => number_format($domainsWithoutClient->count()).' need client links',
                'meter' => min(100, $counts['domains'] > 0 ? ($domainsWithoutClient->count() / $counts['domains']) * 100 : 0),
            ],
            [
                'label' => 'Clients',
                'value' => number_format($counts['clients']),
                'sub' => number_format($counts['users']).' users in platform',
                'meter' => min(100, $counts['clients'] > 0 ? ($counts['users'] / $counts['clients']) * 100 : 0),
            ],
        ];
    @endphp

    <style>
        .status-shell {
            background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
            border: 1px solid #dbe3ef;
            border-radius: 16px;
            padding: 20px;
        }

        .status-title {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .status-title h1 {
            margin: 0;
            font-size: 1.8rem;
            color: #0f172a;
        }

        .status-title p {
            margin: 6px 0 0;
            color: #475569;
            font-size: 0.95rem;
        }

        .status-cards {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            margin-bottom: 18px;
        }

        .status-card {
            border: 1px solid #d8e1ed;
            background: #ffffff;
            border-radius: 12px;
            padding: 14px;
        }

        .status-card-label {
            color: #64748b;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .status-card-value {
            margin: 6px 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f172a;
        }

        .status-card-sub {
            color: #334155;
            font-size: 0.84rem;
            margin-bottom: 8px;
        }

        .status-meter {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .status-meter-fill {
            height: 100%;
            background: linear-gradient(90deg, #0284c7 0%, #16a34a 100%);
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 14px;
        }

        .status-panel {
            background: #ffffff;
            border: 1px solid #d8e1ed;
            border-radius: 12px;
            overflow: hidden;
        }

        .status-panel-head {
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .status-panel-head h2 {
            margin: 0;
            font-size: 1rem;
            color: #0f172a;
        }

        .status-panel-head p {
            margin: 4px 0 0;
            font-size: 0.82rem;
            color: #64748b;
        }

        .status-table-wrap {
            max-height: 340px;
            overflow: auto;
        }

        .status-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .status-table th,
        .status-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #edf2f7;
            text-align: left;
        }

        .status-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #334155;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-domain-link {
            color: #0369a1;
            text-decoration: none;
            font-weight: 600;
        }

        .status-domain-link:hover {
            text-decoration: underline;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #334155;
            font-size: 0.72rem;
        }

        @media (max-width: 768px) {
            .status-shell {
                padding: 14px;
            }

            .status-title h1 {
                font-size: 1.45rem;
            }
        }
    </style>

    <div class="status-shell">
        <div class="status-title">
            <div>
                <h1>System Status Dashboard</h1>
                <p>Infrastructure health, account totals, and domain cleanup queues.</p>
            </div>
        </div>

        <div class="status-cards">
            @foreach($statusCards as $card)
                <div class="status-card">
                    <div class="status-card-label">{{ $card['label'] }}</div>
                    <div class="status-card-value">{{ $card['value'] }}</div>
                    <div class="status-card-sub">{{ $card['sub'] }}</div>
                    <div class="status-meter">
                        <div class="status-meter-fill" style="width: {{ number_format(max(0, min(100, $card['meter'])), 1) }}%;"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="status-grid">
            <section class="status-panel">
                <div class="status-panel-head">
                    <h2>Domains Not Connected to a Client</h2>
                    <p>Click a domain to assign a client on its domain overview page.</p>
                </div>
                <div class="status-table-wrap">
                    <table class="status-table">
                        <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Status</th>
                            <th>Expiry</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($domainsWithoutClient as $domain)
                            <tr>
                                <td><a class="status-domain-link" href="{{ route('admin.domains.show', $domain) }}">{{ $domain->name }}</a></td>
                                <td><span class="status-pill">{{ $domain->status ?? 'Unknown' }}</span></td>
                                <td>{{ $domain->expiry_date ? \Illuminate\Support\Carbon::parse($domain->expiry_date)->toDateString() : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">Every domain currently has a linked client.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="status-panel">
                <div class="status-panel-head">
                    <h2>Domains Not Synced to Halo</h2>
                    <p>Domains missing Halo asset IDs and ready for sync/linking work.</p>
                </div>
                <div class="status-table-wrap">
                    <table class="status-table">
                        <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Client</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($domainsNotSyncedHalo as $domain)
                            <tr>
                                <td><a class="status-domain-link" href="{{ route('admin.domains.show', $domain) }}">{{ $domain->name }}</a></td>
                                <td>{{ $domain->client_id ? '#'.$domain->client_id : 'Unlinked' }}</td>
                                <td><span class="status-pill">{{ $domain->status ?? 'Unknown' }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">All domains currently have Halo links.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="status-panel">
                <div class="status-panel-head">
                    <h2>Domains Not Synced to ITGlue</h2>
                    <p>Domains missing ITGlue IDs and waiting for sync completion.</p>
                </div>
                <div class="status-table-wrap">
                    <table class="status-table">
                        <thead>
                        <tr>
                            <th>Domain</th>
                            <th>Client</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($domainsNotSyncedItglue as $domain)
                            <tr>
                                <td><a class="status-domain-link" href="{{ route('admin.domains.show', $domain) }}">{{ $domain->name }}</a></td>
                                <td>{{ $domain->client_id ? '#'.$domain->client_id : 'Unlinked' }}</td>
                                <td><span class="status-pill">{{ $domain->status ?? 'Unknown' }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">All domains currently have ITGlue links.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection
