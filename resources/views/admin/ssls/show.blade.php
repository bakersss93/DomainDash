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
                <strong>Product</strong><span>{{ $ssl->display_product_name }}</span>
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
                    <strong>Product Name</strong><span>{{ $statusPayload['productName'] ?? $ssl->display_product_name }}</span>
                    <strong>Product Years</strong><span>{{ $statusPayload['productYears'] ?? '—' }}</span>
                    <strong>Start Date</strong><span>{{ $statusPayload['startDate'] ?? '—' }}</span>
                    <strong>Expire Date</strong><span>{{ $statusPayload['expireDate'] ?? '—' }}</span>
                </div>
            @else
                <p>Live status unavailable (missing cert ID).</p>
            @endif
        </div>
    </div>

    <div style="padding:16px;border:1px solid #334155;border-radius:10px;">
        <h2 style="font-size:18px;margin-bottom:12px;">SSL Management Options</h2>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <button type="button" class="btn-accent" id="open-bundle-modal">Get Certificate / Bundle</button>
            <form method="POST" action="{{ route('admin.services.ssl.renew', $ssl) }}" onsubmit="return confirm('Renew this SSL now?');">
                @csrf
                <button type="submit" class="btn-accent">Renew Certificate</button>
            </form>
            <button type="button" class="btn-accent" id="open-rekey-modal">Rekey / Reissue</button>
        </div>
    </div>
</div>

<div id="bundle-modal" class="dd-modal" style="display:none;">
    <div class="dd-modal-backdrop"></div>
    <div class="dd-modal-dialog" style="width:900px;max-width:97%;">
        <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;">Certificate Bundle</h2>
        <div style="display:flex;gap:10px;margin-bottom:10px;">
            <a href="{{ route('admin.services.ssl.bundleZip', $ssl) }}" class="btn-accent" style="text-decoration:none;">Download ZIP file</a>
            <button type="button" id="close-bundle-modal" class="btn-accent">Close</button>
        </div>
        <div id="bundle-status" style="margin-bottom:10px;"></div>
        <label style="display:block;margin-bottom:6px;">Certificate (CER)</label>
        <textarea id="bundle-cer" rows="4" class="dd-input" style="width:100%;font-family:monospace;margin-bottom:8px;" readonly></textarea>
        <label style="display:block;margin-bottom:6px;">Certificate (P7B)</label>
        <textarea id="bundle-p7b" rows="4" class="dd-input" style="width:100%;font-family:monospace;margin-bottom:8px;" readonly></textarea>
        <label style="display:block;margin-bottom:6px;">CA Bundle</label>
        <textarea id="bundle-ca" rows="4" class="dd-input" style="width:100%;font-family:monospace;" readonly></textarea>
    </div>
</div>

