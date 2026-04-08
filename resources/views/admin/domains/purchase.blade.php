@extends('layouts.app')

@section('content')
<div class="dd-page dd-domain-purchase-page">
    <h1 class="dd-page-title">Purchase New Domain</h1>

    <div id="app-purchase-domain">
        <!-- Step 1: Search for domain -->
        <div id="step-search" class="dd-card" style="margin-bottom: 24px;">
            <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 16px;">Search for a domain</h2>

            <div class="dd-search-row" style="display: flex; gap: 12px; margin-bottom: 16px;">
                <input type="text" id="domain-name" class="dd-search-input" placeholder="Enter a Domain Name"
                       style="flex: 1; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;">

                <div class="fancy-select-wrapper dd-search-extension-wrap" style="min-width: 200px;">
                    <select id="extension" class="fancy-select dd-search-extension">
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
        <div id="step-au-validation" class="dd-card" style="margin-bottom: 24px; display: none;">
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
        <div id="step-client" class="dd-card" style="margin-bottom: 24px; display: none;">
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
                        <select id="existing-client-id" class="fancy-select">
                            <option value="">Select a client</option>
                            @foreach(\App\Models\Client::orderBy('business_name')->get() as $client)
                                @php
                                    $contactName = $client->primary_contact_name ?? '';
                                    [$contactFirst, $contactLast] = array_pad(explode(' ', trim($contactName), 2), 2, '');
                                @endphp
                                <option value="{{ $client->id }}"
                                        data-business-name="{{ $client->business_name }}"
                                        data-first-name="{{ $contactFirst }}"
                                        data-last-name="{{ $contactLast }}"
                                        data-email="{{ $client->email }}"
                                        data-phone="{{ $client->phone }}"
                                        data-address="{{ $client->address }}"
                                        data-city="{{ $client->city }}"
                                        data-state="{{ $client->state }}"
                                        data-postcode="{{ $client->postcode }}"
                                        data-country="{{ $client->country ?? 'AU' }}">
                                    {{ $client->business_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <p style="margin-top: 8px; font-size: 12px; color: #6b7280;">
                        Contact details will be taken from the client's stored information.
                    </p>
                </div>

                <div id="existing-client-summary" class="dd-existing-client-summary" style="display:none;">
                    <div class="dd-existing-client-summary-header">
                        <h3>Domain Registration Contact</h3>
                        <button type="button" class="dd-account-password-btn" onclick="openExistingClientModal()">Edit details</button>
                    </div>
                    <dl class="dd-existing-client-grid">
                        <div><dt>Business</dt><dd id="summary-business-name"></dd></div>
                        <div><dt>Contact</dt><dd id="summary-contact-name"></dd></div>
                        <div><dt>Email</dt><dd id="summary-email"></dd></div>
                        <div><dt>Phone</dt><dd id="summary-phone"></dd></div>
                        <div><dt>Address</dt><dd id="summary-address"></dd></div>
                        <div><dt>Location</dt><dd id="summary-location"></dd></div>
                    </dl>
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
                <button onclick="completePurchase()" class="btn-accent" style="padding: 12px 32px; font-size: 16px;">
                    Complete Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<div id="existing-client-modal" class="dd-account-modal-backdrop" hidden>
    <div class="dd-account-modal" role="dialog" aria-modal="true" aria-labelledby="existingClientModalTitle">
        <div class="dd-account-modal-header">
            <h2 id="existingClientModalTitle">Edit Registration Details</h2>
            <button type="button" class="dd-account-modal-close" onclick="closeExistingClientModal()" aria-label="Close existing client editor">×</button>
        </div>
        <p class="dd-account-modal-intro">These details apply to this domain registration only and do not overwrite the base client record.</p>
        <div class="dd-account-modal-grid">
            <div class="dd-account-modal-form">
                <div class="dd-existing-client-form-grid">
                    <div><label>Business Name</label><input type="text" id="edit-business-name"></div>
                    <div><label>Email</label><input type="email" id="edit-email"></div>
                    <div><label>First Name</label><input type="text" id="edit-first-name"></div>
                    <div><label>Last Name</label><input type="text" id="edit-last-name"></div>
                    <div><label>Phone</label><input type="text" id="edit-phone"></div>
                    <div><label>Country</label><input type="text" id="edit-country"></div>
                    <div style="grid-column:1 / -1;"><label>Address</label><input type="text" id="edit-address"></div>
                    <div><label>City</label><input type="text" id="edit-city"></div>
                    <div><label>State</label><input type="text" id="edit-state"></div>
                    <div><label>Postcode</label><input type="text" id="edit-postcode"></div>
                </div>
                <div class="dd-account-actions" style="justify-content:flex-start;margin-top:1rem;">
                    <button type="button" class="btn-accent" onclick="saveExistingClientModal()">Save details</button>
                    <button type="button" class="dd-account-secondary" onclick="closeExistingClientModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let searchedDomain = '';
let isAuDomain = false;
let auRegistrantData = null;
let existingClientOverrides = null;

function searchDomain() {
    const domainName = document.getElementById('domain-name').value.trim();
    const extension = document.getElementById('extension').value;

    if (!domainName || !extension) {
        alert('Please enter a domain name and select an extension.');
        return;
    }

    const resultDiv = document.getElementById('search-result');
    auRegistrantData = null;
    document.getElementById('au-registrant-info').style.display = 'none';
    document.getElementById('step-au-validation').style.display = 'none';
    document.getElementById('step-client').style.display = 'none';
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
                <div class="dd-purchase-notice dd-purchase-notice-success">
                    <p>${data.message}</p>
                </div>
            `;

            if (isAuDomain) {
                document.getElementById('step-au-validation').style.display = 'block';
                document.getElementById('step-client').style.display = 'none';
                toggleAuFields();
            } else {
                document.getElementById('step-au-validation').style.display = 'none';
                document.getElementById('step-client').style.display = 'block';
            }
        } else {
            resultDiv.innerHTML = `
                <div class="dd-purchase-notice dd-purchase-notice-error">
                    <p>${data.message || 'Domain is not available.'}</p>
                </div>
            `;
            document.getElementById('step-au-validation').style.display = 'none';
            document.getElementById('step-client').style.display = 'none';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = `
            <div class="dd-purchase-notice dd-purchase-notice-error">
                <p>Error checking domain availability.</p>
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
    const auBusinessFields = document.getElementById('au-business-fields');
    const stepClient = document.getElementById('step-client');
    const registrantInfo = document.getElementById('au-registrant-info');
    const shouldUseBusinessPath = method === 'Business';

    auBusinessFields.style.display = shouldUseBusinessPath ? 'block' : 'none';
    registrantInfo.style.display = shouldUseBusinessPath && auRegistrantData ? 'block' : 'none';
    stepClient.style.display = shouldUseBusinessPath ? (auRegistrantData ? 'block' : 'none') : 'block';
}

function toggleClientFields() {
    const clientType = document.getElementById('client-type').value;
    document.getElementById('existing-client-fields').style.display = clientType === 'existing' ? 'block' : 'none';
    document.getElementById('new-client-fields').style.display = clientType === 'new' ? 'block' : 'none';
    document.getElementById('existing-client-summary').style.display = clientType === 'existing' ? 'block' : 'none';
    if (clientType === 'existing') {
        populateExistingClientSummary();
    }
}

function getSelectedClientData() {
    const select = document.getElementById('existing-client-id');
    const option = select?.options?.[select.selectedIndex];
    if (!option || !option.value) {
        return null;
    }
    return {
        business_name: option.dataset.businessName || '',
        first_name: option.dataset.firstName || '',
        last_name: option.dataset.lastName || '',
        email: option.dataset.email || '',
        phone: option.dataset.phone || '',
        address: option.dataset.address || '',
        city: option.dataset.city || '',
        state: option.dataset.state || '',
        postcode: option.dataset.postcode || '',
        country: option.dataset.country || 'AU',
    };
}

function getExistingClientRegistrationData() {
    return existingClientOverrides || getSelectedClientData();
}

function populateExistingClientSummary() {
    const summary = document.getElementById('existing-client-summary');
    const data = getExistingClientRegistrationData();
    if (!summary) return;
    if (!data) {
        summary.style.display = 'none';
        return;
    }

    summary.style.display = 'block';
    document.getElementById('summary-business-name').textContent = data.business_name || '—';
    document.getElementById('summary-contact-name').textContent = [data.first_name, data.last_name].filter(Boolean).join(' ') || '—';
    document.getElementById('summary-email').textContent = data.email || '—';
    document.getElementById('summary-phone').textContent = data.phone || '—';
    document.getElementById('summary-address').textContent = data.address || '—';
    document.getElementById('summary-location').textContent = [data.city, data.state, data.postcode, data.country].filter(Boolean).join(', ') || '—';
}

function openExistingClientModal() {
    const data = getExistingClientRegistrationData();
    if (!data) {
        alert('Please select a client first.');
        return;
    }

    document.getElementById('edit-business-name').value = data.business_name || '';
    document.getElementById('edit-first-name').value = data.first_name || '';
    document.getElementById('edit-last-name').value = data.last_name || '';
    document.getElementById('edit-email').value = data.email || '';
    document.getElementById('edit-phone').value = data.phone || '';
    document.getElementById('edit-address').value = data.address || '';
    document.getElementById('edit-city').value = data.city || '';
    document.getElementById('edit-state').value = data.state || '';
    document.getElementById('edit-postcode').value = data.postcode || '';
    document.getElementById('edit-country').value = data.country || 'AU';

    document.getElementById('existing-client-modal').removeAttribute('hidden');
    document.body.classList.add('dd-account-modal-open');
}

function closeExistingClientModal() {
    document.getElementById('existing-client-modal').setAttribute('hidden', 'hidden');
    document.body.classList.remove('dd-account-modal-open');
}

function saveExistingClientModal() {
    existingClientOverrides = {
        business_name: document.getElementById('edit-business-name').value.trim(),
        first_name: document.getElementById('edit-first-name').value.trim(),
        last_name: document.getElementById('edit-last-name').value.trim(),
        email: document.getElementById('edit-email').value.trim(),
        phone: document.getElementById('edit-phone').value.trim(),
        address: document.getElementById('edit-address').value.trim(),
        city: document.getElementById('edit-city').value.trim(),
        state: document.getElementById('edit-state').value.trim(),
        postcode: document.getElementById('edit-postcode').value.trim(),
        country: document.getElementById('edit-country').value.trim() || 'AU',
    };
    populateExistingClientSummary();
    closeExistingClientModal();
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

        if (!formData.client_id) {
            alert('Please select a client.');
            return;
        }

        const existingData = getExistingClientRegistrationData();
        if (existingData) {
            formData.existing_client_overrides = existingData;
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

document.addEventListener('DOMContentLoaded', function () {
    const existingClientSelect = document.getElementById('existing-client-id');
    if (existingClientSelect) {
        existingClientSelect.addEventListener('change', function () {
            existingClientOverrides = null;
            populateExistingClientSummary();
        });
    }

    const existingClientModal = document.getElementById('existing-client-modal');
    if (existingClientModal) {
        existingClientModal.addEventListener('click', function (event) {
            if (event.target === existingClientModal) {
                closeExistingClientModal();
            }
        });
    }
});
</script>
<style>
    .dd-domain-purchase-page .dd-page-title {
        font-size: 2.2rem;
        line-height: 1.15;
        font-weight: 700;
        margin-bottom: 1.5rem;
        color: var(--dd-text);
    }

    .dd-domain-purchase-page .dd-card {
        border: 1px solid var(--dd-border);
        background: linear-gradient(150deg, var(--dd-surface), var(--dd-surface-soft));
    }

    .dd-domain-purchase-page h2 {
        font-size: 1.75rem !important;
        line-height: 1.2;
        font-weight: 650;
        margin-bottom: 1.1rem !important;
        color: var(--dd-text) !important;
    }

    .dd-domain-purchase-page #step-search h2,
    .dd-domain-purchase-page #step-au-validation h2,
    .dd-domain-purchase-page #step-client h2 {
        font-size: 2rem !important;
        margin-bottom: 1.25rem !important;
    }

    .dd-domain-purchase-page .dd-search-row {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) minmax(190px, 220px) auto;
        gap: 12px;
        align-items: stretch;
    }

    .dd-domain-purchase-page .dd-search-extension-wrap {
        display: block;
        width: 100%;
    }

    .dd-domain-purchase-page input[type="text"],
    .dd-domain-purchase-page input[type="email"],
    .dd-domain-purchase-page .fancy-select {
        min-height: 48px;
        background: var(--dd-surface-soft) !important;
        border: 1px solid var(--dd-border) !important;
        color: var(--dd-text) !important;
        border-radius: 12px !important;
    }

    .dd-domain-purchase-page #domain-name {
        color: var(--dd-text) !important;
        font-weight: 500;
    }

    .dd-domain-purchase-page .dd-search-input,
    .dd-domain-purchase-page .dd-search-extension {
        width: 100%;
        min-height: 56px;
        border-radius: 14px !important;
        border: 1px solid #b6c3d6 !important;
        background: #ffffff !important;
        box-shadow: 0 1px 0 rgba(255, 255, 255, 0.95) inset, 0 0 0 1px rgba(182, 195, 214, 0.35);
        padding: 0 14px !important;
    }

    .dd-domain-purchase-page .dd-search-input:focus,
    .dd-domain-purchase-page .dd-search-extension:focus {
        outline: none;
        border-color: color-mix(in srgb, var(--dd-accent) 60%, #8ba6c7 40%) !important;
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--dd-accent) 20%, transparent);
    }

    .dd-domain-purchase-page input::placeholder {
        color: color-mix(in srgb, var(--dd-text-soft) 92%, transparent) !important;
        opacity: 1;
    }

    html.dark .dd-domain-purchase-page .dd-search-input,
    html.dark .dd-domain-purchase-page .dd-search-extension {
        border-color: color-mix(in srgb, #324664 62%, var(--dd-border) 38%) !important;
        background: #1c2c47 !important;
        color: #e2e8f0 !important;
    }

    .dd-domain-purchase-page .dd-search-extension option {
        background: #ffffff;
        color: #111827;
    }

    .dd-domain-purchase-page #step-au-validation input[type="text"],
    .dd-domain-purchase-page #step-au-validation input[type="email"],
    .dd-domain-purchase-page #step-au-validation .fancy-select,
    .dd-domain-purchase-page #step-client input[type="text"],
    .dd-domain-purchase-page #step-client input[type="email"],
    .dd-domain-purchase-page #step-client .fancy-select {
        border: 1px solid #b6c3d6 !important;
        background: #ffffff !important;
        color: #111827 !important;
        box-shadow: 0 1px 0 rgba(255, 255, 255, 0.95) inset, 0 0 0 1px rgba(182, 195, 214, 0.35);
    }

    .dd-domain-purchase-page #step-au-validation .fancy-select option,
    .dd-domain-purchase-page #step-client .fancy-select option {
        background: #ffffff;
        color: #111827;
    }

    .dd-domain-purchase-page #step-au-validation input[type="text"]:focus,
    .dd-domain-purchase-page #step-au-validation input[type="email"]:focus,
    .dd-domain-purchase-page #step-au-validation .fancy-select:focus,
    .dd-domain-purchase-page #step-client input[type="text"]:focus,
    .dd-domain-purchase-page #step-client input[type="email"]:focus,
    .dd-domain-purchase-page #step-client .fancy-select:focus {
        outline: none;
        border-color: color-mix(in srgb, var(--dd-accent) 60%, #8ba6c7 40%) !important;
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--dd-accent) 20%, transparent);
    }

    html.dark .dd-domain-purchase-page .dd-search-extension option {
        background: #0f172a;
        color: #e2e8f0;
    }

    html.dark .dd-domain-purchase-page #step-au-validation input[type="text"],
    html.dark .dd-domain-purchase-page #step-au-validation input[type="email"],
    html.dark .dd-domain-purchase-page #step-au-validation .fancy-select,
    html.dark .dd-domain-purchase-page #step-client input[type="text"],
    html.dark .dd-domain-purchase-page #step-client input[type="email"],
    html.dark .dd-domain-purchase-page #step-client .fancy-select {
        border-color: color-mix(in srgb, #324664 62%, var(--dd-border) 38%) !important;
        background: #1c2c47 !important;
        color: #e2e8f0 !important;
    }

    html.dark .dd-domain-purchase-page #step-au-validation .fancy-select option,
    html.dark .dd-domain-purchase-page #step-client .fancy-select option {
        background: #0f172a;
        color: #e2e8f0;
    }

    .dd-domain-purchase-page input:-webkit-autofill,
    .dd-domain-purchase-page input:-webkit-autofill:hover,
    .dd-domain-purchase-page input:-webkit-autofill:focus {
        -webkit-text-fill-color: var(--dd-text) !important;
        caret-color: var(--dd-text);
        -webkit-box-shadow: 0 0 0 1000px var(--dd-surface-soft) inset !important;
        box-shadow: 0 0 0 1000px var(--dd-surface-soft) inset !important;
        transition: background-color 9999s ease-in-out 0s;
    }

    .dd-domain-purchase-page .fancy-select-wrapper::after {
        color: var(--dd-text-soft) !important;
    }

    .dd-domain-purchase-page p,
    .dd-domain-purchase-page li,
    .dd-domain-purchase-page label {
        color: var(--dd-text-soft) !important;
    }

    .dd-domain-purchase-page .btn-accent {
        min-height: 48px;
        border-radius: 14px;
    }

    .dd-domain-purchase-page .dd-purchase-notice {
        padding: 16px;
        border-radius: 10px;
        border-width: 1px;
        border-style: solid;
    }

    .dd-domain-purchase-page .dd-purchase-notice p {
        margin: 0;
        font-weight: 600;
    }

    .dd-domain-purchase-page .dd-purchase-notice-success {
        background: #ecfdf5;
        border-color: #10b981;
    }

    .dd-domain-purchase-page .dd-purchase-notice-success p {
        color: #065f46 !important;
    }

    .dd-domain-purchase-page .dd-purchase-notice-error {
        background: #fef2f2;
        border-color: #ef4444;
    }

    .dd-domain-purchase-page .dd-purchase-notice-error p {
        color: #991b1b !important;
    }

    .dd-existing-client-summary {
        margin-top: 14px;
        padding: 14px;
        border: 1px solid var(--dd-border);
        border-radius: 12px;
        background: color-mix(in srgb, var(--dd-surface-soft) 86%, transparent);
    }

    .dd-existing-client-summary-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }

    .dd-existing-client-summary-header h3 {
        margin: 0;
        color: var(--dd-text);
        font-size: 1rem;
    }

    .dd-existing-client-grid {
        margin: 0;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 16px;
    }

    .dd-existing-client-grid dt {
        font-size: 12px;
        color: var(--dd-text-soft);
    }

    .dd-existing-client-grid dd {
        margin: 2px 0 0;
        color: var(--dd-text);
        font-weight: 600;
    }

    .dd-existing-client-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .dd-existing-client-form-grid label {
        display: block;
        margin-bottom: 6px;
        color: var(--dd-text-soft);
        font-size: 13px;
    }

    .dd-existing-client-form-grid input {
        width: 100%;
    }

    html.dark .dd-domain-purchase-page .dd-purchase-notice-success {
        background: #0f2f26;
    }

    html.dark .dd-domain-purchase-page .dd-purchase-notice-success p {
        color: #6ee7b7 !important;
    }

    html.dark .dd-domain-purchase-page .dd-purchase-notice-error {
        background: #3a161c;
    }

    html.dark .dd-domain-purchase-page .dd-purchase-notice-error p {
        color: #fecaca !important;
    }

    @media (max-width: 900px) {
        .dd-domain-purchase-page .dd-search-row {
            grid-template-columns: minmax(0, 1fr);
        }

        .dd-existing-client-grid,
        .dd-existing-client-form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endsection
