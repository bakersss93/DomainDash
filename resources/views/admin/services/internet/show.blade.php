@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
        <div>
            <a href="{{ route('admin.services.internet') }}" style="font-size:13px;color:var(--dd-text-soft);text-decoration:none;">← Internet Services</a>
            <h1 class="dd-page-title">
                {{ $service->vocus_service_id ?? 'Internet Service' }}
            </h1>
            @if($service->address_long)
                <p style="font-size:14px;color:var(--dd-text-soft);margin:2px 0 0;">{{ $service->address_long }}</p>
            @endif
        </div>
        <div style="display:flex;gap:8px;">
            @php
                $statusColor = match($service->service_status) {
                    'ACTIVE'   => ['bg'=>'#dcfce7','text'=>'#15803d'],
                    'SUSPEND'  => ['bg'=>'#fef9c3','text'=>'#854d0e'],
                    'INACTIVE' => ['bg'=>'#fee2e2','text'=>'#b91c1c'],
                    default    => ['bg'=>'#f1f5f9','text'=>'#475569'],
                };
            @endphp
            <span style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;background:{{ $statusColor['bg'] }};color:{{ $statusColor['text'] }};">
                {{ $service->service_status }}
            </span>
            @if($service->service_type)
                <span style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;background:var(--dd-status-info-bg);color:var(--dd-status-info-fg);">
                    {{ $service->service_type }}
                </span>
            @endif
        </div>
    </div>

    {{-- Pending transaction banner --}}
    @if($service->isPending())
        <div id="pending-banner" style="margin-bottom:16px;padding:12px 16px;background:var(--dd-status-warning-bg);border:1px solid var(--warning-border);border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div style="font-size:14px;color:var(--dd-status-warning-fg);">
                <strong>Transaction in progress</strong> — ID: <code style="font-family:monospace;">{{ $service->last_transaction_id }}</code>
                &nbsp;·&nbsp; State: <strong id="txn-state">{{ $service->last_transaction_state }}</strong>
                @if($service->last_polled_at)
                    &nbsp;·&nbsp; <span id="txn-checked">Last checked {{ $service->last_polled_at->diffForHumans() }}</span>
                @endif
            </div>
            <button type="button" id="poll-btn" onclick="pollStatus()" class="btn-accent" style="padding:6px 14px;font-size:13px;">
                Refresh Status
            </button>
        </div>
    @endif

    {{-- Failed / expired transaction: show what Vocus said --}}
    @if(in_array($service->last_transaction_state, ['FAILED', 'EXPIRED', 'UNKNOWN', 'WITHDRAWN'], true))
        <x-ui.alert variant="danger" title="Vocus transaction {{ strtolower($service->last_transaction_state) }}" style="margin-bottom:16px;">
            Transaction <code>{{ $service->last_transaction_id }}</code>
            @if($service->last_fault_code) · fault code <code>{{ $service->last_fault_code }}</code>@endif
            @if($service->last_fault_message)<div>{{ $service->last_fault_message }}</div>@endif
            @if(! $service->last_fault_code && ! $service->last_fault_message)<div>Vocus did not return a fault code or message. Quote the transaction ID when contacting Vocus support.</div>@endif
        </x-ui.alert>
    @endif

    {{-- Inline poll result / error (replaces alert()) --}}
    <div id="txn-alert" aria-live="polite" style="margin-bottom:16px;"></div>

    {{-- Client suggestion --}}
    @if($clientMatch && !$service->client_id)
        <div style="margin-bottom:16px;padding:12px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:14px;color:var(--dd-status-info-fg);">
                This service may belong to <strong>{{ $clientMatch->business_name ?? $clientMatch->name }}</strong>
            </div>
            <form method="POST" action="{{ route('admin.services.internet.assignClient', $service) }}">
                @csrf
                <input type="hidden" name="client_id" value="{{ $clientMatch->id }}">
                <button type="submit" class="btn-accent" style="padding:6px 14px;font-size:13px;">Link Client</button>
            </form>
        </div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        {{-- Live service details --}}
        <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
            <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;color:var(--text);">Live Service Details</h3>
            @if($liveNotice)
                <x-ui.alert variant="info" style="margin-bottom:12px;">{{ $liveNotice }}</x-ui.alert>
            @endif
            @if($liveError)
                <x-ui.alert variant="warning" title="Live details unavailable" style="margin-bottom:12px;">{{ $liveError }}
                    <div style="font-size:12px;margin-top:4px;">Showing the details stored from the last successful sync or order.</div>
                </x-ui.alert>
            @endif
            @if($liveData)
                @php
                    $fields = [
                        'ServiceID'         => 'Service ID',
                        'ServiceStatus'     => 'Status',
                        'ServiceScope'      => 'Scope',
                        'PlanID'            => 'Plan',
                        'ServiceType'       => 'Technology',
                        'ServiceLevel'      => 'Service Level',
                        'OrderType'         => 'Order Type',
                        'CustomerName'      => 'Customer',
                        'Phone'             => 'Phone',
                        'Username'          => 'PPPoE Username',
                        'DirectoryID'       => 'Directory ID',
                        'NBNInstanceID'     => 'NBN Instance (PRI)',
                        'AVCID'             => 'AVC ID',
                        'CVCID'             => 'CVC ID',
                        'CopperPairID'      => 'Copper Pair ID',
                        'BillingProviderID' => 'Billing Provider ID',
                        'LocationReference' => 'Postcode',
                    ];
                @endphp
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                    @foreach($fields as $key => $label)
                        @if(isset($liveData[$key]) && $liveData[$key] !== '')
                            <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;white-space:nowrap;">{{ $label }}</dt>
                            <dd style="font-size:13px;margin:0;font-family:{{ in_array($key,['ServiceID','DirectoryID','NBNInstanceID','AVCID','CVCID','CopperPairID','BillingProviderID']) ? 'monospace' : 'inherit' }};">
                                {{ $liveData[$key] }}
                            </dd>
                        @endif
                    @endforeach
                </dl>
            @else
                {{-- Stored data (no live record yet, pending order, or Vocus unreachable) --}}
                @php
                    $stored = [
                        'Service ID' => $service->vocus_service_id, 'Status' => $service->service_status, 'Scope' => $service->service_scope,
                        'Plan' => $service->plan_id, 'Technology' => $service->service_type, 'Service Level' => $service->service_level,
                        'Order Type' => $service->order_type, 'Customer' => $service->customer_name, 'Phone' => $service->phone,
                        'PPPoE Username' => $service->username, 'Realm' => $service->realm, 'Directory ID' => $service->directory_id,
                        'NBN Instance (PRI)' => $service->nbn_instance_id, 'AVC ID' => $service->avc_id, 'CVC ID' => $service->cvc_id,
                        'Copper Pair ID' => $service->copper_pair_id, 'Postcode' => $service->location_reference,
                        'Last synced' => $service->synced_at?->diffForHumans(),
                    ];
                @endphp
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                    @foreach($stored as $label => $value)
                        @if($value !== null && $value !== '')
                            <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;white-space:nowrap;">{{ $label }}</dt>
                            <dd style="font-size:13px;margin:0;">{{ $value }}</dd>
                        @endif
                    @endforeach
                </dl>
            @endif
        </div>

        {{-- Service management panel --}}
        <div style="display:flex;flex-direction:column;gap:16px;">

            {{-- Client assignment --}}
            <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
                <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;color:var(--text);">Assigned Client</h3>
                @if($service->client)
                    <p style="font-size:14px;margin:0 0 10px;">
                        <a href="{{ route('admin.clients.show', $service->client) }}" style="color:var(--accent);text-decoration:none;">
                            {{ $service->client->business_name ?? $service->client->name }}
                        </a>
                    </p>
                @else
                    <p style="font-size:13px;color:var(--dd-text-soft);margin:0 0 10px;">Not assigned</p>
                @endif
                <form method="POST" action="{{ route('admin.services.internet.assignClient', $service) }}" style="display:flex;gap:8px;align-items:center;">
                    @csrf
                    <select name="client_id" class="dd-input" style="flex:1;">
                        <option value="">— None —</option>
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}" {{ $service->client_id == $client->id ? 'selected' : '' }}>
                                {{ $client->business_name ?? $client->name }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn-accent" style="padding:6px 12px;font-size:13px;">Save</button>
                </form>
            </div>

            {{-- Status actions --}}
            <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
                <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;color:var(--text);">Service Actions</h3>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    @if(! $service->canChangeStatus())
                        <p style="font-size:12px;color:var(--dd-text-soft);margin:0;">Status changes are unavailable while a Vocus transaction is in progress.</p>
                    @endif
                    @if($service->canChangeStatus() && $service->supportsSuspendResume() && $service->service_status === 'ACTIVE')
                        <form method="POST" action="{{ route('admin.services.internet.setStatus', $service) }}">
                            @csrf
                            <input type="hidden" name="status" value="SUSPEND">
                            <button type="submit" style="width:100%;padding:8px;border-radius:6px;border:1px solid var(--warning-border);background:var(--dd-status-warning-bg);color:var(--dd-status-warning-fg);font-size:13px;cursor:pointer;"
                                    onclick="return confirm('Suspend this service? The end user will lose connectivity.');">
                                Suspend Service
                            </button>
                        </form>
                    @elseif($service->canChangeStatus() && $service->supportsSuspendResume() && $service->service_status === 'SUSPEND')
                        <form method="POST" action="{{ route('admin.services.internet.setStatus', $service) }}">
                            @csrf
                            <input type="hidden" name="status" value="ACTIVE">
                            <button type="submit" style="width:100%;padding:8px;border-radius:6px;border:1px solid var(--success-border);background:var(--dd-status-success-bg);color:var(--dd-status-success-fg);font-size:13px;cursor:pointer;">
                                Resume Service
                            </button>
                        </form>
                    @endif
                    @unless($service->supportsSuspendResume())
                        <p style="font-size:12px;color:var(--dd-text-soft);margin:0;">Suspend and Resume are only available for Layer 3 / Reseller-Connect services (not Layer 2 / NCL2).</p>
                    @endunless

                    @if($service->canChangeStatus() && $service->service_status !== 'INACTIVE')
                        <form method="POST" action="{{ route('admin.services.internet.setStatus', $service) }}">
                            @csrf
                            <input type="hidden" name="status" value="INACTIVE">
                            <button type="submit" style="width:100%;padding:8px;border-radius:6px;border:1px solid color-mix(in srgb, var(--dd-status-danger-fg) 30%, transparent);background:var(--dd-status-danger-bg);color:var(--dd-danger-text);font-size:13px;cursor:pointer;"
                                    onclick="return confirm('Cancel this service? This action cannot be undone. Vocus will deactivate the NBN connection.');">
                                Cancel Service
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Diagnostics --}}
    <div style="margin-top:20px;background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;color:var(--text);">Diagnostics</h3>
        <div style="display:flex;gap:8px;margin-bottom:16px;">
            <form method="POST" action="{{ route('admin.services.internet.diagnostic', $service) }}">
                @csrf
                <input type="hidden" name="type" value="AUTH-LOG">
                <button type="submit" class="btn-accent" style="padding:7px 14px;font-size:13px;">
                    Fetch Auth Log
                </button>
            </form>
            <form method="POST" action="{{ route('admin.services.internet.diagnostic', $service) }}">
                @csrf
                <input type="hidden" name="type" value="DISCONNECT">
                <button type="submit" style="padding:7px 14px;border-radius:6px;border:1px solid var(--warning-border);background:var(--dd-status-warning-bg);color:var(--dd-status-warning-fg);font-size:13px;cursor:pointer;"
                        onclick="return confirm('Disconnect the current active session for this service?');">
                    Disconnect Session
                </button>
            </form>
        </div>

        @if($diagnostics->isNotEmpty())
            <div style="display:flex;flex-direction:column;gap:10px;">
            @foreach($diagnostics as $diag)
                <div style="background:white;border:1px solid #e5e7eb;border-radius:8px;padding:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="font-size:13px;font-weight:600;">{{ $diag->diagnostic_type }}</span>
                        <span style="font-size:12px;color:var(--dd-text-soft);">{{ $diag->created_at->diffForHumans() }}</span>
                    </div>
                    @if($diag->diagnostic_type === 'AUTH-LOG' && !empty($diag->result['records']))
                        <table style="width:100%;font-size:12px;border-collapse:collapse;">
                            <thead>
                                <tr style="color:var(--dd-text-soft);">
                                    <th style="text-align:left;padding:3px 8px;">DateTime</th>
                                    <th style="text-align:left;padding:3px 8px;">Result</th>
                                    <th style="text-align:left;padding:3px 8px;">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($diag->result['records'] as $rec)
                                <tr>
                                    <td style="padding:3px 8px;font-family:monospace;">{{ $rec['datetime'] ?? '-' }}</td>
                                    <td style="padding:3px 8px;color:{{ ($rec['auth_result'] ?? '') === 'PASS' ? '#15803d' : '#b91c1c' }};">
                                        {{ $rec['auth_result'] ?? '-' }}
                                    </td>
                                    <td style="padding:3px 8px;color:#374151;">{{ $rec['reason'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @elseif($diag->transaction_id)
                        <p style="font-size:12px;color:var(--dd-text-soft);margin:0;">Transaction: <code>{{ $diag->transaction_id }}</code></p>
                    @else
                        <p style="font-size:12px;color:var(--dd-text-soft);margin:0;">No details recorded.</p>
                    @endif
                </div>
            @endforeach
            </div>
        @else
            <p style="font-size:13px;color:var(--dd-text-soft);">No diagnostics run yet.</p>
        @endif
    </div>

    {{-- Notes --}}
    <div style="margin-top:20px;background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
        <h3 style="font-size:14px;font-weight:600;margin:0 0 10px;color:var(--text);">Internal Notes</h3>
        <p style="font-size:13px;color:#374151;white-space:pre-wrap;margin:0;">{{ $service->notes ?: '—' }}</p>
    </div>

</div>
</div>

<script>
function showTxnAlert(variant, text) {
    const box = document.getElementById('txn-alert');
    if (!box) return;
    box.replaceChildren();
    const alert = document.createElement('div');
    alert.className = 'ui-alert ui-alert--' + variant;
    alert.setAttribute('role', variant === 'danger' || variant === 'warning' ? 'alert' : 'status');
    const body = document.createElement('div');
    body.className = 'ui-alert__body';
    body.textContent = text;
    alert.appendChild(body);
    box.appendChild(alert);
}

// Manual polling only. Account-level / unknown-transaction errors stop here and are shown
// inline (no alert() loop); a Vocus error never triggers an automatic retry.
function pollStatus() {
    const btn = document.getElementById('poll-btn');
    if (btn) btn.disabled = true;
    fetch('{{ route('admin.services.internet.poll', $service) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        },
    })
    .then(r => r.json().catch(() => ({ error: 'Unexpected response from the server.', stop: true })))
    .then(data => {
        if (data.error) {
            showTxnAlert('danger', data.error);
            if (data.reload) { setTimeout(() => window.location.reload(), 2500); return; }
            if (btn && !data.stop) btn.disabled = false;
            if (btn && data.stop) btn.title = 'Fix the problem above, then reload the page to try again.';
            return;
        }
        const el = document.getElementById('txn-state');
        if (el) el.textContent = data.transaction_state;
        if (data.cooldown) {
            showTxnAlert('info', data.message || 'Checked recently. Try again later.');
            if (btn) btn.disabled = false;
            return;
        }
        if (['SUCCESS', 'FAILED', 'WITHDRAWN'].includes(data.transaction_state)) {
            window.location.reload();
            return;
        }
        showTxnAlert('info', 'Vocus reports the transaction as ' + data.transaction_state + '.');
        if (btn) btn.disabled = false;
    })
    .catch(() => {
        showTxnAlert('danger', 'Could not reach the server. Try again shortly.');
        if (btn) btn.disabled = false;
    });
}
</script>
@endsection
