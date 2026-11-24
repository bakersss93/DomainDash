<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Assets\AssetEntity;
use App\Services\ItGlue\ItGlueAssetMapping;
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
    /** Type-name patterns used to pre-select an asset type for an entity (only when exactly one type matches). */
    private const TYPE_NAME_HINTS = [
        'domain' => '/^domains?$/i',
        'internet_service' => '/internet|broadband|nbn|connectivity/i',
        'hosting_service' => '/hosting|web\s*site|website/i',
        'ssl_certificate' => '/ssl|certificate|\btls\b/i',
    ];

    /** ?entity=domain|internet_service|hosting_service|ssl_certificate (default domain). */
    private function entityParam(Request $request): string
    {
        $entity = (string) $request->query('entity', AssetEntity::DOMAIN);
        abort_unless(AssetEntity::valid($entity), 422, 'Unknown entity.');

        return $entity;
    }

    /** @param array<int,array{id:int|string,name:string}> $types */
    private function suggestType(string $entity, array $types, int|string|null $stored): int|string|null
    {
        if ($stored) {
            return $stored;
        }
        $named = array_values(array_filter($types, fn ($t) => preg_match(self::TYPE_NAME_HINTS[$entity], (string) $t['name'])));

        return count($named) === 1 ? $named[0]['id'] : null;
    }

    // ------------------------------------------------------------------
    // HaloPSA
    // ------------------------------------------------------------------

    public function haloAssetTypes(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            if ($problem = HaloPsaClient::configurationProblem()) {
                return $this->fail($problem);
            }

            $entity = $this->entityParam($request);
            $types = app(HaloPsaClient::class)->listAssetTypes($request->boolean('refresh'));
            $mapping = HaloAssetMapping::forEntity($entity);

            // Pre-select: the stored id, else the only type whose name fits the entity (domains: named "Domain").
            $suggested = $this->suggestType($entity, $types, $mapping->typeId);

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

            $entity = $this->entityParam($request);
            $type = app(HaloPsaClient::class)->getAssetTypeDetails($id, $request->boolean('refresh'));
            $fields = $type['fields'];

            $suggestions = AssetFieldSuggester::forEntity('halo', $entity, $fields);

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
                'entity' => $entity,
                'points' => AssetEntity::points($entity, 'halo'),
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

            $entity = $this->entityParam($request);
            $types = app(ItGlueClient::class)->listFlexibleAssetTypes($request->boolean('refresh'));
            $stored = ItGlueAssetMapping::forEntity($entity)->typeId;

            return response()->json([
                'types' => $types,
                'stored_id' => $stored,
                'suggested_id' => $this->suggestType($entity, $types, $stored),
            ]);
        });
    }

    public function itglueTypeFields(Request $request, int $id): JsonResponse
    {
        return $this->guard(function () use ($request, $id) {
            if (! ItGlueClient::isConfigured()) {
                return $this->fail('IT Glue is not configured. Save the API key first.');
            }

            $entity = $this->entityParam($request);
            $fields = app(ItGlueClient::class)->flexibleAssetTypeFields($id, $request->boolean('refresh'));

            return response()->json([
                'fields' => $fields,
                'suggestions' => AssetFieldSuggester::forEntity('itglue', $entity, $fields),
                'entity' => $entity,
                'points' => AssetEntity::points($entity, 'itglue'),
                'defaults' => $entity === AssetEntity::DOMAIN ? ItGlueClient::DEFAULT_TRAITS : [],
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
