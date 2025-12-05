@extends('layouts.app')

@section('content')
<div style="max-width: 1200px;">
    <h1 style="font-size: 24px; font-weight: 600; margin-bottom: 24px;">Purchase SSL Certificate</h1>

    @if(isset($error))
        <div style="padding: 16px; background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px; margin-bottom: 24px;">
            <p style="color: #991b1b; font-weight: 600;">{{ $error }}</p>
        </div>
    @endif

    <div style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
        <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">SSL Certificate Details</h2>

        @if(!empty($products))
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">SSL Product *</label>
                <div class="fancy-select-wrapper" style="width: 100%;">
                    <select id="product-id" class="fancy-select">
                        <option value="">Select an SSL product</option>
                        @foreach($products as $product)
                            <option value="{{ $product['productID'] ?? $product['id'] ?? '' }}">
                                {{ $product['productName'] ?? $product['name'] ?? 'Unknown Product' }} -
                                ${{ number_format($product['price'] ?? 0, 2) }}/year
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        @else
            <p style="color: #6b7280; margin-bottom: 24px;">No SSL products available. Please configure your Synergy API settings.</p>
        @endif

        <div style="margin-bottom: 16px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Domain Name *</label>
            <input type="text" id="domain" placeholder="e.g., example.com"
                   style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
            <p style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                The domain name to secure with SSL.
            </p>
        </div>

        <div style="margin-bottom: 16px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 500;">Certificate Period *</label>
            <div class="fancy-select-wrapper" style="width: 100%;">
                <select id="years" class="fancy-select">
                    <option value="1">1 Year</option>
                    <option value="2">2 Years</option>
                    <option value="3">3 Years</option>
                </select>
            </div>
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
                <strong>Note:</strong> After purchase, you will need to generate and submit a CSR (Certificate Signing Request) to activate the certificate.
            </p>
        </div>

        <button onclick="purchaseSSL()" class="btn-accent" style="padding: 12px 32px; font-size: 16px;">
            Purchase SSL Certificate
        </button>
    </div>
</div>

<script>
function purchaseSSL() {
    const productId = document.getElementById('product-id').value;
    const domain = document.getElementById('domain').value.trim();
    const years = parseInt(document.getElementById('years').value);
    const clientId = document.getElementById('client-id').value;

    if (!productId || !domain || !clientId) {
        alert('Please fill in all required fields.');
        return;
    }

    if (!confirm(`Are you sure you want to purchase an SSL certificate for ${domain}?`)) {
        return;
    }

    fetch('{{ route('admin.services.ssl.purchase.store') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            product_id: productId,
            domain: domain,
            years: years,
            client_id: clientId
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = '{{ route('admin.services.ssls') }}';
        } else {
            alert(data.message || 'Error purchasing SSL certificate.');
        }
    })
    .catch(err => {
        alert('Error purchasing SSL certificate.');
    });
}
</script>
@endsection
