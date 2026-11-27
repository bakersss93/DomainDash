<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HaloClient;
use App\Models\ItglueOrganization;
use App\Models\Setting;
use App\Services\Halo\HaloAssetMapping;
use App\Services\Halo\HaloPsaClient;
use App\Services\ItGlue\ItGlueClient;
use App\Support\SyncErrorSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Option lists for the searchable selects in Settings (<x-ui.mapping-table>, billing, assets).
 *
 *   GET /admin/settings/options/{source}?q=&limit=&refresh=1&include=ID&asset_type_id=&type_id=
 *   -> { options: [{id,label,meta?}], total, truncated, cached, error? }
 *
 * Halo / IT Glue lists are read-only GETs, normalised to {id,label}, cached 10 minutes per tenant
 * (refresh=1 bypasses the cache) and filtered server-side by `q`. Local-mirror lists (clients,
 * organisations) are queried with LIKE instead of loading everything. `include` keeps an already-saved
 * value selectable even when the remote list no longer returns it.
 */
class SettingsOptionsController extends Controller
{
    public const TTL = 600;

    public const SOURCES = [
        'halo.ticket-types', 'halo.statuses', 'halo.priorities', 'halo.teams', 'halo.slas', 'halo.sites',
        'halo.contract-types', 'halo.contract-subtypes', 'halo.asset-types', 'halo.asset-fields', 'halo.items', 'halo.clients',
        'itglue.organisations', 'itglue.flexible-types', 'itglue.flexible-fields',
        'dd.clients', 'dd.asset-entities', 'dd.service-categories', 'dd.status-labels', 'dd.asset-points', 'dd.itglue-points', 'dd.ticket-classes',
    ];

    public function show(Request $request, string $source): JsonResponse
    {
        abort_unless(in_array($source, self::SOURCES, true), 404);

        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min(200, (int) $request->query('limit', 100)));
        $refresh = $request->boolean('refresh');

        try {
            [$options, $cached] = $this->load($source, $request, $q, $refresh);
        } catch (\Throwable $e) {
            return response()->json(['options' => [], 'total' => 0, 'truncated' => false, 'error' => SyncErrorSanitizer::describe($e)], 422);
        }
        if ($options === null) {
            return response()->json(['options' => [], 'total' => 0, 'truncated' => false, 'error' => $cached], 422);
        }

        if ($q !== '' && ! $this->serverFiltered($source)) {
            $needle = mb_strtolower($q);
            $options = array_values(array_filter($options, fn ($o) => str_contains(mb_strtolower($o['label']), $needle)));
        }

        $include = trim((string) $request->query('include', ''));
        if ($include !== '' && $q === '' && ! in_array($include, array_map(fn ($o) => (string) $o['id'], $options), true)) {
            array_unshift($options, ['id' => $include, 'label' => 'Saved value (#' . $include . ')']);
        }

        $total = count($options);

