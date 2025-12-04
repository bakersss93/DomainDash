@extends('layouts.app')

@section('content')
<div style="max-width: 1200px;">
    <h1 style="font-size: 24px; font-weight: 600; margin-bottom: 24px;">Purchase Hosting</h1>

    @if(isset($error))
        <div style="padding: 16px; background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px; margin-bottom: 24px;">
            <p style="color: #991b1b; font-weight: 600;">{{ $error }}</p>
        </div>
    @endif

    <div style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
        <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Select Hosting Plan</h2>

        @if(!empty($plans))
            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Hosting Plan *</label>
                <div class="fancy-select-wrapper" style="width: 100%;">
                    <select id="plan-id" class="fancy-select">
                        <option value="">Select a hosting plan</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan['planID'] ?? $plan['id'] ?? '' }}">
                                {{ $plan['planName'] ?? $plan['name'] ?? 'Unknown Plan' }} -
                                ${{ number_format($plan['price'] ?? 0, 2) }}/{{ $plan['period'] ?? 'month' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        @else
            <p style="color: #6b7280; margin-bottom: 24px;">No hosting plans available. Please configure your Synergy API settings.</p>
        @endif

        <div style="margin-bottom: 16px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Primary Domain *</label>
            <input type="text" id="domain" placeholder="e.g., example.com"
                   style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
            <p style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                The primary domain name for this hosting service.
            </p>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Assign to Client *</label>
            <div class="fancy-select-wrapper" style="width: 100%;">
                <select id="client-id" class="fancy-select">
                    <option value="">Select a client</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->business_name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="margin-bottom: 16px; padding: 16px; background: #dbeafe; border: 1px solid #3b82f6; border-radius: 6px;">
            <p style="color: #1e40af; font-size: 14px;">
                <strong>Note:</strong> The service email will be set to support@jargonconsulting.com.au
            </p>
        </div>

        <button onclick="purchaseHosting()" class="btn-accent" style="padding: 12px 32px; font-size: 16px;">
            Purchase Hosting
        </button>
    </div>
</div>

<script>
function purchaseHosting() {
    const planId = document.getElementById('plan-id').value;
    const domain = document.getElementById('domain').value.trim();
    const clientId = document.getElementById('client-id').value;

    if (!planId || !domain || !clientId) {
        alert('Please fill in all required fields.');
        return;
    }

    if (!confirm(`Are you sure you want to purchase hosting for ${domain}?`)) {
        return;
    }

    fetch('{{ route('admin.services.hosting.purchase.store') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            plan_id: planId,
            domain: domain,
            client_id: clientId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = '{{ route('admin.services.hosting') }}';
        } else {
            alert(data.message || 'Error purchasing hosting.');
        }
    })
    .catch(err => {
        alert('Error purchasing hosting.');
    });
}
</script>
@endsection
