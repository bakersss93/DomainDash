@extends('layouts.app')

@section('content')
<div style="max-width: 1200px;">
    <h1 style="font-size: 24px; font-weight: 600; margin-bottom: 24px;">Purchase New Domain</h1>

    <div id="app-purchase-domain">
        <!-- Step 1: Search for domain -->
        <div id="step-search" style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Search for a domain</h2>

            <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                <input type="text" id="domain-name" placeholder="Enter a Domain Name"
                       style="flex: 1; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">

                <div class="fancy-select-wrapper" style="min-width: 200px;">
                    <select id="extension" class="fancy-select">
                        <option value="">Select an Extension</option>
                        <option value="com">com</option>
                        <option value="net">net</option>
                        <option value="org">org</option>
                        <option value="com.au">com.au</option>
                        <option value="net.au">net.au</option>
                        <option value="org.au">org.au</option>
                        <option value="au">au</option>
                        <option value="io">io</option>
                        <option value="co">co</option>
                        <option value="biz">biz</option>
                        <option value="info">info</option>
                    </select>
                </div>

                <button onclick="searchDomain()" class="btn-accent" style="padding: 12px 32px; white-space: nowrap;">
                    Check
                </button>
            </div>

            <div id="search-result" style="display: none;"></div>
        </div>

        <!-- Step 2: .au Registrant Validation (only for .au domains) -->
        <div id="step-au-validation" style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; display: none;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">.au Automated Registrant Retrieval</h2>

            <p style="margin-bottom: 16px; color: #6b7280; font-size: 14px;">
                To streamline the registration process, please inform us if you wish to register this domain as an individual or as a business.
            </p>

            <ul style="margin-bottom: 24px; color: #6b7280; font-size: 14px; margin-left: 20px;">
                <li>If you wish to register as a business you will need to supply us with an ABN/ACN or RBN, and we'll obtain the required details automatically for you.</li>
                <li>If you wish to register as an individual you will be required to provide us with Evidence of Identity (EOI) documents. e.g. Australian Driver's License, Passport and or Medicare Card.</li>
            </ul>

            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Preferred Eligibility Method</label>
                <div class="fancy-select-wrapper" style="width: 100%;">
                    <select id="au-method" class="fancy-select" onchange="toggleAuFields()">
                        <option value="Business">Business</option>
                        <option value="Individual">Individual</option>
                    </select>
                </div>
            </div>

            <div id="au-business-fields">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Registration Number</label>
                    <div style="display: flex; gap: 12px;">
                        <div class="fancy-select-wrapper" style="flex: 1;">
                            <select id="au-id-type" class="fancy-select">
                                <option value="ABN">ABN</option>
                                <option value="ACN">ACN</option>
                                <option value="RBN">RBN</option>
                            </select>
                        </div>
                        <input type="text" id="au-id-number" placeholder="Enter registration number"
                               style="flex: 2; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                        <button onclick="validateAu()" class="btn-accent" style="padding: 12px 32px;">
                            Lookup Registrant
                        </button>
                    </div>
                </div>

                <div id="au-registrant-info" style="display: none; padding: 16px; background: #d1fae5; border: 1px solid #10b981; border-radius: 6px; margin-bottom: 16px;">
                    <strong>Registrant Name:</strong> <span id="au-registrant-name"></span>
                </div>
            </div>
        </div>

        <!-- Step 3: Client Information -->
        <div id="step-client" style="background: var(--bg); border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px; display: none;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">New Contact Information</h2>

            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500;">Search By</label>
                <div class="fancy-select-wrapper" style="width: 100%;">
                    <select id="client-type" class="fancy-select" onchange="toggleClientFields()">
                        <option value="existing">Existing Client</option>
                        <option value="new">New Client</option>
                    </select>
                </div>
            </div>

            <!-- Existing Client Selection -->
            <div id="existing-client-fields">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Select Client</label>
                    <div class="fancy-select-wrapper" style="width: 100%;">
                        <select id="existing-client-id" class="fancy-select" onchange="loadClientDomains()">
                            <option value="">Select a client</option>
                            @foreach(\App\Models\Client::orderBy('company_name')->get() as $client)
                                <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div id="client-domains-wrapper" style="display: none; margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Copy Details From Domain</label>
                    <div class="fancy-select-wrapper" style="width: 100%;">
                        <select id="existing-domain-id" class="fancy-select">
                            <option value="">Select a domain</option>
                        </select>
                    </div>
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
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Company Name *</label>
                    <input type="text" id="company-name" style="width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
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
                <button onclick="completePurchase()" class="btn-accent" style="padding: 12px 32px; font-size: 16px;">
                    Complete Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let searchedDomain = '';
let isAuDomain = false;
let auRegistrantData = null;