<div id="rekey-modal" class="dd-modal" style="display:none;">
    <div class="dd-modal-backdrop"></div>
    <div class="dd-modal-dialog" style="width:760px;max-width:97%;">
        <h2 style="font-size:18px;font-weight:600;margin-bottom:12px;">Rekey / Reissue</h2>
        <label for="rekey-csr" style="display:block;margin-bottom:6px;">CSR</label>
        <textarea id="rekey-csr" rows="7" class="dd-input" style="width:100%;font-family:monospace;" placeholder="-----BEGIN CERTIFICATE REQUEST-----"></textarea>

        <div style="display:flex;gap:10px;margin-top:10px;">
            <button type="button" class="btn-accent" id="decode-csr-btn">Decode CSR</button>
            <button type="button" class="btn-accent" id="confirm-rekey-btn" disabled>Confirm and submit reissue</button>
            <button type="button" class="btn-accent" id="close-rekey-modal">Close</button>
        </div>

        <div id="rekey-status" style="margin-top:10px;"></div>

        <details id="rekey-csr-details" style="margin-top:12px;display:none;">
            <summary style="cursor:pointer;font-weight:600;">CSR Details</summary>
            <div id="rekey-csr-grid" style="display:grid;grid-template-columns:170px 1fr;gap:6px;margin-top:8px;"></div>
        </details>

        <form method="POST" id="rekey-form" action="{{ route('admin.services.ssl.rekey', $ssl) }}" style="display:none;">
            @csrf
            <input type="hidden" name="csr" id="rekey-csr-hidden">
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const bundleModal = document.getElementById('bundle-modal');
    const openBundleBtn = document.getElementById('open-bundle-modal');
    const closeBundleBtn = document.getElementById('close-bundle-modal');
    const bundleCer = document.getElementById('bundle-cer');
    const bundleP7b = document.getElementById('bundle-p7b');
    const bundleCa = document.getElementById('bundle-ca');
    const bundleStatus = document.getElementById('bundle-status');

    function closeBundleModal() {
        bundleModal.style.display = 'none';
        bundleStatus.textContent = '';
    }

    openBundleBtn.addEventListener('click', async function () {
        bundleModal.style.display = 'flex';
        bundleStatus.textContent = 'Loading certificate bundle...';

        try {
            const response = await fetch('{{ route('admin.services.ssl.certificate', $ssl) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                bundleStatus.textContent = payload.message || 'Unable to load certificate bundle.';
                return;
            }

            bundleCer.value = payload.bundle.cer || '';
            bundleP7b.value = payload.bundle.p7b || '';
            bundleCa.value = payload.bundle.caBundle || '';
            bundleStatus.textContent = 'Certificate bundle fetched from Synergy.';
        } catch (error) {
            bundleStatus.textContent = 'Bundle fetch failed: ' + error.message;
        }
    });

    closeBundleBtn.addEventListener('click', closeBundleModal);
    bundleModal.querySelector('.dd-modal-backdrop').addEventListener('click', closeBundleModal);

    const rekeyModal = document.getElementById('rekey-modal');
    const openRekeyBtn = document.getElementById('open-rekey-modal');
    const closeRekeyBtn = document.getElementById('close-rekey-modal');
    const decodeBtn = document.getElementById('decode-csr-btn');
    const confirmBtn = document.getElementById('confirm-rekey-btn');
    const rekeyCsr = document.getElementById('rekey-csr');
    const rekeyCsrHidden = document.getElementById('rekey-csr-hidden');
    const rekeyStatus = document.getElementById('rekey-status');
    const csrDetails = document.getElementById('rekey-csr-details');
    const csrGrid = document.getElementById('rekey-csr-grid');
    const rekeyForm = document.getElementById('rekey-form');

    function closeRekeyModal() {
        rekeyModal.style.display = 'none';
        rekeyCsr.value = '';
        rekeyCsrHidden.value = '';
        rekeyStatus.textContent = '';
        csrDetails.style.display = 'none';
        csrGrid.innerHTML = '';
        confirmBtn.disabled = true;
    }

    openRekeyBtn.addEventListener('click', function () {
        rekeyModal.style.display = 'flex';
    });

    decodeBtn.addEventListener('click', async function () {
        const csrValue = rekeyCsr.value.trim();
        if (!csrValue) {
            rekeyStatus.textContent = 'Please paste CSR data first.';
            return;
        }

        decodeBtn.disabled = true;
        confirmBtn.disabled = true;
        csrGrid.innerHTML = '';
        csrDetails.style.display = 'none';
        rekeyStatus.textContent = 'Decoding CSR...';

        try {
            const response = await fetch('{{ route('admin.services.ssl.decodeCsr') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ csr: csrValue })
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                rekeyStatus.textContent = payload.message || 'Unable to decode CSR.';
                return;
            }

            const decoded = payload.decoded || {};
            const fields = [
                ['Country', decoded.country],
                ['Common Name', decoded.commonName],
                ['Location', decoded.city],
                ['State', decoded.state],
                ['Organization', decoded.organisation],
                ['Organization Unit', decoded.organisationUnit],
                ['Email', decoded.emailAddress],
                ['Key Length', decoded.privateKeyLength]
            ];

            fields.forEach(function (field) {
                const label = document.createElement('strong');
                label.textContent = field[0];
                const value = document.createElement('span');
                value.textContent = field[1] || 'N/A';
                csrGrid.appendChild(label);
                csrGrid.appendChild(value);
            });

            csrDetails.style.display = 'block';
            rekeyCsrHidden.value = csrValue;
            rekeyStatus.textContent = 'CSR decoded. Confirm to submit reissue.';
            confirmBtn.disabled = false;
        } catch (error) {
            rekeyStatus.textContent = 'Decode failed: ' + error.message;
        } finally {
            decodeBtn.disabled = false;
        }
    });

    confirmBtn.addEventListener('click', function () {
        if (!rekeyCsrHidden.value) {
            rekeyStatus.textContent = 'Decode CSR before submitting.';
            return;
        }

        if (!confirm('Submit this CSR for reissue to Synergy?')) {
            return;
        }

        rekeyForm.submit();
    });

    closeRekeyBtn.addEventListener('click', closeRekeyModal);
    rekeyModal.querySelector('.dd-modal-backdrop').addEventListener('click', closeRekeyModal);
});
</script>

<style>
.dd-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 80;
}
.dd-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.75);
}
.dd-modal-dialog {
    position: relative;
    background: var(--surface-elevated);
    border-radius: 12px;
    padding: 16px 18px 18px;
    border: 1px solid var(--border-subtle);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
}
</style>
@endsection
