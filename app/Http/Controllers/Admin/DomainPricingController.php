<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DomainPricingController extends Controller
{
    public function index()
    {
        $pricings = DomainPricing::query()->orderBy('tld')->get();

        return view('admin.domains.pricing', compact('pricings'));
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'pricing_csv' => 'required|file|mimes:csv,txt',
        ]);

        $handle = fopen($request->file('pricing_csv')->getRealPath(), 'r');

        if ($handle === false) {
            return back()->withErrors(['pricing_csv' => 'Unable to read the CSV file.']);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return back()->withErrors(['pricing_csv' => 'The CSV file is empty.']);
        }

        $normalizedHeader = array_map(fn ($column) => trim((string) $column), $header);

        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $data = array_combine($normalizedHeader, array_pad($row, count($normalizedHeader), null));
            if ($data === false) {
                continue;
            }

            $tld = strtolower(trim((string) ($data['TLD'] ?? '')));
            if ($tld === '') {
                continue;
            }

            DomainPricing::query()->updateOrCreate(
                ['tld' => ltrim($tld, '.')],
                [
                    'registration_price' => $this->parseMoney($data['Registration price'] ?? null),
                    'renewal_price' => $this->parseMoney($data['Renewal price'] ?? null),
                    'restore_price' => $this->parseMoney($data['Restore price'] ?? null),
                    'transfer_price' => $this->parseMoney($data['Transfer price'] ?? null),
                    'minimum_years' => $this->parseInt($data['Minimum years'] ?? null),
                    'maximum_years' => $this->parseInt($data['Maximum years'] ?? null),
                    'id_protection' => $this->parseBool($data['ID protection'] ?? null),
                    'dnssec' => $this->parseBool($data['DNSSEC'] ?? null),
                    'sale_registration_1_year_price' => $this->parseMoney($data['Sale registration 1 year price'] ?? null),
                    'sale_registration_2_year_price' => $this->parseMoney($data['Sale registration 2 year price'] ?? null),
                    'sale_registration_3_year_price' => $this->parseMoney($data['Sale registration 3 year price'] ?? null),
                    'sale_registration_4_year_price' => $this->parseMoney($data['Sale registration 4 year price'] ?? null),
                    'sale_registration_5_year_price' => $this->parseMoney($data['Sale registration 5 year price'] ?? null),
                    'sale_registration_6_year_price' => $this->parseMoney($data['Sale registration 6 year price'] ?? null),
                    'sale_registration_7_year_price' => $this->parseMoney($data['Sale registration 7 year price'] ?? null),
                    'sale_registration_8_year_price' => $this->parseMoney($data['Sale registration 8 year price'] ?? null),
                    'sale_registration_9_year_price' => $this->parseMoney($data['Sale registration 9 year price'] ?? null),
                    'sale_registration_10_year_price' => $this->parseMoney($data['Sale registration 10 year price'] ?? null),
                    'sale_renew_price' => $this->parseMoney($data['Sale renew price'] ?? null),
                    'sale_transfer_price' => $this->parseMoney($data['Sale transfer price'] ?? null),
                    'sale_end_date' => $this->parseDate($data['Sale end date'] ?? null),
                ]
            );

            $imported++;
        }

        fclose($handle);

        return back()->with('status', "Imported {$imported} pricing row(s).");
    }

    public function updateSellPrice(Request $request, DomainPricing $domainPricing): RedirectResponse
    {
        $validated = $request->validate([
            'sell_price' => 'required|numeric|min:0',
        ]);

        $domainPricing->update([
            'sell_price' => round((float) $validated['sell_price'], 2),
        ]);

        return back()->with('status', "Updated sell price for .{$domainPricing->tld}.");
    }

    public function updateCommonDomain(Request $request, DomainPricing $domainPricing): RedirectResponse
    {
        $validated = $request->validate([
            'is_common' => 'nullable|boolean',
        ]);

        $domainPricing->update([
            'is_common' => (bool) ($validated['is_common'] ?? false),
        ]);

        return back()->with('status', "Updated common domain flag for .{$domainPricing->tld}.");
    }

    public function bulkMarkup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'markup_percent' => 'required|numeric|min:0',
        ]);

        $multiplier = 1 + (((float) $validated['markup_percent']) / 100);

        DomainPricing::query()->get()->each(function (DomainPricing $pricing) use ($multiplier): void {
            $base = $pricing->effective_registration_price;
            if ($base === null) {
                return;
            }

            $pricing->update([
                'sell_price' => round($base * $multiplier, 2),
            ]);
        });

        return back()->with('status', 'Bulk sell pricing updated from current buy prices.');
    }

    private function parseMoney(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '' || strtoupper($value) === 'N/A') {
            return null;
        }

        return round((float) str_replace(['$', ','], '', $value), 2);
    }

    private function parseInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || strtoupper($value) === 'N/A' ? null : (int) $value;
    }

    private function parseBool(mixed $value): bool
    {
        return strtolower(trim((string) $value)) === 'yes';
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || strtoupper($value) === 'N/A') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