function searchDomain() {
    const domainName = document.getElementById('domain-name').value.trim();
    const extension = document.getElementById('extension').value;

    if (!domainName || !extension) {
        alert('Please enter a domain name and select an extension.');
        return;
    }

    const resultDiv = document.getElementById('search-result');
    resultDiv.innerHTML = '<p>Checking availability...</p>';
    resultDiv.style.display = 'block';

    fetch('{{ route('admin.domains.purchase.search') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ domain_name: domainName, extension: extension })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.available) {
            searchedDomain = data.domain;
            isAuDomain = data.requiresAuValidation;

            resultDiv.innerHTML = `
                <div style="padding: 16px; background: #d1fae5; border: 1px solid #10b981; border-radius: 6px;">
                    <p style="color: #065f46; font-weight: 600;">${data.message}</p>
                </div>
            `;

            if (isAuDomain) {
                document.getElementById('step-au-validation').style.display = 'block';
            } else {
                document.getElementById('step-client').style.display = 'block';
            }
        } else {
            resultDiv.innerHTML = `
                <div style="padding: 16px; background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px;">
                    <p style="color: #991b1b; font-weight: 600;">${data.message || 'Domain is not available.'}</p>
                </div>
            `;
            document.getElementById('step-au-validation').style.display = 'none';
            document.getElementById('step-client').style.display = 'none';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = `
            <div style="padding: 16px; background: #fee2e2; border: 1px solid #dc2626; border-radius: 6px;">
                <p style="color: #991b1b;">Error checking domain availability.</p>
            </div>
        `;
    });
}

function validateAu() {
    const idType = document.getElementById('au-id-type').value;
    const idNumber = document.getElementById('au-id-number').value.trim();

    if (!idNumber) {
        alert('Please enter a registration number.');
        return;
    }

    fetch('{{ route('admin.domains.purchase.validateAu') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ id_type: idType, id_number: idNumber })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            auRegistrantData = data.registrant;
            document.getElementById('au-registrant-name').textContent = data.registrant.name;
            document.getElementById('au-registrant-info').style.display = 'block';
            document.getElementById('step-client').style.display = 'block';
        } else {
            alert(data.message || 'Invalid registration number.');
        }
    })
    .catch(err => {
        alert('Error validating registration number.');
    });
}

function toggleAuFields() {
    const method = document.getElementById('au-method').value;
    document.getElementById('au-business-fields').style.display = method === 'Business' ? 'block' : 'none';
}

function toggleClientFields() {
    const clientType = document.getElementById('client-type').value;
    document.getElementById('existing-client-fields').style.display = clientType === 'existing' ? 'block' : 'none';
    document.getElementById('new-client-fields').style.display = clientType === 'new' ? 'block' : 'none';
}

function loadClientDomains() {
    const clientId = document.getElementById('existing-client-id').value;
    if (!clientId) {
        document.getElementById('client-domains-wrapper').style.display = 'none';
        return;
    }

    fetch(`/admin/clients/${clientId}`)
        .then(res => res.json())
        .then(data => {
            const select = document.getElementById('existing-domain-id');
            select.innerHTML = '<option value="">Select a domain</option>';

            if (data.domains && data.domains.length > 0) {
                data.domains.forEach(domain => {
                    select.innerHTML += `<option value="${domain.id}">${domain.name}</option>`;
                });
                document.getElementById('client-domains-wrapper').style.display = 'block';
            }
        });
}

function completePurchase() {
    const clientType = document.getElementById('client-type').value;

    let formData = {
        domain: searchedDomain,
        years: 1,
        client_type: clientType
    };

    if (clientType === 'existing') {
        formData.client_id = document.getElementById('existing-client-id').value;
        formData.domain_id = document.getElementById('existing-domain-id').value;

        if (!formData.client_id || !formData.domain_id) {
            alert('Please select a client and a domain to copy details from.');
            return;
        }
    } else {
        formData.company_name = document.getElementById('company-name').value;
        formData.first_name = document.getElementById('first-name').value;
        formData.last_name = document.getElementById('last-name').value;
        formData.email = document.getElementById('email').value;
        formData.phone = document.getElementById('phone').value;
        formData.address = document.getElementById('address').value;
        formData.city = document.getElementById('city').value;
        formData.state = document.getElementById('state').value;
        formData.postcode = document.getElementById('postcode').value;
        formData.country = document.getElementById('country').value;

        if (!formData.company_name || !formData.first_name || !formData.last_name ||
            !formData.email || !formData.phone || !formData.address ||
            !formData.city || !formData.state || !formData.postcode) {
            alert('Please fill in all required fields.');
            return;
        }
    }

    // Add .au specific data if available
    if (auRegistrantData) {
        formData.au_id_type = auRegistrantData.id_type;
        formData.au_id_number = auRegistrantData.id_number;
        formData.au_registrant_name = auRegistrantData.name;
        formData.au_eligibility_type = auRegistrantData.eligibility_type;
    }

    fetch('{{ route('admin.domains.purchase.complete') }}', {
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
            alert(data.message || 'Error purchasing domain.');
        }
    })
    .catch(err => {
        alert('Error purchasing domain.');
    });
}
</script>
@endsection
