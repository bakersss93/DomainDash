@extends('layouts.app')

@section('content')
<div style="max-width: 1200px;">
    <h1 style="font-size: 24px; font-weight: 600; margin-bottom: 24px;">Transfer Domain</h1>

    <div id="app-transfer-domain">
        <!-- Step 1: Enter Domain and EPP Code -->
        <div id="step-domain-info" style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Domain Transfer Details</h2>

            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Domain Name *</label>
                <input type="text" id="domain-name" placeholder="e.g., example.com"
                       style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Domain EPP Code / Authorization Code *</label>
                <input type="text" id="epp-code" placeholder="Enter EPP/Auth code"
                       style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                <p style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                    The EPP code can be obtained from your current domain registrar.
                </p>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" id="auto-renew" style="width: 18px; height: 18px; cursor: pointer;">
                    <span style="font-weight: 500;">Enable Auto-Renewal</span>
                </label>
                <p style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                    Automatically renew this domain before it expires.
                </p>
            </div>

            <button onclick="validateTransfer()" class="btn-accent" style="padding: 12px 32px;">
                Validate Transfer
            </button>

            <div id="validation-result" style="display: none; margin-top: 16px;"></div>
        </div>

        <!-- Step 2: Client Information -->
        <div id="step-client" style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; display: none;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Client Information</h2>

            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Link to Client</label>
                <div class="fancy-select-wrapper" style="width: 100%;">
                    <select id="client-type" class="fancy-select" onchange="toggleClientFields()">
                        <option value="existing">Link to Existing Client</option>
                        <option value="new">Create New Client</option>
                    </select>
                </div>
            </div>

            <!-- Existing Client Selection -->
            <div id="existing-client-fields">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Select Client</label>
                    <div class="fancy-select-wrapper" style="width: 100%;">
                        <select id="existing-client-id" class="fancy-select">
                            <option value="">Select a client</option>
                            @foreach(\App\Models\Client::orderBy('business_name')->get() as $client)
                                <option value="{{ $client->id }}">{{ $client->business_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                        Contact details will be taken from the client's stored information.
                    </p>
                </div>
            </div>

            <!-- New Client Fields -->
            <div id="new-client-fields" style="display: none;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">First Name *</label>
                        <input type="text" id="first-name" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Last Name *</label>
                        <input type="text" id="last-name" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Business Name *</label>
                    <input type="text" id="business-name" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Email *</label>
                    <input type="email" id="email" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Phone *</label>
                    <input type="text" id="phone" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Address *</label>
                    <input type="text" id="address" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">City *</label>
                        <input type="text" id="city" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">State *</label>
                        <input type="text" id="state" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-weight: 500;">Postcode *</label>
                        <input type="text" id="postcode" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Country *</label>
                    <div class="fancy-select-wrapper" style="width: 100%;">
                        <select id="country" class="fancy-select">
                            <option value="AU">Australia</option>
                            <option value="US">United States</option>
                            <option value="GB">United Kingdom</option>
                            <option value="NZ">New Zealand</option>
                        </select>
                    </div>
                </div>
            </div>

            <div style="margin-top: 24px;">
                <button onclick="completeTransfer()" class="btn-accent" style="padding: 12px 32px; font-size: 16px;">
                    Initiate Transfer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let validatedDomain = '';
let validatedEppCode = '';

function validateTransfer() {
    const domain = document.getElementById('domain-name').value.trim();
    const eppCode = document.getElementById('epp-code').value.trim();

    if (!domain || !eppCode) {
        alert('Please enter both domain name and EPP code.');
        return;
    }

    const resultDiv = document.getElementById('validation-result');
    resultDiv.innerHTML = '<p>Validating transfer...</p>';
    resultDiv.style.display = 'block';

    fetch('{{ route('admin.domains.transfer.validate') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ domain: domain, epp_code: eppCode })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            validatedDomain = domain;
            validatedEppCode = eppCode;

            resultDiv.innerHTML = `
                <div style="padding: 16px; background: #d1fae5; border: 1px solid #10b981; border-radius: 6px;">
                    <p style="color: #065f46; font-weight: 600;">${data.message}</p>
                </div>
            `;

            document.getElementById('step-client').style.display = 'block';
        } else {
            resultDiv.innerHTML = `
                <div style="padding: 16px; background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px;">
                    <p style="color: #991b1b; font-weight: 600;">${data.message}</p>
                </div>
            `;
            document.getElementById('step-client').style.display = 'none';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = `
            <div style="padding: 16px; background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px;">
                <p style="color: #991b1b;">Error validating transfer.</p>
            </div>
        `;
    });
}

function toggleClientFields() {
    const clientType = document.getElementById('client-type').value;
    document.getElementById('existing-client-fields').style.display = clientType === 'existing' ? 'block' : 'none';
    document.getElementById('new-client-fields').style.display = clientType === 'new' ? 'block' : 'none';
}

function completeTransfer() {
    const clientType = document.getElementById('client-type').value;
    const autoRenew = document.getElementById('auto-renew').checked;

    let formData = {
        domain: validatedDomain,
        epp_code: validatedEppCode,
        auto_renew: autoRenew,
        client_type: clientType
    };

    if (clientType === 'existing') {
        formData.client_id = document.getElementById('existing-client-id').value;

        if (!formData.client_id) {
            alert('Please select a client.');
            return;
        }
    } else {
        formData.business_name = document.getElementById('business-name').value;
        formData.first_name = document.getElementById('first-name').value;
        formData.last_name = document.getElementById('last-name').value;
        formData.email = document.getElementById('email').value;
        formData.phone = document.getElementById('phone').value;
        formData.address = document.getElementById('address').value;
        formData.city = document.getElementById('city').value;
        formData.state = document.getElementById('state').value;
        formData.postcode = document.getElementById('postcode').value;
        formData.country = document.getElementById('country').value;

        if (!formData.business_name || !formData.first_name || !formData.last_name ||
            !formData.email || !formData.phone || !formData.address ||
            !formData.city || !formData.state || !formData.postcode) {
            alert('Please fill in all required fields.');
            return;
        }
    }

    fetch('{{ route('admin.domains.transfer.complete') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = '{{ route('admin.domains') }}';
        } else {
            alert(data.message || 'Error initiating transfer.');
        }
    })
    .catch(err => {
        alert('Error initiating transfer.');
    });
}
</script>
@endsection