        return response()->json([
            'options' => array_slice($options, 0, $limit),
            'total' => $total,
            'truncated' => $total > $limit,
            'cached' => $cached === true,
        ]);
    }

    private function serverFiltered(string $source): bool
    {
        return in_array($source, ['halo.clients', 'itglue.organisations', 'dd.clients', 'halo.items'], true);
    }

    /**
     * @return array{0:?array<int,array>,1:bool|string}  [options, cachedFlag] or [null, errorMessage]
     */
    private function load(string $source, Request $request, string $q, bool $refresh): array
    {
        // --- no remote call needed ---
        switch ($source) {
            case 'dd.service-categories':
                return [$this->serviceCategories(), false];
            case 'dd.ticket-classes':
                return [array_map(fn ($c) => ['id' => $c, 'label' => $c], MappingController::TICKET_CLASSES), false];
            case 'dd.status-labels':
                return [array_map(fn ($c) => ['id' => $c, 'label' => $c], ['Open', 'In Progress', 'Awaiting Customer', 'On Hold', 'Resolved', 'Closed']), false];
            case 'dd.asset-entities':
                return [$this->points(array_combine(\App\Services\Assets\AssetEntity::KEYS, array_map(fn ($e) => \App\Services\Assets\AssetEntity::label($e), \App\Services\Assets\AssetEntity::KEYS))), false];
            case 'dd.asset-points':
                return [$this->points($this->entityPoints($request, 'halo')), false];
            case 'dd.itglue-points':
                return [$this->points($this->entityPoints($request, 'itglue')), false];
            case 'dd.clients':
                return [$this->ddClients($q), false];
            case 'halo.clients':
                return [$this->haloMirrorClients($q), false];
            case 'itglue.organisations':
                return [$this->itglueMirrorOrgs($q), false];
            case 'halo.items':
                return $this->haloItems($q, $refresh);
        }

        // --- cached remote lists ---
        if (str_starts_with($source, 'halo.')) {
            if ($problem = HaloPsaClient::configurationProblem()) {
                return [null, $problem];
            }
        } elseif (! ItGlueClient::isConfigured()) {
            return [null, 'IT Glue is not configured. Save the API key first.'];
        }

        $param = match ($source) {
            'halo.asset-fields' => (int) $request->query('asset_type_id', 0),
            'itglue.flexible-fields' => (int) $request->query('type_id', 0),
            'halo.contract-types' => \App\Services\Halo\HaloBillingSettings::fromSettings()->typeLookupId,
            'halo.contract-subtypes' => \App\Services\Halo\HaloBillingSettings::fromSettings()->subtypeLookupId,
            default => 0,
        };
        if (in_array($source, ['halo.asset-fields', 'itglue.flexible-fields'], true) && $param <= 0) {
            return [[], false];
        }

        $key = 'dd_opts:' . $source . ':' . $this->tenant($source) . ':' . $param;
        if ($refresh) {
            Cache::forget($key);
        }
        $hit = Cache::has($key);
        $options = Cache::remember($key, self::TTL, fn () => $this->fetchRemote($source, $param, $refresh));

        return [$options, $hit];
    }

    private function tenant(string $source): string
    {
        $cfg = (array) Setting::get(str_starts_with($source, 'halo.') ? 'halo' : 'itglue', []);

        return sha1((string) ($cfg['base_url'] ?? '') . '|' . (string) ($cfg['tenant'] ?? ''));
    }

    private function fetchRemote(string $source, int $param, bool $refresh): array
    {
        if (str_starts_with($source, 'itglue.')) {
            $itg = app(ItGlueClient::class);
            if ($source === 'itglue.flexible-types') {
                return $this->normalise($itg->listFlexibleAssetTypes($refresh), 'name');
            }
            $out = [];
            foreach ($itg->flexibleAssetTypeFields($param, $refresh) as $f) {
                if (empty($f['fillable'])) {
                    continue; // only Text, Textbox and Date traits can receive a plain value
                }
                $out[] = ['id' => (string) $f['name_key'], 'label' => $f['name'] . ' (' . $f['name_key'] . ')', 'meta' => ['kind' => $f['kind'], 'fillable' => $f['fillable']]];
            }

            return $out;
        }

        $halo = app(HaloPsaClient::class);

        return match ($source) {
            'halo.ticket-types' => $this->normalise($halo->listTicketTypes($refresh), 'name'),
            'halo.statuses' => $this->normalise($halo->listTicketStatuses($refresh), 'name'),
            'halo.priorities' => $this->normalise($halo->listPriorityRows(), 'name', ['priorityid', 'id']),
            'halo.teams' => $this->normalise($halo->listTeamRows(), 'name'),
            'halo.slas' => $this->normalise($halo->listSlaRows(), 'name'),
            'halo.sites' => $this->normalise($halo->listSiteRows(), 'name', ['id'], 'client_name'),
            'halo.contract-types' => $this->lookupOptions($halo->listContractTypes($param)),
            'halo.contract-subtypes' => $this->lookupOptions($halo->listContractSubtypes($param)),
            'halo.asset-types' => $this->normalise($halo->listAssetTypes($refresh), 'name', ['id'], 'group'),
            'halo.asset-fields' => $this->haloFields($halo->getAssetTypeDetails($param, $refresh)),
        };
    }

    /**
     * Halo lookup rows (contract types, agreement subtypes) as picker options.
     *
     * @param array<int,array{id:int,name:string,prefix?:string}> $rows
     */
    private function lookupOptions(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (string) $r['id'], 'label' => $r['name']];
        }

        return $out;
    }

    /** @param array<int,array> $rows */
    private function normalise(array $rows, string $nameKey, array $idKeys = ['id'], ?string $prefixKey = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = 0;
            foreach ($idKeys as $k) {
                $id = (int) ($row[$k] ?? $row[ucfirst($k)] ?? 0);
                if ($id > 0) {
                    break;
                }
            }
            $name = trim((string) ($row[$nameKey] ?? $row[ucfirst($nameKey)] ?? ''));
            if ($id <= 0) {
                continue;
            }
            $label = $name !== '' ? $name : '#' . $id;
            $meta = $prefixKey !== null ? trim((string) ($row[$prefixKey] ?? '')) : '';
            $out[$id] = ['id' => (string) $id, 'label' => $label] + ($meta !== '' ? ['meta' => ['group' => $meta]] : []);
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    private function haloFields(array $type): array
    {
        $out = [];
        foreach ($type['fields'] as $f) {
            $out[] = ['id' => (string) $f['id'], 'label' => $f['name'], 'meta' => ['kind' => $f['kind'], 'mandatory' => $f['mandatory']]];
        }

        return $out;
    }

    /** The DomainDash values of one entity (?entity=, default domain) for the field-mapping rows. */
    private function entityPoints(Request $request, string $provider): array
    {
        $entity = (string) $request->query('entity', \App\Services\Assets\AssetEntity::DOMAIN);

        return \App\Services\Assets\AssetEntity::valid($entity)
            ? \App\Services\Assets\AssetEntity::points($entity, $provider)
            : \App\Services\Assets\AssetEntity::points(\App\Services\Assets\AssetEntity::DOMAIN, $provider);
    }

    private function points(array $points): array
    {
        $out = [];
        foreach ($points as $k => $label) {
            $out[] = ['id' => (string) $k, 'label' => (string) $label];
        }

        return $out;
    }

    private function serviceCategories(): array
    {
        $set = ['Domain', 'Web Hosting', 'SSL'];
        $halo = Setting::get('halo', []);
        foreach ((array) (is_array($halo) ? ($halo['ticket_type_mappings'] ?? []) : []) as $m) {
            $c = trim((string) ($m['service_category'] ?? ''));
            if ($c !== '') {
                $set[] = $c;
            }
        }

        return array_map(fn ($c) => ['id' => $c, 'label' => $c], array_values(array_unique($set)));
    }

    private function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    private function ddClients(string $q): array
    {
        return Client::query()->when($q !== '', fn ($b) => $b->where('business_name', 'like', $this->like($q)))
            ->orderBy('business_name')->limit(200)->get(['id', 'business_name'])
            ->map(fn ($c) => ['id' => (string) $c->id, 'label' => (string) ($c->business_name ?: 'Client #' . $c->id)])->all();
    }

    private function haloMirrorClients(string $q): array
    {
        return HaloClient::query()->where('inactive', false)
            ->when($q !== '', fn ($b) => $b->where('name', 'like', $this->like($q)))
            ->orderBy('name')->limit(200)->get(['halo_id', 'name'])
            ->map(fn ($c) => ['id' => (string) $c->halo_id, 'label' => (string) ($c->name ?: 'Halo client #' . $c->halo_id)])->all();
    }

    private function itglueMirrorOrgs(string $q): array
    {
        return ItglueOrganization::query()
            ->when($q !== '', fn ($b) => $b->where('name', 'like', $this->like($q)))
            ->orderBy('name')->limit(200)->get(['itglue_id', 'name'])
            ->map(fn ($o) => ['id' => (string) $o->itglue_id, 'label' => (string) ($o->name ?: 'Organisation #' . $o->itglue_id)])->all();
    }

    /** Item search is Halo-side paged and already cached by the client (60 s); refresh bypasses that. */
    private function haloItems(string $q, bool $refresh): array
    {
        if ($problem = HaloPsaClient::configurationProblem()) {
            return [null, $problem];
        }
        $res = app(HaloPsaClient::class)->searchItems($q, false, 1, 50, $refresh);
        $out = array_map(fn ($i) => ['id' => (string) $i['id'], 'label' => (string) ($i['name'] ?? ('Item #' . $i['id']))], $res['items']);

        return [array_values($out), false];
    }
}
