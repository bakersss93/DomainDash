@extends('layouts.app')

@section('content')
<div class="dd-page">
<div class="dd-card" style="max-width:760px;">
    <a href="{{ route('admin.services.internet.qualify') }}" style="font-size:13px;color:#6b7280;text-decoration:none;">← Qualify Address</a>
    <h1 class="dd-page-title" style="font-size:1.35rem;margin:8px 0 4px;">Transfer / Churn Service</h1>
    <p style="font-size:14px;color:#6b7280;margin:0 0 4px;">Transfer an existing NBN service from another provider to Vocus Wholesale.</p>
    <p style="font-size:13px;color:#d97706;background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;margin:0 0 24px;">
        <strong>Note:</strong> A CHURN order will contact the customer's current provider. Ensure the customer has authorised this transfer and that the <strong>Customer Authorisation Date</strong> is correct.
    </p>

    @if($errors->any())
        <div style="margin-bottom:16px;padding:12px 16px;background:#fff1f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:14px;">
            <strong>Please correct the following errors:</strong>
            <ul style="margin:6px 0 0 16px;padding:0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.services.internet.transfer') }}">
        @csrf

        {{-- Address --}}
        <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:16px;">
            <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Service Address</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Address</label>
                    <input type="text" name="address_long" value="{{ old('address_long', $address_long) }}"
                           class="dd-input" style="width:100%;font-size:13px;" readonly
                           placeholder="Pre-filled from qualify step">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Directory ID <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="directory_id" value="{{ old('directory_id', $directory_id) }}"
                           class="dd-input" style="width:100%;font-family:monospace;font-size:13px;" required>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Postcode / Location Reference <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="location_reference" value="{{ old('location_reference') }}"
                           class="dd-input" style="width:100%;font-size:13px;" placeholder="e.g. 3000" maxlength="10" required>
                </div>
            </div>
        </div>

        {{-- Service details --}}
        <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:16px;">
            <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Service Details</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Vocus Service ID <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="vocus_service_id" value="{{ old('vocus_service_id') }}"
                           class="dd-input" style="width:100%;font-family:monospace;font-size:13px;" placeholder="e.g. 0712345678" required maxlength="30">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Plan ID <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="plan_id" value="{{ old('plan_id') }}"
                           class="dd-input" style="width:100%;font-size:13px;" placeholder="e.g. NBN-25/10" required maxlength="30">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Technology <span style="color:#b91c1c;">*</span></label>
                    <select name="service_type" class="dd-input" style="width:100%;font-size:13px;" required>
                        <option value="">Select…</option>
                        @foreach(['FTTP','FTTC','FTTB','FTTN','HFC','FIXED-WIRELESS'] as $t)
                            <option value="{{ $t }}" {{ old('service_type', $service_type) === $t ? 'selected' : '' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Service Scope <span style="color:#b91c1c;">*</span></label>
                    <select name="scope" class="dd-input" style="width:100%;font-size:13px;" required>
                        <option value="RESELLER-CONNECT" {{ old('scope') === 'RESELLER-CONNECT' ? 'selected' : '' }}>RESELLER-CONNECT</option>
                        <option value="NETWORK-CONNECT" {{ old('scope') === 'NETWORK-CONNECT' ? 'selected' : '' }}>NETWORK-CONNECT</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Service Level</label>
                    <select name="service_level" class="dd-input" style="width:100%;font-size:13px;">
                        <option value="STANDARD" {{ old('service_level', 'STANDARD') === 'STANDARD' ? 'selected' : '' }}>STANDARD</option>
                        <option value="ENHANCED" {{ old('service_level') === 'ENHANCED' ? 'selected' : '' }}>ENHANCED</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Copper Pair ID <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="copper_pair_id" value="{{ old('copper_pair_id', $copper_pair_id) }}"
                           class="dd-input" style="width:100%;font-family:monospace;font-size:13px;" placeholder="Pre-filled from qualify" required maxlength="30">
                    <p style="font-size:11px;color:#9ca3af;margin:3px 0 0;">Required for CHURN orders.</p>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">AVC ID</label>
                    <input type="text" name="avc_id" value="{{ old('avc_id') }}"
                           class="dd-input" style="width:100%;font-family:monospace;font-size:13px;" placeholder="Optional — for FTTP" maxlength="30">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">CVC ID</label>
                    <input type="text" name="cvc_id" value="{{ old('cvc_id') }}"
                           class="dd-input" style="width:100%;font-family:monospace;font-size:13px;" placeholder="Optional — for FTTP" maxlength="30">
                </div>
            </div>
        </div>

        {{-- Customer authorisation --}}
        <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:16px;">
            <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Customer Authorisation</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Customer Name <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="customer_name" value="{{ old('customer_name') }}"
                           class="dd-input" style="width:100%;font-size:13px;" placeholder="Full name on account" required maxlength="100">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Phone <span style="color:#b91c1c;">*</span></label>
                    <input type="text" name="phone" value="{{ old('phone') }}"
                           class="dd-input" style="width:100%;font-size:13px;" placeholder="e.g. 0412345678" required maxlength="20">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">Customer Authorisation Date (CADate) <span style="color:#b91c1c;">*</span></label>
                    <input type="date" name="ca_date" value="{{ old('ca_date', now()->format('Y-m-d')) }}"
                           class="dd-input" style="width:100%;font-size:13px;" required>
                    <p style="font-size:11px;color:#9ca3af;margin:3px 0 0;">Date the customer authorised the transfer. Must not be in the past.</p>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;margin-bottom:4px;color:#6b7280;">PPPoE Realm</label>
                    <input type="text" name="realm" value="{{ old('realm') }}"
                           class="dd-input" style="width:100%;font-size:13px;" placeholder="e.g. customer@isp.net.au" maxlength="64">
                </div>
            </div>
        </div>

        {{-- Link to client --}}
        <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:16px;">
            <h3 style="font-size:14px;font-weight:600;margin:0 0 14px;">Link to Client (optional)</h3>
            <select name="client_id" class="dd-input" style="width:100%;font-size:13px;">
                <option value="">— None —</option>
                @foreach(\App\Models\Client::orderBy('business_name')->get() as $client)
                    <option value="{{ $client->id }}" {{ old('client_id') == $client->id ? 'selected' : '' }}>
                        {{ $client->business_name ?? $client->name }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Notes --}}
        <div style="background:var(--surface-muted,#f8fafc);border:1px solid var(--border-subtle);border-radius:10px;padding:20px;margin-bottom:20px;">
            <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Internal Notes</h3>
            <textarea name="notes" class="dd-input" style="width:100%;font-size:13px;min-height:80px;resize:vertical;" placeholder="Optional internal notes…" maxlength="500">{{ old('notes') }}</textarea>
        </div>

        <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn-accent" style="padding:10px 24px;font-size:14px;font-weight:600;"
                    onclick="return confirm('Submit this CHURN/transfer order to Vocus? The customer\'s current provider will be contacted.');">
                Submit Transfer →
            </button>
            <a href="{{ route('admin.services.internet') }}" style="font-size:14px;color:#6b7280;text-decoration:none;">Cancel</a>
        </div>
    </form>
</div>
</div>
@endsection
