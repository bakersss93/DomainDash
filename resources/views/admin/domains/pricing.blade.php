@extends('layouts.app')

@section('content')
<div class="dd-page dd-domain-pricing-page">
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
        @can('domain-pricing.manage')
        <form method="POST" action="{{ route('admin.domains.pricing.import') }}" enctype="multipart/form-data" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
            @csrf
            <input type="file" name="pricing_csv" accept=".csv,text/csv" required>
            <button type="submit" class="btn-accent">Import Pricing</button>
        </form>
        @else
        <p style="margin:0; color:#6b7280;">You have view access only. Import is disabled.</p>
        @endcan
        <p style="margin-top:0.75rem; color:#6b7280;">Imports from Synergy CSV and upserts by TLD. Sale prices are used until sale end date.</p>
    </div>

    <div class="dd-card" style="margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0.8rem;">Bulk Sell Price Markup</h2>
        @can('domain-pricing.manage')
        <form method="POST" action="{{ route('admin.domains.pricing.bulk-markup') }}" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
            @csrf
            <input type="number" step="0.01" min="0" name="markup_percent" placeholder="Markup %" required style="max-width:170px;">
            <button type="submit" class="btn-accent">Apply Markup</button>
        </form>
        @else
        <p style="margin:0; color:#6b7280;">You have view access only. Bulk markup is disabled.</p>
        @endcan
    </div>

    <div class="dd-card">
        <div class="dd-pricing-toolbar">
            <div class="dd-pricing-search-wrap">
                <label for="pricing-search" class="dd-pricing-label">Search TLD</label>
                <input id="pricing-search" type="text" class="dd-pricing-search" placeholder="Type to filter (e.g. com.au)">
            </div>
            <div class="dd-pricing-filters">
                <label class="dd-pricing-filter"><input type="checkbox" id="filter-on-sale"> On sale</label>
                <label class="dd-pricing-filter"><input type="checkbox" id="filter-sale-ended"> Show EoL Domains</label>
            </div>
        </div>

        <h2 style="margin-bottom: 1rem;">Current TLD Pricing</h2>
        <div style="overflow:auto;">
            <table class="dd-table" id="pricing-table" style="width:100%; min-width:980px;">
                <thead>
                <tr>
                    <th><button type="button" class="dd-sort-btn" data-sort-key="tld">TLD <span class="dd-sort-indicator"></span></button></th>
                    <th><button type="button" class="dd-sort-btn" data-sort-key="buy">Buy Price (1y) <span class="dd-sort-indicator"></span></button></th>
                    <th><button type="button" class="dd-sort-btn" data-sort-key="sale">Sale 1y <span class="dd-sort-indicator"></span></button></th>
                    <th><button type="button" class="dd-sort-btn" data-sort-key="saleEnd">Sale End Date <span class="dd-sort-indicator"></span></button></th>
                    <th><button type="button" class="dd-sort-btn" data-sort-key="effective">Effective Buy <span class="dd-sort-indicator"></span></button></th>
                    <th><button type="button" class="dd-sort-btn" data-sort-key="sell">Sell Price <span class="dd-sort-indicator"></span></button></th>
                </tr>
                </thead>
                <tbody id="pricing-table-body">
                @forelse($pricings as $pricing)
                    @php
                        $saleActive = $pricing->sale_end_date && ! $pricing->sale_end_date->isPast();
                        $saleEnded = $pricing->sale_end_date && $pricing->sale_end_date->isPast();
                        $saleOneYear = $pricing->sale_registration_1_year_price;
                        $buyPrice = $pricing->registration_price;
                        $effectivePrice = $pricing->effective_registration_price;
                        $saleEndDate = $pricing->sale_end_date?->format('Y-m-d');
                    @endphp
                    <tr
                        data-tld="{{ strtolower($pricing->tld) }}"
                        data-buy="{{ $buyPrice !== null ? number_format((float) $buyPrice, 2, '.', '') : '' }}"
                        data-sale="{{ $saleOneYear !== null ? number_format((float) $saleOneYear, 2, '.', '') : '' }}"
                        data-sale-end="{{ $saleEndDate ?? '' }}"
                        data-effective="{{ $effectivePrice !== null ? number_format((float) $effectivePrice, 2, '.', '') : '' }}"
                        data-sell="{{ $pricing->sell_price !== null ? number_format((float) $pricing->sell_price, 2, '.', '') : '' }}"
                        data-on-sale="{{ $saleActive ? '1' : '0' }}"
                        data-sale-ended="{{ $saleEnded ? '1' : '0' }}"
                    >
                        <td>.{{ $pricing->tld }}</td>
                        <td>{{ $buyPrice !== null ? '$' . number_format((float) $buyPrice, 2) : 'N/A' }}</td>
                        <td>{{ $saleOneYear !== null ? '$' . number_format((float) $saleOneYear, 2) : 'N/A' }}</td>
                        <td>{{ $saleEndDate ?? 'N/A' }}</td>
                        <td>
                            {{ $effectivePrice !== null ? '$' . number_format((float) $effectivePrice, 2) : 'N/A' }}
                            @if($saleActive)
                                <span style="font-size:12px; color:#059669;">(sale active)</span>
                            @elseif($saleEnded)
                                <span style="font-size:12px; color:#b45309;">(ended)</span>
                            @endif
                        </td>
                        <td>
                            @can('domain-pricing.manage')
                            <form method="POST" action="{{ route('admin.domains.pricing.sell-price', $pricing) }}" style="display:flex; align-items:center; gap:0.5rem;">
                                @csrf
                                @method('PUT')
                                <input type="number" name="sell_price" step="0.01" min="0" value="{{ $pricing->sell_price }}" style="max-width:130px;" required>
                                <button type="submit" class="dd-account-password-btn">Save</button>
                            </form>
                            @else
                            <span>{{ $pricing->sell_price !== null ? '$' . number_format((float) $pricing->sell_price, 2) : 'N/A' }}</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr id="pricing-empty-row">
                        <td colspan="6" style="text-align:center; color:#6b7280;">No pricing loaded yet. Import a CSV to begin.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p id="pricing-filter-empty" style="display:none; margin-top:0.8rem; color:#6b7280;">No rows match your current search/filter selection.</p>
    </div>
