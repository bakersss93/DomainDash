@extends('layouts.app')

@section('content')
<div style="max-width: 1200px; margin: 0 auto;">
    <div class="dd-service-overview-container">
        {{-- Header --}}
        <div class="dd-overview-header">
            <div>
                <h1 class="dd-overview-title">
                    {{ $service->domain_name ?? $service->username ?? 'Service #' . $service->id }}
                </h1>
                <p class="dd-overview-subtitle">
                    <a href="{{ route('admin.services.hosting') }}" class="dd-breadcrumb-link">Services</a>
                    <span class="dd-breadcrumb-separator">/</span>
                    <span>Overview</span>
                </p>
            </div>
            <div class="dd-overview-actions">
                <form method="POST" action="{{ route('admin.services.hosting.login', $service) }}" target="_blank" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn-accent dd-pill-btn">
                        Open cPanel
                    </button>
                </form>
            </div>
        </div>

        @if($error)
            <div class="dd-alert dd-alert-error">
                <strong>Error:</strong> Failed to retrieve service data from Synergy.
                <br><small>{{ $error }}</small>
                <br><small style="margin-top: 8px; display: block;">Displaying cached data from database below. Check Laravel logs for details.</small>
            </div>
        @elseif($serviceData && !isset($serviceData['domain']))
            <div class="dd-alert dd-alert-warning">
                <strong>Warning:</strong> {{ $serviceData['errorMessage'] ?? 'Unable to fetch live data from Synergy.' }}
                <br><small>Displaying cached data from database below.</small>
            </div>
        @elseif($serviceData)
            <div class="dd-alert dd-alert-success">
                <strong>Success:</strong> Live data retrieved from Synergy Wholesale.
                <br><small>Last updated: {{ now()->format('d M Y H:i:s') }}</small>
            </div>
        @endif

        {{-- Service Information Cards --}}
        <div class="dd-stats-grid">
            {{-- Basic Info Card --}}
            <div class="dd-stat-card">
                <div class="dd-stat-card-header">
                    <h2 class="dd-stat-card-title">Service Information</h2>
                </div>
                <div class="dd-stat-card-body">
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Domain</span>
                        <span class="dd-stat-value">{{ $serviceData['domain'] ?? $service->domain_name ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Username</span>
                        <span class="dd-stat-value">{{ $serviceData['username'] ?? $service->username ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Password</span>
                        <button type="button"
                                class="dd-password-show-btn dd-password-btn"
                                data-password-id="{{ $service->id }}">
                            Show password
                        </button>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Server</span>
                        <span class="dd-stat-value">{{ $serviceData['server'] ?? $service->server ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Status</span>
                        <span class="dd-stat-value dd-status-badge dd-status-{{ strtolower($serviceData['status'] ?? $service->service_status ?? 'unknown') }}">
                            {{ $serviceData['status'] ?? $service->service_status ?? 'Unknown' }}
                        </span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Plan</span>
                        <span class="dd-stat-value">{{ $serviceData['plan'] ?? $service->plan ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Product</span>
                        <span class="dd-stat-value">{{ $serviceData['product'] ?? '—' }}</span>
                    </div>
                </div>
            </div>

            {{-- Network Info Card --}}
            <div class="dd-stat-card">
                <div class="dd-stat-card-header">
                    <h2 class="dd-stat-card-title">Network Information</h2>
                </div>
                <div class="dd-stat-card-body">
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">IP Address</span>
                        <span class="dd-stat-value">{{ $serviceData['dedicatedIPv4'] ?? $service->ip ?? $service->ip_address ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Location</span>
                        <span class="dd-stat-value">
                            @if(isset($serviceData['city']) || isset($serviceData['country']))
                                {{ $serviceData['city'] ?? '' }}{{ isset($serviceData['city']) && isset($serviceData['country']) ? ', ' : '' }}{{ $serviceData['country'] ?? '' }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Location ID</span>
                        <span class="dd-stat-value">{{ $serviceData['locationID'] ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Temp URL</span>
                        <span class="dd-stat-value">
                            @if(isset($serviceData['tempUrl']))
                                {{ $serviceData['tempUrl'] ? 'Enabled' : 'Disabled' }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- Disk Usage Card --}}
            <div class="dd-stat-card">
                <div class="dd-stat-card-header">
                    <h2 class="dd-stat-card-title">Disk Usage</h2>
                </div>
                <div class="dd-stat-card-body">
                    @php
                        // Convert to float as API returns strings with 'M' suffix (e.g., "1043M")
                        $diskUsage = $serviceData['diskUsage'] ?? $service->disk_usage_mb ?? null;
                        if ($diskUsage) {
                            // Remove 'M' suffix and convert to float
                            $diskUsage = (float) str_replace(['M', 'G'], '', $diskUsage);
                        }

                        $diskLimit = $serviceData['diskLimit'] ?? $service->disk_limit_mb ?? null;
                        if ($diskLimit) {
                            // Remove 'M' suffix and convert to float
                            $diskLimit = (float) str_replace(['M', 'G'], '', $diskLimit);
                        }

                        $diskPercent = ($diskUsage && $diskLimit) ? round(($diskUsage / $diskLimit) * 100, 1) : null;
                    @endphp
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Used</span>
                        <span class="dd-stat-value">{{ $diskUsage ? number_format($diskUsage) . ' MB' : '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Limit</span>
                        <span class="dd-stat-value">{{ $diskLimit ? number_format($diskLimit) . ' MB' : '—' }}</span>
                    </div>
                    @if($diskPercent !== null)
                        <div class="dd-stat-row dd-full-width">
                            <div class="dd-progress-bar">
                                <div class="dd-progress-fill" style="width: {{ min($diskPercent, 100) }}%"></div>
                            </div>
                            <span class="dd-progress-text">{{ $diskPercent }}% used</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Bandwidth Card --}}
            <div class="dd-stat-card">
                <div class="dd-stat-card-header">
                    <h2 class="dd-stat-card-title">Bandwidth</h2>
                </div>
                <div class="dd-stat-card-body">
                    @php
                        // Convert to float as API returns mixed types
                        $bwUsed = $serviceData['bandwidth'] ?? $service->bandwidth_used_mb ?? null;
                        $bwUsed = $bwUsed ? (float) $bwUsed : null;

                        // Get bandwidth limit from package if available
                        $bwLimit = $service->bandwidth_limit_mb ?? ($package->bandwidth_mb ?? null);
                        $bwLimit = $bwLimit ? (float) $bwLimit : null;

                        $bwPercent = ($bwUsed && $bwLimit) ? round(($bwUsed / $bwLimit) * 100, 1) : null;
                    @endphp
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Used</span>
                        <span class="dd-stat-value">
                            @if($bwUsed)
                                {{ $bwUsed >= 1024 ? number_format($bwUsed / 1024, 2) . ' GB' : number_format($bwUsed) . ' MB' }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Limit</span>
                        <span class="dd-stat-value">
                            @if($bwLimit)
                                {{ $bwLimit >= 1024 ? number_format($bwLimit / 1024, 2) . ' GB' : number_format($bwLimit) . ' MB' }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    @if($bwPercent !== null)
                        <div class="dd-stat-row dd-full-width">
                            <div class="dd-progress-bar">
                                <div class="dd-progress-fill" style="width: {{ min($bwPercent, 100) }}%"></div>
                            </div>
                            <span class="dd-progress-text">{{ $bwPercent }}% used</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Billing Info Card --}}
            <div class="dd-stat-card">
                <div class="dd-stat-card-header">
                    <h2 class="dd-stat-card-title">Billing Information</h2>
                </div>
                <div class="dd-stat-card-body">
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Billing Period</span>
                        <span class="dd-stat-value">{{ $serviceData['billingPeriod'] ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Next Renewal</span>
                        <span class="dd-stat-value">
                            {{ $serviceData['nextRenewalDue'] ?? ($service->next_renewal_due ? $service->next_renewal_due->format('d M Y') : '—') }}
                        </span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Plan</span>
                        <span class="dd-stat-value">{{ $serviceData['plan'] ?? $service->plan ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Client</span>
                        <span class="dd-stat-value">
                            @if($service->client)
                                {{ $service->client->business_name ?? $service->client->name }}
                            @else
                                <span style="opacity: 0.6;">Not assigned</span>
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- Technical Details Card --}}
            <div class="dd-stat-card">
                <div class="dd-stat-card-header">
                    <h2 class="dd-stat-card-title">Technical Details</h2>
                </div>
                <div class="dd-stat-card-body">
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">HOID</span>
                        <span class="dd-stat-value">{{ $service->hoid ?? '—' }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Database ID</span>
                        <span class="dd-stat-value">{{ $service->id }}</span>
                    </div>
                    <div class="dd-stat-row">
                        <span class="dd-stat-label">Last Synced</span>
                        <span class="dd-stat-value">{{ $service->updated_at->diffForHumans() }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Raw Data (for debugging, can be removed) --}}
        @if($serviceData && config('app.debug'))
            <details class="dd-stat-card" style="margin-top: 24px;">
                <summary class="dd-stat-card-header" style="cursor: pointer;">
                    <h2 class="dd-stat-card-title">Raw API Response (Debug)</h2>
                </summary>
                <div class="dd-stat-card-body">
                    <pre style="font-size: 12px; overflow-x: auto; background: rgba(0,0,0,0.1); padding: 12px; border-radius: 4px;">{{ json_encode($serviceData, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </details>
        @endif
    </div>
</div>

<style>
    :root {
        --dd-card-bg: #ffffff;
        --dd-card-border: #d1d5db;
        --dd-text-color: #111827;
        --dd-text-muted: #6b7280;
        --dd-accent: #4ade80;
        --dd-progress-bg: rgba(148,163,184,0.2);
        --dd-progress-fill: #4ade80;
        --dd-pill-bg: #f3f4f6;
        --dd-overlay-bg: rgba(15,23,42,0.65);
    }

    body.dark-mode,
    body[data-theme="dark"],
    html.dark,
    html[data-theme="dark"] {
        --dd-card-bg: #020617;
        --dd-card-border: #1f2937;
        --dd-text-color: #e5e7eb;
        --dd-text-muted: #9ca3af;
        --dd-progress-bg: rgba(148,163,184,0.15);
        --dd-progress-fill: #4ade80;
        --dd-pill-bg: #0f172a;
        --dd-overlay-bg: rgba(15,23,42,0.85);
    }

    .dd-service-overview-container {
        padding: 24px 0;
    }

    .dd-overview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .dd-overview-title {
        font-size: 24px;
        font-weight: 600;
        margin: 0 0 4px 0;
        color: var(--dd-text-color);
    }

    .dd-overview-subtitle {
        font-size: 14px;
        color: var(--dd-text-muted);
        margin: 0;
    }

    .dd-breadcrumb-link {
        color: var(--dd-accent);
        text-decoration: none;
    }

    .dd-breadcrumb-link:hover {
        text-decoration: underline;
    }

    .dd-breadcrumb-separator {
        margin: 0 8px;
        opacity: 0.5;
    }

    .dd-overview-actions {
        display: flex;
        gap: 8px;
    }

    .dd-pill-btn {
        border-radius: 9999px !important;
        padding: 8px 16px !important;
    }

    .dd-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 20px;
    }

    .dd-stat-card {
        background: var(--dd-card-bg);
        border: 1px solid var(--dd-card-border);
        border-radius: 12px;
        overflow: hidden;
    }

    .dd-stat-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--dd-card-border);
        background: var(--dd-card-bg);
    }

    .dd-stat-card-title {
        font-size: 16px;
        font-weight: 600;
        margin: 0;
        color: var(--dd-text-color);
    }

    .dd-stat-card-body {
        padding: 16px 20px;
    }

    .dd-stat-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid rgba(148,163,184,0.1);
    }

    .dd-stat-row:last-child {
        border-bottom: none;
    }

    .dd-stat-row.dd-full-width {
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
    }

    .dd-stat-label {
        font-size: 13px;
        font-weight: 500;
        color: var(--dd-text-muted);
    }

    .dd-stat-value {
        font-size: 14px;
        font-weight: 500;
        color: var(--dd-text-color);
        text-align: right;
    }

    .dd-status-badge {
        padding: 4px 12px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .dd-status-active {
        background: rgba(74, 222, 128, 0.2);
        color: #22c55e;
    }

    .dd-status-suspended {
        background: rgba(251, 191, 36, 0.2);
        color: #f59e0b;
    }

    .dd-status-terminated {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }

    .dd-status-unknown {
        background: rgba(148, 163, 184, 0.2);
        color: var(--dd-text-muted);
    }

    .dd-progress-bar {
        width: 100%;
        height: 8px;
        background: var(--dd-progress-bg);
        border-radius: 9999px;
        overflow: hidden;
    }

    .dd-progress-fill {
        height: 100%;
        background: var(--dd-progress-fill);
        border-radius: 9999px;
        transition: width 0.3s ease;
    }

    .dd-progress-text {
        font-size: 12px;
        color: var(--dd-text-muted);
        text-align: center;
    }

    .dd-link {
        color: var(--dd-accent);
        text-decoration: none;
    }

    .dd-link:hover {
        text-decoration: underline;
    }

    .dd-alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .dd-alert-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #ef4444;
    }

    .dd-alert-warning {
        background: rgba(251, 191, 36, 0.1);
        border: 1px solid rgba(251, 191, 36, 0.3);
        color: #f59e0b;
    }

    .dd-alert-success {
        background: rgba(74, 222, 128, 0.1);
        border: 1px solid rgba(74, 222, 128, 0.3);
        color: #22c55e;
    }

    .dd-password-show-btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 6px 14px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        transition: opacity 0.2s;
    }

    .dd-password-show-btn:hover {
        opacity: 0.9;
    }
</style>

{{-- Password modal --}}
<div id="dd-password-modal" class="dd-password-modal dd-hidden" aria-hidden="true">
    <div class="dd-password-backdrop" data-dd-password-close></div>
    <div class="dd-password-panel">
        <h2 class="dd-password-title">Service password</h2>
        <div class="dd-password-input-wrapper">
            <input id="dd-password-input"
                type="password"
                readonly
                class="dd-pill-input dd-password-input"
                value="••••••••">
            <button type="button"
                    id="dd-password-toggle"
                    class="dd-password-toggle"
                    aria-label="Toggle password visibility">
                👁
            </button>
        </div>
        <div class="dd-password-actions">
            <button type="button"
                    class="btn-secondary dd-pill-btn"
                    data-dd-password-close>
                Close
            </button>
            <button type="button"
                    class="btn-accent dd-pill-btn"
                    id="dd-password-copy">
                Copy to clipboard
            </button>
        </div>
    </div>
</div>

<style>
    .dd-password-modal {
        position: fixed;
        inset: 0;
        z-index: 999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .dd-password-backdrop {
        position: absolute;
        inset: 0;
        background: var(--dd-overlay-bg);
    }

    .dd-password-panel {
        position: relative;
        z-index: 1000;
        max-width: 420px;
        width: 100%;
        border-radius: 12px;
        padding: 20px;
        background: var(--dd-card-bg);
        border: 1px solid var(--dd-card-border);
    }

    .dd-password-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 10px;
        color: var(--dd-text-color);
    }

    .dd-password-input-wrapper {
        position: relative;
        margin-bottom: 10px;
    }

    .dd-password-input {
        width: 100%;
        padding-right: 44px !important;
    }

    .dd-password-toggle {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 18px;
        color: var(--dd-text-color);
        opacity: 0.7;
        padding: 4px 8px;
        border-radius: 4px;
        transition: opacity 0.2s ease, background-color 0.2s ease;
    }

    .dd-password-toggle:hover {
        opacity: 1;
        background: var(--dd-pill-bg);
    }

    .dd-password-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    .dd-password-modal.dd-hidden {
        display: none !important;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const passwordModal = document.getElementById('dd-password-modal');
    const passwordInput = document.getElementById('dd-password-input');
    const passwordToggle = document.getElementById('dd-password-toggle');
    const passwordCopy = document.getElementById('dd-password-copy');

    function openPasswordModal(password) {
        if (passwordInput) {
            passwordInput.value = password || 'Unavailable';
            passwordInput.type = 'password';
        }
        if (passwordToggle) {
            passwordToggle.textContent = '👁';
        }
        passwordModal.classList.remove('dd-hidden');
        passwordModal.setAttribute('aria-hidden', 'false');
    }

    function closePasswordModal() {
        passwordModal.classList.add('dd-hidden');
        passwordModal.setAttribute('aria-hidden', 'true');
        if (passwordInput) {
            passwordInput.value = '••••••••';
            passwordInput.type = 'password';
        }
    }

    // Open modal on "Show password" button click
    document.querySelectorAll('.dd-password-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const id = btn.getAttribute('data-password-id');

            fetch('{{ url('admin/services/hosting') }}/' + id + '/password', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    openPasswordModal(data.password);
                } else {
                    alert('Error: ' + (data.message || 'Unable to fetch password'));
                }
            })
            .catch(err => {
                alert('Error fetching password: ' + err.message);
            });
        });
    });

    // Close modal
    document.querySelectorAll('[data-dd-password-close]').forEach(function (btn) {
        btn.addEventListener('click', closePasswordModal);
    });

    // Toggle password visibility
    if (passwordToggle) {
        passwordToggle.addEventListener('click', function () {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordToggle.textContent = '🙈';
            } else {
                passwordInput.type = 'password';
                passwordToggle.textContent = '👁';
            }
        });
    }

    // Copy to clipboard
    if (passwordCopy) {
        passwordCopy.addEventListener('click', function () {
            passwordInput.select();
            document.execCommand('copy');

            const originalText = passwordCopy.textContent;
            passwordCopy.textContent = 'Copied!';
            setTimeout(() => {
                passwordCopy.textContent = originalText;
            }, 2000);
        });
    }
});
</script>
@endsection
