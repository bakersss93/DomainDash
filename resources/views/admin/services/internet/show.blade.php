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
                    'ACTIVE'   => ['bg'=>'var(--dd-status-success-bg)','text'=>'var(--dd-status-success-fg)'],
                    'SUSPEND'  => ['bg'=>'var(--dd-status-warning-bg)','text'=>'var(--dd-status-warning-fg)'],
                    'INACTIVE' => ['bg'=>'var(--dd-status-danger-bg)','text'=>'var(--dd-status-danger-fg)'],
                    default    => ['bg'=>'var(--dd-status-muted-bg)','text'=>'var(--dd-status-muted-fg)'],
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
        <div style="margin-bottom:16px;padding:12px 16px;background:var(--dd-status-info-bg);border:1px solid var(--dd-border);color:var(--dd-text);border-radius:8px;display:flex;align-items:center;justify-content:space-between;">
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
                    // Get SERVICE response params (Product Catalogue FIBRE 4.2.2) with readable labels.
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
                        'AuthType'          => 'Authentication Type',
                        'IPAssignment'      => 'IP Type (assignment)',
                        'IPAddress'         => 'IP Address',
                        'DirectoryID'       => 'Directory ID',
                        'LocationReference' => 'Postcode',
                        'Reference'         => 'Customer Reference',
                        'Label'             => 'Label',
                        'NBNInstanceID'     => 'NBN Instance (PRI)',
                        'AVCID'             => 'AVC ID',
                        'CVCID'             => 'CVC ID',
                        'CSA'               => 'CSA',
                        'CTAG'              => 'C-Tag',
                        'STAG'              => 'S-Tag',
                        'RSPCTAG'           => 'RSP C-Tag',
                        'RSPSTAG'           => 'RSP S-Tag',
                        'CopperPairID'      => 'Copper Pair ID',
                        'VoicebandContinuity' => 'Voiceband Continuity',
                        'CarrierID'         => 'Continued phone number (FNN)',
                        'CentralSplitter'   => 'Central Splitter',
                        'StabilityProfile'  => 'Stability Profile',
                        'Battery'           => 'Battery',
                        'BatteryMonitor'    => 'Battery Monitor',
                        'NTDID'             => 'NTD ID',
                        'DataPortID'        => 'UNI-D Port (Data Port ID)',
                        'NBNCRD'            => 'NBN Customer Requested Date',
                        'TrafficClass1'     => 'Traffic Class 1',
                        'TrafficClass2'     => 'Traffic Class 2',
                        'AnniversaryDay'    => 'Anniversary Day',
                        'BillingProviderID' => 'Billing Provider ID',
                        'BundleReference'   => 'Bundle Reference',
                        'CPEDirectoryID'    => 'CPE Directory ID',
                        'CPEServiceID'      => 'CPE Service ID',
                        'CPEPlanID'         => 'CPE Plan',
                        'NBNCPEServiceID'   => 'NBN CPE Service ID',
                        'NBNCPEPlanID'      => 'NBN CPE Plan',
                        'AppointmentSlot'   => 'Appointment Slot',
                        'StartDateTime'     => 'Appointment Start',
                        'EndDateTime'       => 'Appointment End',
                    ];
                    // Never display credentials, whatever Vocus returns.
                    $hiddenKeys = ['Password', 'AccessKey', 'AliasKey'];
                    // Anything else Vocus returns is shown with a readable label.
                    $extra = collect($liveData)
                        ->reject(fn ($v, $k) => isset($fields[$k]) || in_array($k, $hiddenKeys, true) || stripos((string) $k, 'IPv6') !== false || is_array($v) || $v === null || $v === '')
                        ->mapWithKeys(fn ($v, $k) => [$k => trim(preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', (string) $k))]);
                    $fields = $fields + $extra->all();
                @endphp
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                    @foreach($fields as $key => $label)
                        @if(isset($liveData[$key]) && $liveData[$key] !== '')
                            <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;white-space:nowrap;">{{ $label }}</dt>
                            <dd style="font-size:13px;margin:0;font-family:{{ in_array($key,['ServiceID','DirectoryID','NBNInstanceID','AVCID','CVCID','CopperPairID','NTDID','DataPortID','BillingProviderID','IPAddress']) ? 'monospace' : 'inherit' }};">
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
                        'Authentication Type' => $service->auth_type, 'IP Type (assignment)' => $service->ip_assignment, 'IP Address' => $service->ip_address,
                        'Customer Reference' => $service->reference, 'Central Splitter' => $service->central_splitter,
                        'PPPoE Username' => $service->username, 'Realm' => $service->realm, 'Directory ID' => $service->directory_id,
                        'NBN Instance (PRI)' => $service->nbn_instance_id, 'AVC ID' => $service->avc_id, 'CVC ID' => $service->cvc_id,
                        'Copper Pair ID' => $service->copper_pair_id, 'NTD ID' => $service->ntd_id, 'UNI-D Port' => $service->uni_port,
                        'Service Class' => $service->service_class, 'Postcode' => $service->location_reference,
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

            {{-- Pricing snapshot taken at order time (admin only) --}}
            <div style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
                <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;color:var(--text);">Pricing (AUD / month)</h3>
                @if($service->cost_price !== null || $service->sell_price !== null)
                    <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                        <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Plan</dt>
                        <dd style="font-size:13px;margin:0;">{{ $service->vocusPlan?->name ?? $service->plan_id }}</dd>
                        <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Cost</dt>
                        <dd style="font-size:13px;margin:0;">${{ number_format((float) $service->cost_price, 2) }}</dd>
                        <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Sell</dt>
                        <dd style="font-size:13px;margin:0;">${{ number_format((float) $service->sell_price, 2) }}</dd>
                        <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Margin</dt>
                        <dd style="font-size:13px;margin:0;font-weight:600;color:{{ (float) $service->margin < 0 ? 'var(--dd-status-danger-fg)' : 'var(--dd-status-success-fg)' }};">${{ number_format((float) $service->margin, 2) }}</dd>
                    </dl>
                @else
                    <p style="font-size:13px;color:var(--dd-text-soft);margin:0;">No price recorded (service not ordered through a configured plan).</p>
                @endif
            </div>

            {{-- HaloPSA billing: subscription + recurring invoice line --}}
            @php
                $haloBilling = \App\Services\Halo\HaloBillingSettings::fromSettings();
                $haloState = $service->haloSyncState();
                $haloChip = match(true) {
                    ! $haloBilling->enabled() => ['Billing sync off', 'var(--dd-status-muted-bg)', 'var(--dd-status-muted-fg)'],
                    $haloState === 'error'    => ['Sync failed', 'var(--dd-status-danger-bg)', 'var(--dd-status-danger-fg)'],
                    $haloState === 'synced'   => ['Synced to Halo', 'var(--dd-status-success-bg)', 'var(--dd-status-success-fg)'],
                    default                   => ['Not synced yet', 'var(--dd-status-warning-bg)', 'var(--dd-status-warning-fg)'],
                };
            @endphp
            <div id="halo-billing-card" style="background:var(--surface-muted);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 12px;">
                    <h3 style="font-size:14px;font-weight:600;margin:0;color:var(--text);">HaloPSA billing</h3>
                    <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;background:{{ $haloChip[1] }};color:{{ $haloChip[2] }};">{{ $haloChip[0] }}</span>
                </div>
                <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;">
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Halo item</dt>
                    <dd style="font-size:13px;margin:0;">{{ $service->vocusPlan?->halo_item_name ?: ($service->vocusPlan?->halo_item_id ? 'Item #'.$service->vocusPlan->halo_item_id : 'Plan not linked to an item') }}</dd>
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Agreement</dt>
                    <dd style="font-size:13px;margin:0;word-break:break-word;">{{ $service->halo_contract_ref ?: ($service->halo_contract_id ? 'Agreement #'.$service->halo_contract_id : '-') }}</dd>
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Subscription</dt>
                    <dd style="font-size:13px;margin:0;">{{ $service->halo_subscription_id ? '#'.$service->halo_subscription_id : '-' }}</dd>
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Recurring invoice line</dt>
                    <dd style="font-size:13px;margin:0;">{{ $service->halo_recurring_line_id ? '#'.$service->halo_recurring_line_id.' on invoice #'.$service->halo_recurring_invoice_id : '-' }}</dd>
                    <dt style="font-size:12px;color:var(--dd-text-soft);font-weight:500;">Last synced</dt>
                    <dd style="font-size:13px;margin:0;">{{ $service->halo_synced_at ? $service->halo_synced_at->diffForHumans() : 'never' }}</dd>
                </dl>
                @if($service->halo_sync_error)
                    <p role="alert" style="font-size:13px;margin:10px 0 0;color:var(--dd-danger-text);word-break:break-word;">{{ $service->halo_sync_error }}</p>
                @endif
                @if($service->client_id && $service->client?->halopsa_reference && auth()->user()?->can('sync.run'))
                    <div id="halo-svc-ri-box" style="margin-top:12px;border-top:1px solid var(--border-subtle);padding-top:12px;"
                         data-list-url="{{ route('admin.services.internet.halo.recurringInvoices', $service) }}"
                         data-save-url="{{ route('admin.services.internet.halo.recurringInvoice', $service) }}">
                        <label for="halo-svc-ri-select" style="display:block;font-size:12px;color:var(--dd-text-soft);font-weight:500;margin-bottom:4px;">Bill on this agreement / recurring invoice</label>
                        <p id="halo-svc-ri-current" style="font-size:13px;margin:0 0 6px;color:var(--text);word-break:break-word;">
                            @if($service->halo_override_invoice_id)
                                {{ $service->halo_override_invoice_name ?: 'Recurring invoice' }} (id {{ $service->halo_override_invoice_id }})
                            @else
                                <span style="color:var(--dd-text-soft);">Automatic: found by the agreement rule in Settings → HaloPSA → Billing.</span>
                            @endif
                        </p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <select id="halo-svc-ri-select" class="dd-input" style="flex:1;min-width:180px;display:none;"></select>
                            <button type="button" id="halo-svc-ri-load" class="btn-accent" style="padding:6px 12px;font-size:13px;white-space:nowrap;">Choose</button>
                            <button type="button" id="halo-svc-ri-save" class="btn-accent" style="padding:6px 12px;font-size:13px;white-space:nowrap;display:none;">Save choice</button>
                            <button type="button" id="halo-svc-ri-clear" style="padding:6px 12px;font-size:13px;border-radius:4px;border:1px solid var(--dd-danger);color:var(--dd-danger-text);background:transparent;white-space:nowrap;{{ $service->halo_override_invoice_id ? '' : 'display:none;' }}">Use automatic</button>
                        </div>
                        <div id="halo-svc-ri-msg" role="status" aria-live="polite" style="font-size:12px;margin-top:6px;min-height:1em;"></div>
                    </div>
                    <script>
                    (function () {
                        var box = document.getElementById('halo-svc-ri-box');
                        if (!box) { return; }
                        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                        var sel = document.getElementById('halo-svc-ri-select'), msg = document.getElementById('halo-svc-ri-msg');
                        var cur = document.getElementById('halo-svc-ri-current'), clear = document.getElementById('halo-svc-ri-clear');
                        function say(t, ok) { msg.textContent = t || ''; msg.style.color = ok ? 'var(--dd-status-success-fg)' : 'var(--dd-danger-text)'; }
                        function call(method, url, body) {
                            return fetch(url, { method: method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined })
                                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, json: j }; }); });
                        }
                        function show(j) {
                            cur.textContent = j.selected_id ? ((j.selected_name || 'Recurring invoice') + ' (id ' + j.selected_id + ')') : 'Automatic: found by the agreement rule in Settings → HaloPSA → Billing.';
                            clear.style.display = j.selected_id ? '' : 'none';
                        }
                        document.getElementById('halo-svc-ri-load').addEventListener('click', function () {
                            say('Loading recurring invoices from HaloPSA...', true);
                            call('GET', box.dataset.listUrl).then(function (r) {
                                if (!r.ok) { say(r.json.error || r.json.message || 'Could not load recurring invoices.'); return; }
                                sel.textContent = '';
                                var first = document.createElement('option'); first.value = '';
                                first.textContent = r.json.invoices.length ? 'Select an agreement / recurring invoice' : 'No recurring invoices found for this client';
                                sel.appendChild(first);
                                r.json.invoices.forEach(function (i) {
                                    var o = document.createElement('option'); o.value = String(i.id);
                                    o.textContent = (i.contract_ref ? i.contract_ref + ' - ' : '') + i.name + ' (id ' + i.id + ')';
                                    if (r.json.selected_id === i.id) { o.selected = true; }
                                    sel.appendChild(o);
                                });
                                sel.style.display = ''; document.getElementById('halo-svc-ri-save').style.display = '';
                                say(r.json.invoices.length + ' recurring invoice(s) found.', true);
                            }).catch(function () { say('Could not reach the server.'); });
                        });
                        function save(id) {
                            call('PUT', box.dataset.saveUrl, { recurring_invoice_id: id }).then(function (r) {
                                if (!r.ok) { var e = r.json.errors ? Object.values(r.json.errors).flat()[0] : null; say(e || r.json.error || r.json.message || 'Could not save.'); return; }
                                show(r.json); say(id ? 'Saved. The service is queued for a Halo update.' : 'Back to automatic.', true);
                            }).catch(function () { say('Could not reach the server.'); });
                        }
                        document.getElementById('halo-svc-ri-save').addEventListener('click', function () {
                            if (!sel.value) { say('Pick a recurring invoice first.'); return; }
                            save(parseInt(sel.value, 10));
                        });
                        clear.addEventListener('click', function () { save(null); });
                    })();
                    </script>
                @endif
                @if(auth()->user()?->can('sync.run'))
                    <form method="POST" action="{{ route('admin.services.internet.halo-sync.one', $service) }}" style="margin-top:12px;">
                        @csrf
                        <button type="submit" class="btn-accent" style="padding:6px 14px;font-size:13px;" @disabled(! $service->client_id || ! $haloBilling->enabled())>Sync to Halo</button>
                        @if(! $service->client_id)
                            <span style="font-size:12px;color:var(--dd-text-soft);margin-left:8px;">Assign a client first.</span>
                        @elseif(! $haloBilling->enabled())
                            <span style="font-size:12px;color:var(--dd-text-soft);margin-left:8px;">Enable billing sync in Settings → HaloPSA.</span>
                        @endif
                    </form>
                @endif
            </div>

            <x-asset-sync-card entity="internet_service" :record="$service" />

            {{-- Client assignment --}}
            <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:18px;">
                <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;color:var(--text);">Assigned Client</h3>
                @if($service->client)
                    <p style="font-size:14px;margin:0 0 10px;">
                        <a href="{{ route('admin.clients.edit', $service->client) }}" style="color:var(--accent);text-decoration:none;">
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
        @if($service->isLayer2())
            <p style="font-size:12px;color:var(--dd-text-soft);margin:0 0 12px;">The authentication log and session disconnect are only available for Reseller-Connect (Layer 3) services.</p>
        @endif
        <div style="display:@if($service->isLayer2()) none @else flex @endif;gap:8px;margin-bottom:16px;">
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
                <div style="background:var(--dd-surface);border:1px solid var(--dd-border);color:var(--dd-text);border-radius:8px;padding:12px;">
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
                                    <td style="padding:3px 8px;color:var(--dd-text);">{{ $rec['reason'] ?? '-' }}</td>
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
        <p style="font-size:13px;color:var(--dd-text);white-space:pre-wrap;margin:0;">{{ $service->notes ?: '—' }}</p>
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