</div>

<script>
(function () {
    const searchInput = document.getElementById('pricing-search');
    const onSaleCheckbox = document.getElementById('filter-on-sale');
    const saleEndedCheckbox = document.getElementById('filter-sale-ended');
    const tableBody = document.getElementById('pricing-table-body');
    const sortButtons = Array.from(document.querySelectorAll('.dd-sort-btn'));
    const filterEmpty = document.getElementById('pricing-filter-empty');
    const staticEmptyRow = document.getElementById('pricing-empty-row');

    let sortState = { key: 'tld', direction: 'asc' };

    function parseSortValue(row, key) {
        if (key === 'saleEnd') {
            const value = row.dataset.saleEnd || '';
            return value === '' ? Number.POSITIVE_INFINITY : Date.parse(value);
        }

        if (['buy', 'sale', 'effective', 'sell'].includes(key)) {
            const value = row.dataset[key] || '';
            return value === '' ? Number.POSITIVE_INFINITY : Number.parseFloat(value);
        }

        return (row.dataset[key] || '').toLowerCase();
    }

    function applySort() {
        const rows = Array.from(tableBody.querySelectorAll('tr[data-tld]'));
        rows.sort((a, b) => {
            const aValue = parseSortValue(a, sortState.key);
            const bValue = parseSortValue(b, sortState.key);

            if (aValue < bValue) return sortState.direction === 'asc' ? -1 : 1;
            if (aValue > bValue) return sortState.direction === 'asc' ? 1 : -1;
            return 0;
        });

        rows.forEach((row) => tableBody.appendChild(row));
    }

    function updateSortIndicators() {
        sortButtons.forEach((button) => {
            const indicator = button.querySelector('.dd-sort-indicator');
            const isActive = button.dataset.sortKey === sortState.key;
            if (!indicator) return;
            indicator.textContent = isActive ? (sortState.direction === 'asc' ? '↑' : '↓') : '';
        });
    }

    function applyFilters() {
        const rows = Array.from(tableBody.querySelectorAll('tr[data-tld]'));
        const search = (searchInput?.value || '').trim().toLowerCase();
        const filterOnSale = !!onSaleCheckbox?.checked;
        const filterSaleEnded = !!saleEndedCheckbox?.checked;

        let visibleCount = 0;

        rows.forEach((row) => {
            const tld = row.dataset.tld || '';
            const onSale = row.dataset.onSale === '1';
            const saleEnded = row.dataset.saleEnded === '1';

            const saleWindowPass = filterSaleEnded ? true : !saleEnded;
            const saleTypePass = filterOnSale ? onSale : true;
            const searchPass = search === '' || tld.includes(search);
            const show = saleWindowPass && saleTypePass && searchPass;

            row.style.display = show ? '' : 'none';
            if (show) {
                visibleCount++;
            }
        });

        if (filterEmpty) {
            filterEmpty.style.display = visibleCount === 0 && rows.length > 0 ? 'block' : 'none';
        }

        if (staticEmptyRow) {
            staticEmptyRow.style.display = rows.length === 0 ? '' : 'none';
        }
    }

    sortButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextKey = button.dataset.sortKey;
            if (!nextKey) {
                return;
            }

            if (sortState.key === nextKey) {
                sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.key = nextKey;
                sortState.direction = 'asc';
            }

            applySort();
            updateSortIndicators();
            applyFilters();
        });
    });

    searchInput?.addEventListener('input', applyFilters);
    onSaleCheckbox?.addEventListener('change', applyFilters);
    saleEndedCheckbox?.addEventListener('change', applyFilters);

    applySort();
    updateSortIndicators();
    applyFilters();
})();
</script>

