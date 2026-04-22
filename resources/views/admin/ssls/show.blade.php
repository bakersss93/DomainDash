@extends('layouts.app')

@section('content')
<div style="max-width: 1100px;">
    <h1 style="font-size:24px;margin-bottom:10px;">SSL Certificate Details</h1>
    <p style="margin-bottom:16px;opacity:0.8;">
        <a href="{{ route('admin.services.ssls') }}">SSL Certificates</a> / {{ $ssl->common_name ?: 'Certificate #' . $ssl->id }}
    </p>

    @if($actionMessage)
        <div style="padding:12px;border:1px solid #3b82f6;border-radius:8px;margin-bottom:16px;">{{ $actionMessage }}</div>
    @endif

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div style="padding:16px;border:1px solid #334155;border-radius:10px;">
            <h2 style="font-size:18px;margin-bottom:10px;">Overview</h2>
            <div style="display:grid;grid-template-columns:160px 1fr;gap:8px;">
                <strong>Common Name</strong><span>{{ $ssl->common_name ?: '—' }}</span>
                <strong>Synergy Cert ID</strong><span>{{ $ssl->cert_id ?: '—' }}</span>
                <strong>Product</strong><span>{{ $ssl->product_name ?: '—' }}</span>
                <strong>Status</strong><span>{{ $ssl->status ?: '—' }}</span>
                <strong>Start Date</strong><span>{{ optional($ssl->start_date)->toDateString() ?: '—' }}</span>
                <strong>Expire Date</strong><span>{{ optional($ssl->expire_date)->toDateString() ?: '—' }}</span>
                <strong>Client</strong><span>{{ optional($ssl->client)->business_name ?: '—' }}</span>
            </div>
        </div>

        <div style="padding:16px;border:1px solid #334155;border-radius:10px;">
            <h2 style="font-size:18px;margin-bottom:10px;">Live Synergy Status</h2>
            @if($statusPayload)
                <div style="display:grid;grid-template-columns:160px 1fr;gap:8px;">
                    <strong>Status</strong><span>{{ $statusPayload['status'] ?? '—' }}</span>
                    <strong>Cert Status</strong><span>{{ $statusPayload['certStatus'] ?? '—' }}</span>
                    <strong>Product Name</strong><span>{{ $statusPayload['productName'] ?? '—' }}</span>
                    <strong>Product Years</strong><span>{{ $statusPayload['productYears'] ?? '—' }}</span>
                    <strong>Start Date</strong><span>{{ $statusPayload['startDate'] ?? '—' }}</span>
                    <strong>Expire Date</strong><span>{{ $statusPayload['expireDate'] ?? '—' }}</span>
                </div>
            @else
                <p>Live status unavailable (missing cert ID).</p>
            @endif
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div style="padding:16px;border:1px solid #334155;border-radius:10px;">
            <h2 style="font-size:18px;margin-bottom:12px;">Certificate Actions</h2>
            <form method="POST" action="{{ route('admin.services.ssl.certificate', $ssl) }}" style="margin-bottom:10px;">
                @csrf
                <button type="submit" class="btn-accent">Get Certificate / Bundle</button>
            </form>

            <form method="POST" action="{{ route('admin.services.ssl.renew', $ssl) }}" style="margin-bottom:10px;">
                @csrf
                <button type="submit" class="btn-accent">Renew Certificate</button>
            </form>

            <form method="POST" action="{{ route('admin.services.ssl.rekey', $ssl) }}">
                @csrf
                <label style="display:block;margin-bottom:6px;">New CSR for Rekey/Reissue</label>
                <textarea name="csr" rows="6" style="width:100%;margin-bottom:10px;" required placeholder="-----BEGIN CERTIFICATE REQUEST-----"></textarea>
                <button type="submit" class="btn-accent">Rekey / Reissue Certificate</button>
            </form>
        </div>

        <div style="padding:16px;border:1px solid #334155;border-radius:10px;">
            <h2 style="font-size:18px;margin-bottom:12px;">Fetched Certificate Data</h2>
            @if($certPayload)
                <label style="display:block;margin-bottom:6px;">CER</label>
                <textarea rows="5" style="width:100%;margin-bottom:8px;" readonly>{{ $certPayload['cer'] ?? '' }}</textarea>
                <label style="display:block;margin-bottom:6px;">P7B</label>
                <textarea rows="5" style="width:100%;margin-bottom:8px;" readonly>{{ $certPayload['p7b'] ?? '' }}</textarea>
                <label style="display:block;margin-bottom:6px;">CA Bundle</label>
                <textarea rows="5" style="width:100%;" readonly>{{ $certPayload['caBundle'] ?? '' }}</textarea>
            @else
                <p>No certificate payload loaded in this session yet.</p>
            @endif
        </div>
    </div>
</div>
@endsection
