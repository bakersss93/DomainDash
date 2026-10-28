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

        $hasBalance = is_numeric($availableBalance);
        $balanceValue = $hasBalance
            ? $balanceCurrency.' '.number_format((float) $availableBalance, 2)
            : 'Unavailable';

        $statusCards = [
            [
                'label' => 'Available Balance',
                'value' => $balanceValue,
                'sub' => 'Synergy status: '.$balanceStatus,
                'meter' => $hasBalance ? 100 : 0,
                'tone' => 'balance',
            ],
            [
                'label' => 'CPU Usage',
                'value' => $cpuValue.'%',
                'sub' => '1 minute load adjusted by core count',
                'meter' => $cpuUsage,
                'tone' => 'default',
            ],
            [
                'label' => 'RAM Usage',
                'value' => number_format($memoryUsedGb, 2).' / '.number_format($memoryTotalGb, 2).' GB',
                'sub' => number_format($memoryUsedPercent, 1).'% used',
                'meter' => $memoryUsedPercent,
                'tone' => 'default',
            ],
            [
                'label' => 'Free Disk Space',
                'value' => number_format($diskFreeGb, 2).' GB',
                'sub' => number_format($diskUsedGb, 2).' / '.number_format($diskTotalGb, 2).' GB used',
                'meter' => $diskUsedPercent,
                'tone' => 'default',
            ],
            [
                'label' => 'Domains',
                'value' => number_format($counts['domains']),
                'sub' => number_format($domainsWithoutClient->count()).' need client links',
                'meter' => min(100, $counts['domains'] > 0 ? ($domainsWithoutClient->count() / $counts['domains']) * 100 : 0),
                'tone' => 'default',
            ],
            [
                'label' => 'Clients',
                'value' => number_format($counts['clients']),
                'sub' => number_format($counts['users']).' users in platform',
                'meter' => min(100, $counts['clients'] > 0 ? ($counts['users'] / $counts['clients']) * 100 : 0),
                'tone' => 'default',
            ],
            [
                'label' => 'Users (MFA)',
                'value' => number_format($usersWithMfa).' / '.number_format($counts['users']),
                'sub' => number_format($usersWithoutMfa).' without MFA configured',
                'meter' => $counts['users'] > 0 ? ($usersWithMfa / $counts['users']) * 100 : 0,
                'tone' => 'default',
            ],
        ];
    @endphp

    <style>
        .status-shell {
            --status-bg: color-mix(in srgb, var(--bg) 94%, #cbd5e1 6%);
            --status-card: color-mix(in srgb, var(--bg) 89%, #ffffff 11%);
            --status-border: color-mix(in srgb, var(--text) 12%, transparent);
            --status-title: color-mix(in srgb, var(--text) 90%, #000000 10%);
            --status-soft: color-mix(in srgb, var(--text) 70%, transparent);
            --status-meter: color-mix(in srgb, var(--text) 15%, transparent);
            --status-row-hover: color-mix(in srgb, var(--accent) 12%, transparent);
            --status-link: color-mix(in srgb, var(--accent) 85%, #0f172a 15%);
            --status-header: color-mix(in srgb, var(--bg) 84%, #e2e8f0 16%);
            background: radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--accent) 18%, transparent) 0%, transparent 42%), var(--status-bg);
            border: 1px solid var(--status-border);
            border-radius: 16px;
            padding: 20px;
            color: var(--status-title);
            animation: status-fade-in 260ms ease-out;
        }

        html.dark .status-shell {
            --status-bg: color-mix(in srgb, var(--bg) 90%, #0f172a 10%);
            --status-card: color-mix(in srgb, var(--bg) 82%, #0f172a 18%);
            --status-border: color-mix(in srgb, #cbd5e1 18%, transparent);
            --status-soft: color-mix(in srgb, #e2e8f0 70%, transparent);
            --status-meter: color-mix(in srgb, #cbd5e1 20%, transparent);
            --status-header: color-mix(in srgb, var(--bg) 72%, #1e293b 28%);
            --status-row-hover: color-mix(in srgb, var(--accent) 16%, transparent);
            --status-link: color-mix(in srgb, var(--accent) 78%, #ffffff 22%);
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
            color: var(--status-title);
        }

        .status-title p {
            margin: 6px 0 0;
            color: var(--status-soft);
            font-size: 0.95rem;
        }

        .status-cards {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            margin-bottom: 18px;
        }

        .status-card {
            border: 1px solid var(--status-border);
            background: var(--status-card);
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }

        html.dark .status-card {
            box-shadow: 0 8px 24px rgba(2, 6, 23, 0.45);
        }

        .status-card.is-action {
            cursor: pointer;
            transition: transform 120ms ease, border-color 120ms ease;
        }

        .status-card.is-action:hover {
            transform: translateY(-2px);
            border-color: color-mix(in srgb, var(--accent) 55%, var(--status-border));
        }

        .status-card.is-balance {
            background: linear-gradient(145deg, color-mix(in srgb, var(--accent) 16%, var(--status-card) 84%), var(--status-card));
        }

        .status-card-label {
            color: var(--status-soft);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .status-card-value {
            margin: 6px 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--status-title);
        }

        .status-card-sub {
            color: var(--status-soft);
            font-size: 0.84rem;
            margin-bottom: 8px;
        }

        .status-meter {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: var(--status-meter);
            overflow: hidden;
        }

        .status-meter-fill {
            height: 100%;
            background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 65%, #0284c7 35%), #16a34a);
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 14px;
        }

        .status-panel {
            background: var(--status-card);
            border: 1px solid var(--status-border);
            border-radius: 12px;
            overflow: hidden;
            animation: status-slide 220ms ease-out;
        }

        .status-panel-head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--status-border);
            background: var(--status-header);
        }

        .status-panel-head h2 {
            margin: 0;
            font-size: 1rem;
            color: var(--status-title);
        }

        .status-panel-head p {
            margin: 4px 0 0;
            font-size: 0.82rem;
            color: var(--status-soft);
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
            border-bottom: 1px solid var(--status-border);
            text-align: left;
        }

        .status-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: var(--status-header);
            color: var(--status-soft);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-table tbody tr:hover {
            background: var(--status-row-hover);
        }

        .status-domain-link {
            color: var(--status-link);
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
            background: color-mix(in srgb, var(--status-header) 78%, var(--accent) 22%);
            color: var(--status-title);
            font-size: 0.72rem;
        }

        .status-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(2, 6, 23, 0.65);
            z-index: 60;
        }

        .status-modal-content {
            width: min(760px, 92vw);
            max-height: 82vh;
            overflow: auto;
            background: var(--status-card);
            border: 1px solid var(--status-border);
            border-radius: 12px;
            padding: 16px;
        }

        .status-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .status-modal-close {
            border: 1px solid var(--status-border);
            background: transparent;
            color: var(--status-title);
            border-radius: 6px;
            padding: 4px 10px;
        }


        @keyframes status-fade-in {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes status-slide {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
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
                <div class="status-card {{ $card['tone'] === 'balance' ? 'is-balance' : '' }} {{ $card['label'] === 'Users (MFA)' ? 'is-action' : '' }}"
                     @if($card['label'] === 'Users (MFA)') onclick="openMfaUsersModal()" @endif>
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
                                <td>{{ $domain->expiry_date ? \Illuminate\Support\Carbon::parse($domain->expiry_date)->toDateString() : '-' }}</td>
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


        <div id="mfaUsersModal" class="status-modal" onclick="closeMfaUsersModal(event)">
            <div class="status-modal-content">
                <div class="status-modal-head">
                    <div>
                        <h2 style="margin:0;font-size:1.1rem;">Users Missing MFA Setup</h2>
                        <p style="margin:4px 0 0;color:var(--status-soft);font-size:0.9rem;">Users without confirmed MFA enrollment.</p>
                    </div>
                    <button type="button" class="status-modal-close" onclick="closeMfaUsersModal()">Close</button>
                </div>

                <table class="status-table">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>MFA Policy</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($usersWithoutMfaConfigured as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td><span class="status-pill">{{ ucfirst($user->mfa_preference ?? 'enabled') }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">All users have MFA configured.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function openMfaUsersModal() {
            const modal = document.getElementById('mfaUsersModal');
            if (modal) {
                modal.style.display = 'flex';
            }
        }

        function closeMfaUsersModal(event) {
            const modal = document.getElementById('mfaUsersModal');
            if (!modal) {
                return;
            }

            if (!event || event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
@endsection

