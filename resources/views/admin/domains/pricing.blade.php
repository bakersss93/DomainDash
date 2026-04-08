@extends('layouts.app')

@section('content')
<div class="dd-page">
    <h1 class="dd-page-title">Domain Pricing</h1>

    @if(session('status'))
        <div class="dd-alert dd-alert-success" style="margin-bottom: 1rem;">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="dd-alert dd-alert-error" style="margin-bottom: 1rem;">
            <ul style="margin:0; padding-left:1.2rem;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="dd-card" style="margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0.8rem;">Import CSV</h2>
        <form method="POST" action="{{ route('admin.domains.pricing.import') }}" enctype="multipart/form-data" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
            @csrf
            <input type="file" name="pricing_csv" accept=".csv,text/csv" required>
            <button type="submit" class="btn-accent">Import Pricing</button>
        </form>
        <p style="margin-top:0.75rem; color:#6b7280;">Imports from Synergy CSV and upserts by TLD. Sale prices are used until sale end date.</p>
    </div>

    <div class="dd-card" style="margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0.8rem;">Bulk Sell Price Markup</h2>
        <form method="POST" action="{{ route('admin.domains.pricing.bulk-markup') }}" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
            @csrf
            <input type="number" step="0.01" min="0" name="markup_percent" placeholder="Markup %" required style="max-width:170px;">
            <button type="submit" class="btn-accent">Apply Markup</button>
        </form>
    </div>

    <div class="dd-card">
        <h2 style="margin-bottom: 1rem;">Current TLD Pricing</h2>
        <div style="overflow:auto;">
            <table class="dd-table" style="width:100%; min-width:980px;">
                <thead>
                <tr>
                    <th>TLD</th>
                    <th>Buy Price (1y)</th>
                    <th>Sale 1y</th>
                    <th>Sale End Date</th>
                    <th>Effective Buy</th>
                    <th>Sell Price</th>
                                    </tr>
                </thead>
                <tbody>
                @forelse($pricings as $pricing)
                    @php
                        $saleActive = $pricing->sale_end_date && ! $pricing->sale_end_date->isPast();
                    @endphp
                    <tr>
                        <td>.{{ $pricing->tld }}</td>
                        <td>${{ number_format((float) ($pricing->registration_price ?? 0), 2) }}</td>
                        <td>{{ $pricing->sale_registration_1_year_price !== null ? '$' . number_format((float) $pricing->sale_registration_1_year_price, 2) : 'N/A' }}</td>
                        <td>{{ $pricing->sale_end_date?->format('Y-m-d') ?? 'N/A' }}</td>
                        <td>
                            ${{ number_format((float) ($pricing->effective_registration_price ?? 0), 2) }}
                            @if($saleActive)
                                <span style="font-size:12px; color:#059669;">(sale active)</span>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.domains.pricing.sell-price', $pricing) }}" style="display:flex; align-items:center; gap:0.5rem;">
                                @csrf
                                @method('PUT')
                                <input type="number" name="sell_price" step="0.01" min="0" value="{{ $pricing->sell_price }}" style="max-width:130px;" required>
                                <button type="submit" class="dd-account-password-btn">Save</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center; color:#6b7280;">No pricing loaded yet. Import a CSV to begin.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
