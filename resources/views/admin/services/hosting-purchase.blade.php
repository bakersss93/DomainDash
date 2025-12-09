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

        @if(isset($packages) && $packages->count() > 0)
            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Hosting Package *</label>
                <div class="fancy-select-wrapper" style="width: 100%;">
                    <select id="plan-id" class="fancy-select" onchange="showPackageDetails()">
                        <option value="">Select a hosting package</option>
                        @foreach($packages as $package)
                            <option value="{{ $package->package_name }}"
                                    data-disk="{{ $package->disk_mb }}"
                                    data-memory="{{ $package->memory_mb }}"
                                    data-cpu="{{ $package->cpu_percent }}"
                                    data-bandwidth="{{ $package->bandwidth_mb }}"
                                    data-price="{{ $package->price_monthly }}">
                                {{ $package->package_name }}
                                @if(auth()->user()->hasRole('admin') && $package->price_monthly)
                                    - ${{ number_format($package->price_monthly, 2) }}/month
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <!-- Package Details Section -->
            <div id="package-details" style="display: none; margin-bottom: 24px; padding: 16px; background: var(--bg-secondary, #f9fafb); border: 1px solid #e5e7eb; border-radius: 6px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: #6b7280;">Package Resources</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                    <div>
                        <span style="font-size: 12px; color: #6b7280;">Disk Space</span>
                        <p id="detail-disk" style="font-size: 14px; font-weight: 500; margin-top: 2px;">-</p>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #6b7280;">Memory</span>
                        <p id="detail-memory" style="font-size: 14px; font-weight: 500; margin-top: 2px;">-</p>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #6b7280;">CPU</span>
                        <p id="detail-cpu" style="font-size: 14px; font-weight: 500; margin-top: 2px;">-</p>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #6b7280;">Bandwidth</span>
                        <p id="detail-bandwidth" style="font-size: 14px; font-weight: 500; margin-top: 2px;">-</p>
                    </div>
                </div>
            </div>
        @else
            <p style="color: #6b7280; margin-bottom: 24px;">No hosting packages available. Please <a href="{{ route('admin.services.hosting') }}" style="color: #3b82f6; text-decoration: underline;">sync hosting services</a> first to load available packages.</p>
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
function formatBytes(mb) {
    if (!mb || mb === 0) return '-';
    if (mb >= 1024) {
        return (mb / 1024).toFixed(0) + ' GB';
    }
    return mb + ' MB';
}

function showPackageDetails() {
    const select = document.getElementById('plan-id');
    const detailsDiv = document.getElementById('package-details');
    const selectedOption = select.options[select.selectedIndex];

    if (!select.value) {
        detailsDiv.style.display = 'none';
        return;
    }

    const disk = selectedOption.getAttribute('data-disk');
    const memory = selectedOption.getAttribute('data-memory');
    const cpu = selectedOption.getAttribute('data-cpu');
    const bandwidth = selectedOption.getAttribute('data-bandwidth');

    document.getElementById('detail-disk').textContent = formatBytes(disk);
    document.getElementById('detail-memory').textContent = formatBytes(memory);
    document.getElementById('detail-cpu').textContent = cpu ? cpu + '%' : '-';
    document.getElementById('detail-bandwidth').textContent = formatBytes(bandwidth);

    detailsDiv.style.display = 'block';
}

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
