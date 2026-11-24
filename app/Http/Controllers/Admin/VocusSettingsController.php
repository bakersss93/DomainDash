<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\VocusPlan;
use App\Services\AuditLogger;
use App\Services\Vocus\VocusOptions;
use App\Services\Vocus\VocusPlanSheetImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    // ----- plan mapping, named tiers ---------------------------------------------------------

    /** Account mapping that decides which plans the order forms offer (Tagged or Default Map). */
    public function updateMapping(Request $request): JsonResponse
    {
        $data = $request->validate(['plan_mapping' => ['required', Rule::in(array_keys(VocusOptions::PLAN_MAPPINGS))]]);

        $old = VocusOptions::planMapping();
        $this->saveSetting('plan_mapping', $data['plan_mapping']);
        AuditLogger::logSystem('vocus.plan_mapping.update', 'Vocus plan mapping set to ' . VocusOptions::PLAN_MAPPINGS[$data['plan_mapping']] . '.', ['service' => 'vocus', 'function' => 'settings-plan-mapping'], ['old_values' => ['plan_mapping' => $old], 'new_values' => ['plan_mapping' => $data['plan_mapping']]]);

        return response()->json(['plan_mapping' => $data['plan_mapping']]);
    }

    /** Download / upload Mbps of the named tiers (Home Fast ...). Both blank = unknown (the plan is never hidden by speed). */
    public function updateTiers(Request $request): JsonResponse
    {
        $names = array_keys(VocusOptions::namedTiers());
        $request->validate(['tiers' => 'required|array', 'tiers.*' => 'nullable|array']);

        $new = [];
        foreach ($names as $name) {
            $row = (array) ($request->input('tiers')[$name] ?? []);
            $d = $row['download'] ?? $row[0] ?? null;
            $u = $row['upload'] ?? $row[1] ?? null;
            $blank = fn ($v) => $v === null || trim((string) $v) === '';
            if ($blank($d) && $blank($u)) {
                $new[$name] = null;
                continue;
            }
            foreach ([$d, $u] as $v) {
                if ($blank($v) || ! ctype_digit(trim((string) $v)) || (int) $v < 1 || (int) $v > 10000) {
                    return response()->json(['message' => "Tier \"{$name}\": enter whole numbers from 1 to 10000 for both download and upload, or leave both blank."], 422);
                }
            }
            $new[$name] = [(int) $d, (int) $u];
        }

        $old = VocusOptions::namedTiers();
        $this->saveSetting('named_tiers', $new);
        AuditLogger::logSystem('vocus.named_tiers.update', 'Vocus named tier speeds updated.', ['service' => 'vocus', 'function' => 'settings-named-tiers'], ['old_values' => ['named_tiers' => $old], 'new_values' => ['named_tiers' => $new]]);

        return response()->json(['tiers' => $this->tiersForForm()]);
    }

    /** @return array<string,array{download:?int,upload:?int}> */
    private function tiersForForm(): array
    {
        return array_map(fn ($s) => ['download' => $s[0] ?? null, 'upload' => $s[1] ?? null], VocusOptions::namedTiers());
    }

    // ----- sheet import --------------------------------------------------------------------

    private const IMPORT_CACHE = 'vocus:plan-import:';

    /**
     * Step 1: parse an uploaded sheet (or re-use a parsed one by token) and report what would change. Nothing is
     * written. The parsed rows are kept in the cache for 30 minutes under a token for the apply step.
     */
    public function importPreview(Request $request): JsonResponse
    {
        $request->validate([
            'file'         => 'nullable|file|max:' . (int) (VocusPlanSheetImporter::MAX_BYTES / 1024) . '|extensions:xlsx,csv',
            'token'        => ['nullable', 'regex:/^[A-Za-z0-9]{32}$/'],
            'only_mapping' => 'nullable|boolean',
        ], ['file.max' => 'The sheet must be 2 MB or smaller.', 'file.extensions' => 'Upload an .xlsx or .csv file.']);

        $userId = $request->user()?->id;
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            try {
                $parsed = VocusPlanSheetImporter::parseFile($file->getRealPath(), strtolower((string) $file->getClientOriginalExtension()));
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            if ($parsed['rows'] === []) {
                return response()->json(['message' => 'No plan rows were found under the header row.'] + ($parsed['warnings'] ? ['warnings' => $parsed['warnings']] : []), 422);
            }
            $token = Str::random(32);
            Cache::put(self::IMPORT_CACHE . $token, ['user' => $userId] + $parsed, now()->addMinutes(30));
        } else {
            $token  = (string) $request->input('token', '');
            $parsed = $token !== '' ? $this->importCache($token, $userId) : null;
            if (! $parsed) {
                return response()->json(['message' => 'Choose a sheet to upload (the previous preview has expired).'], 422);
            }
        }

        $only = $request->boolean('only_mapping', VocusOptions::planMapping() === 'DEFAULT_MAP') ? VocusOptions::planMapping() : null;
        $plan = VocusPlanSheetImporter::plan($parsed['rows'], $only);

        return response()->json([
            'token'        => $token,
            'total_rows'   => count($parsed['rows']),
            'only_mapping' => $only !== null,
            'mapping'      => VocusOptions::PLAN_MAPPINGS[VocusOptions::planMapping()],
            'counts'       => ['new' => count($plan['new']), 'updated' => count($plan['updated']), 'unchanged' => count($plan['unchanged']), 'skipped' => $plan['skipped']],
            'sample'       => ['new' => array_slice($plan['new'], 0, 8), 'updated' => array_slice($plan['updated'], 0, 8)],
            'warnings'     => $parsed['warnings'],
        ]);
    }

    /** Step 2: apply the previewed rows. Audit log carries counts only. */
    public function importApply(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'regex:/^[A-Za-z0-9]{32}$/'], 'only_mapping' => 'nullable|boolean']);

        $parsed = $this->importCache((string) $request->input('token'), $request->user()?->id);
        if (! $parsed) {
            return response()->json(['message' => 'The preview has expired. Upload the sheet again.'], 422);
        }

        $only   = $request->boolean('only_mapping') ? VocusOptions::planMapping() : null;
        $counts = DB::transaction(fn () => VocusPlanSheetImporter::apply($parsed['rows'], $only));
        Cache::forget(self::IMPORT_CACHE . $request->input('token'));

        AuditLogger::logSystem('vocus.plans.import', 'Vocus plan sheet imported.', ['service' => 'vocus', 'function' => 'settings-plans-import'], ['new_values' => $counts + ['only_mapping' => $only]]);

        return response()->json(['counts' => $counts, 'plans' => VocusPlan::orderBy('name')->get()->map->toFormArray()->values()]);
    }

    private function importCache(string $token, mixed $userId): ?array
    {
        $hit = Cache::get(self::IMPORT_CACHE . $token);

        return is_array($hit) && ($hit['user'] ?? null) === $userId && isset($hit['rows']) ? $hit : null;
    }

    private function validatedPlan(Request $request, ?VocusPlan $plan = null): array
    {
        $data = $request->validate([
            'plan_id'    => ['required', 'string', 'min:2', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('vocus_plans', 'plan_id')->ignore($plan?->id)],
            'name'       => 'required|string|max:100',
            'scopes'     => 'nullable|array',
            'scopes.*'   => ['string', Rule::in(array_keys(VocusOptions::SCOPES))],
            'speed_tier' => 'nullable|string|max:40',
            'technology' => ['nullable', Rule::in(VocusOptions::PLAN_TECHNOLOGIES)],
            'mapping'    => ['nullable', Rule::in(array_keys(VocusOptions::PLAN_MAPPINGS))],
            'download_mbps' => 'nullable|integer|min:1|max:10000',
            'upload_mbps'   => 'nullable|integer|min:1|max:10000',
            'cost_price' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'sell_price' => ['required', 'numeric', 'min:0', 'max:99999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'active'     => 'nullable|boolean',
        ], [
            'download_mbps.*'  => 'Download Mbps must be a whole number from 1 to 10000.',
            'upload_mbps.*'    => 'Upload Mbps must be a whole number from 1 to 10000.',
            'plan_id.regex'    => 'The Plan ID may only contain letters, digits, dot, dash and underscore.',
            'cost_price.regex' => 'Prices are in AUD with at most 2 decimals.',
            'sell_price.regex' => 'Prices are in AUD with at most 2 decimals.',
        ]);

        $tier  = ($data['speed_tier'] ?? '') !== '' ? trim($data['speed_tier']) : null;
        $parsed = VocusOptions::speedsForTier($tier); // fills a blank speed from "100/20" text or a named tier (Home Fast)
        $down  = $data['download_mbps'] ?? $parsed[0] ?? null;
        $up    = $data['upload_mbps'] ?? $parsed[1] ?? null;

        return [
            'plan_id'    => $data['plan_id'],
            'name'       => trim($data['name']),
            'scopes'     => array_values(array_unique($data['scopes'] ?? [])) ?: null,
            'speed_tier' => $tier,
            // A request that omits technology / mapping keeps the stored value on update (null on create).
            'technology' => array_key_exists('technology', $data) || ! $plan ? ($data['technology'] ?? null) ?: null : $plan->technology,
            'mapping'    => array_key_exists('mapping', $data) || ! $plan ? ($data['mapping'] ?? null) ?: null : $plan->mapping,
            'download_mbps' => $down !== null ? (int) $down : null,
            'upload_mbps'   => $up !== null ? (int) $up : null,
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
