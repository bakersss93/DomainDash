<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DomainPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainPricingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = DomainPricing::query();

        if ($request->filled('tld')) {
            $q->where('tld', $request->tld);
        }

        if ($request->has('is_common')) {
            $q->where('is_common', filter_var($request->is_common, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(
            $q->orderBy('tld')->paginate((int) $request->get('per_page', 100))
        );
    }

    public function showByTld(string $tld): JsonResponse
    {
        $pricing = DomainPricing::where('tld', $tld)->first();

        if (!$pricing) {
            return response()->json(['message' => "No pricing found for TLD: {$tld}"], 404);
        }

        return response()->json($pricing);
    }
}
