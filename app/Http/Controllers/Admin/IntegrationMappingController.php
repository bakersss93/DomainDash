<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueClient;
use App\Services\Sync\HaloDomainPusher;
use App\Support\AssetFieldSuggester;
use App\Support\SyncErrorSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only JSON endpoints behind Settings → HaloPSA / IT Glue: list the
 * remote asset types, load one type's fields with suggested mappings, and a
 * dry-run preview. Read-only toward the vendors (GET only); results are cached
 * 10 minutes and "refresh=1" bypasses the cache. Only ids/names/kinds reach
 * the browser, never raw vendor payloads or credentials.
 */
class IntegrationMappingController extends Controller
{
    // ------------------------------------------------------------------
    // HaloPSA
    // ------------------------------------------------------------------

    public function haloAssetTypes(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            if ($problem = HaloPsaClient::configurationProblem()) {
                return $this->fail($problem);
            }

            $types = app(HaloPsaClient::class)->listAssetTypes($request->boolean('refresh'));
            $mapping = HaloAssetMapping::fromSettings();

            // Pre-select: the stored id, else the only type literally named "Domain".
            $suggested = $mapping->typeId;
            if (! $suggested) {
                $named = array_values(array_filter($types, fn ($t) => strcasecmp($t['name'], 'Domain') === 0));
                $suggested = count($named) === 1 ? $named[0]['id'] : null;
            }

            return response()->json([
                'types' => $types,
                'stored_id' => $mapping->typeId,
                'suggested_id' => $suggested,
            ]);
        });
    }

    public function haloAssetTypeFields(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            if ($problem = HaloPsaClient::configurationProblem()) {
                return $this->fail($problem);
            }

            $type = app(HaloPsaClient::class)->getAssetTypeDetails($id, $request->boolean('refresh'));
            $fields = $type['fields'];

            $suggestions = AssetFieldSuggester::halo($fields);

            // Resolve each key field of the type to one of its fields and, if
            // that field is suggested for a data point, to that data point.
            $keyfields = [];
            foreach ($type['keyfields'] as $key) {
                $match = null;
                foreach ($fields as $f) {
                    if (($key['id'] > 0 && ($f['id'] === $key['id'] || $f['typeinfo_id'] === $key['id']))
                        || ($key['name'] !== '' && strcasecmp($f['name'], $key['name']) === 0)) {
                        $match = $f;
                        break;
                    }
                }
                $point = '';
                if ($match) {
                    $point = (string) (array_search($match['id'], $suggestions, true) ?: '');
                }
                $keyfields[] = $key + ['field_id' => $match['id'] ?? null, 'suggested_point' => $point];
            }

            return response()->json([
                'id' => $type['id'] ?: $id,
                'name' => $type['name'],
                'fields' => $fields,
                'keyfields' => $keyfields,
                'suggestions' => $suggestions,
                'points' => HaloAssetMapping::POINTS,
            ]);
        });
    }

    /**
     * Dry run: what would be pushed for one domain with the mapping currently
     * in the form (or the saved one). No Halo call, nothing written.
     */
    public function haloPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => 'required_without:domain|nullable|integer|exists:domains,id',
            'domain' => 'required_without:domain_id|nullable|string|max:255|exists:domains,name',
            'halo' => 'nullable|array',
            'halo.asset_type_id' => 'nullable|integer|min:1',
            'halo.asset_type_name' => 'nullable|string|max:180',
            'halo.asset_field_ids' => 'nullable|array',
            'halo.asset_field_ids.*' => 'nullable|integer|min:0',
            'halo.asset_field_names' => 'nullable|array',
            'halo.asset_field_names.*' => 'nullable|string|max:180',
            'halo.asset_key_fields' => 'nullable|array',
            'halo.asset_key_fields.*' => 'nullable|in:domain,expiry,nameservers,whois',
        ]);

        $mapping = isset($data['halo']) && $data['halo'] !== []
            ? HaloAssetMapping::fromArray($data['halo'])
            : HaloAssetMapping::fromSettings();

        $domain = Domain::with('client')
            ->when(! empty($data['domain_id']), fn ($q) => $q->whereKey($data['domain_id']), fn ($q) => $q->where('name', strtolower(trim($data['domain']))))
            ->firstOrFail();
        $preview = app(HaloDomainPusher::class)->preview($domain, $mapping);
        $preview['domain'] = $domain->name;
        $preview['client'] = $domain->client?->business_name;

        return response()->json($preview);
    }

    // ------------------------------------------------------------------
    // IT Glue
    // ------------------------------------------------------------------

    public function itglueTypes(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            if (! ItGlueClient::isConfigured()) {
                return $this->fail('IT Glue is not configured. Save the API key first.');
            }

            return response()->json([
                'types' => app(ItGlueClient::class)->listFlexibleAssetTypes($request->boolean('refresh')),
                'stored_id' => ((array) \App\Models\Setting::get('itglue', []))['flexible_asset_type_id'] ?? null,
            ]);
        });
    }

    public function itglueTypeFields(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            if (! ItGlueClient::isConfigured()) {
                return $this->fail('IT Glue is not configured. Save the API key first.');
            }

            $fields = app(ItGlueClient::class)->flexibleAssetTypeFields($id, $request->boolean('refresh'));

            return response()->json([
                'fields' => $fields,
                'suggestions' => AssetFieldSuggester::itglue($fields),
                'points' => [
                    'domain' => 'Domain name',
                    'name_servers' => 'Name servers',
                    'expiry' => 'Expiry date',
                    'whois' => 'WHOIS / registrant summary',
                    'dns' => 'DNS records',
                ],
                'defaults' => ItGlueClient::DEFAULT_TRAITS,
            ]);
        });
    }

    // ------------------------------------------------------------------

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $this->fail(SyncErrorSanitizer::describe($e));
        }
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['error' => SyncErrorSanitizer::clean($message, 500)], 422);
    }
}
