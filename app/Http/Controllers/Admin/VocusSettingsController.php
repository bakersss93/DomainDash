<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\VocusPlan;
use App\Services\AuditLogger;
use App\Services\Vocus\VocusOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON endpoints behind the Settings -> Vocus modals (PPPoE realms, service scopes, plans).
 * Routes live in the Administrator-only admin group, like the rest of Settings; every write is audited.
 */
class VocusSettingsController extends Controller
{
    // ----- realms --------------------------------------------------------------------------

    public function updateRealms(Request $request): JsonResponse
    {
        $request->validate(['realms' => 'present|array|max:' . VocusOptions::MAX_REALMS, 'realms.*' => 'nullable']);

        $raw = array_values(array_filter((array) $request->input('realms'), fn ($r) => ! (is_string($r) && trim($r) === '')));
        [$realms, $errors] = VocusOptions::validateRealms($raw);

        if ($errors !== []) {
            return response()->json(['message' => $errors[0], 'errors' => ['realms' => $errors]], 422);
        }

        $this->saveSetting('realms', $realms);
        AuditLogger::logSystem('vocus.realms.update', 'Vocus PPPoE realms updated.', ['service' => 'vocus', 'function' => 'settings-realms'], ['new_values' => ['realms' => $realms]]);

        return response()->json(['realms' => $realms]);
    }

    // ----- scopes --------------------------------------------------------------------------

    public function updateScopes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scopes'   => 'required|array|min:1',
            'scopes.*' => ['string', Rule::in(array_keys(VocusOptions::SCOPES))],
        ], ['scopes.required' => 'Enable at least one service scope.', 'scopes.min' => 'Enable at least one service scope.']);

        $scopes = array_values(array_filter(array_keys(VocusOptions::SCOPES), fn ($s) => in_array($s, $data['scopes'], true)));

        $this->saveSetting('scopes', $scopes);
        AuditLogger::logSystem('vocus.scopes.update', 'Vocus service scopes updated.', ['service' => 'vocus', 'function' => 'settings-scopes'], ['new_values' => ['scopes' => $scopes]]);

        return response()->json(['scopes' => $scopes]);
    }

    // ----- plans ---------------------------------------------------------------------------

    public function plans(): JsonResponse
    {
        return response()->json(['plans' => VocusPlan::orderBy('name')->get()->map->toFormArray()->values()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $plan = VocusPlan::create($this->validatedPlan($request));
        AuditLogger::logSystem('vocus.plan.create', "Vocus plan {$plan->plan_id} created.", ['service' => 'vocus', 'function' => 'settings-plans'], ['new_values' => $plan->toFormArray()]);

        return response()->json(['plan' => $plan->toFormArray()], 201);
    }

    public function updatePlan(Request $request, VocusPlan $plan): JsonResponse
    {
        $old = $plan->toFormArray();
        $plan->update($this->validatedPlan($request, $plan));
        AuditLogger::logSystem('vocus.plan.update', "Vocus plan {$plan->plan_id} updated.", ['service' => 'vocus', 'function' => 'settings-plans'], ['old_values' => $old, 'new_values' => $plan->toFormArray()]);

        return response()->json(['plan' => $plan->toFormArray()]);
    }

    public function destroyPlan(VocusPlan $plan): JsonResponse
    {
        $old = $plan->toFormArray();
        $plan->delete(); // services keep their plan_id text and price snapshot (FK is nullOnDelete)
        AuditLogger::logSystem('vocus.plan.delete', "Vocus plan {$old['plan_id']} deleted.", ['service' => 'vocus', 'function' => 'settings-plans'], ['old_values' => $old]);

        return response()->json(['deleted' => true]);
    }

    private function validatedPlan(Request $request, ?VocusPlan $plan = null): array
    {
        $data = $request->validate([
            'plan_id'    => ['required', 'string', 'min:2', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('vocus_plans', 'plan_id')->ignore($plan?->id)],
            'name'       => 'required|string|max:100',
            'scopes'     => 'nullable|array',
            'scopes.*'   => ['string', Rule::in(array_keys(VocusOptions::SCOPES))],
            'speed_tier' => 'nullable|string|max:40',
            'cost_price' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'sell_price' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'active'     => 'nullable|boolean',
        ], [
            'plan_id.regex'    => 'The Plan ID may only contain letters, digits, dot, dash and underscore.',
            'cost_price.regex' => 'Prices are in AUD with at most 2 decimals.',
            'sell_price.regex' => 'Prices are in AUD with at most 2 decimals.',
        ]);

        return [
            'plan_id'    => $data['plan_id'],
            'name'       => trim($data['name']),
            'scopes'     => array_values(array_unique($data['scopes'] ?? [])) ?: null,
            'speed_tier' => ($data['speed_tier'] ?? '') !== '' ? trim($data['speed_tier']) : null,
            'cost_price' => number_format((float) $data['cost_price'], 2, '.', ''),
            'sell_price' => number_format((float) $data['sell_price'], 2, '.', ''),
            'active'     => $request->boolean('active', true),
        ];
    }

    private function saveSetting(string $key, mixed $value): void
    {
        $current = Setting::get('vocus', []);
        $current = is_array($current) ? $current : [];
        $current[$key] = $value;
        Setting::put('vocus', $current);
    }
}