<style>
.dd-domain-pricing-page .dd-pricing-toolbar {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
    justify-content: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.dd-domain-pricing-page .dd-pricing-label {
    display: block;
    font-size: 0.85rem;
    color: #6b7280;
    margin-bottom: 0.35rem;
}

.dd-domain-pricing-page .dd-pricing-search {
    min-width: 260px;
    border: 1px solid var(--dd-border);
    border-radius: 10px;
    padding: 0.65rem 0.75rem;
}

.dd-domain-pricing-page .dd-pricing-filters {
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
}

.dd-domain-pricing-page .dd-pricing-filter {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.9rem;
}

.dd-domain-pricing-page .dd-pricing-filter input[type="checkbox"] {
    appearance: none;
    width: 16px;
    height: 16px;
    border: 1px solid var(--dd-border);
    border-radius: 6px;
    background: var(--dd-surface, #ffffff);
    position: relative;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}

.dd-domain-pricing-page .dd-pricing-filter input[type="checkbox"]:checked {
    background: #16a34a;
    border-color: #16a34a;
}

.dd-domain-pricing-page .dd-pricing-filter input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    left: 4px;
    top: 1px;
    width: 4px;
    height: 8px;
    border: solid #ffffff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.dd-domain-pricing-page .dd-sort-btn {
    border: 0;
    background: transparent;
    font: inherit;
    font-weight: 700;
    color: inherit;
    padding: 0;
    cursor: pointer;
}

.dd-domain-pricing-page .dd-sort-indicator {
    display: inline-block;
    min-width: 0.75rem;
}

@media (max-width: 768px) {
    .dd-domain-pricing-page .dd-pricing-search {
        min-width: 100%;
        width: 100%;
    }

    .dd-domain-pricing-page .dd-pricing-search-wrap {
        width: 100%;
    }
}
</style>
@endsection
