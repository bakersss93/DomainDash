<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HaloClient;
use App\Models\ItglueOrganization;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Sync\SyncDispatcher;
use App\Services\Vocus\VocusOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * JSON CRUD behind the <x-ui.mapping-table> component (Settings pages).
 *
 * One endpoint family, one `{mapping}` key per list of matches:
 *   halo.ticket-types   settings key halo.ticket_type_mappings   (service category + class -> Halo ticket type)
 *   halo.statuses       settings key halo.status_mappings        (DomainDash status label <- Halo status)
 *   halo.clients        clients.halopsa_reference                (DomainDash client <-> Halo client)
 *   itglue.organisations clients.itglue_org_id                   (DomainDash client <-> IT Glue organisation)
 *   vocus.realms        settings key vocus.realms                (PPPoE realms)
 *
 * Rows are { id, cells: {column => text}, values: {field => {id,label}|string} }. Lists stored in a setting use
 * the list index as id; the stored shape is exactly what the old forms posted, so every reader keeps working.
 * Routes live in the Administrator-only admin group; every write is audited.
 */
class MappingController extends Controller
{
    public const MAPPINGS = ['halo.ticket-types', 'halo.statuses', 'halo.clients', 'itglue.organisations', 'vocus.realms'];

    public const TICKET_CLASSES = ['Support/Issue', 'Service Request'];

    public function index(Request $request, string $mapping): JsonResponse
    {
        $this->assertKnown($mapping);
        $perPage = max(5, min(100, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        if (in_array($mapping, ['halo.clients', 'itglue.organisations'], true)) {
            return $this->clientIndex($mapping, $page, $perPage, $q);
        }

        $rows = [];
        foreach ($this->readList($mapping) as $i => $item) {
            $row = $this->listRow($mapping, (array) $item, (string) $i);
            if ($q !== '' && ! str_contains(mb_strtolower(implode(' ', $row['cells'])), $q)) {
                continue;
            }
            $rows[] = $row;
        }

        return response()->json([
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => count($rows),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function store(Request $request, string $mapping): JsonResponse
    {
        $this->assertKnown($mapping);

        if (in_array($mapping, ['halo.clients', 'itglue.organisations'], true)) {
            return $this->clientSave($request, $mapping, null);
        }

        $list = $this->readList($mapping);
        $item = $this->listItem($request, $mapping, $list, null);
        $list[] = $item;
        $this->writeList($mapping, $list);
        $this->audit($mapping, 'added', $item);

        return response()->json(['row' => $this->listRow($mapping, $item, (string) (count($list) - 1))], 201);
    }

    public function update(Request $request, string $mapping, string $id): JsonResponse
    {
        $this->assertKnown($mapping);

        if (in_array($mapping, ['halo.clients', 'itglue.organisations'], true)) {
            return $this->clientSave($request, $mapping, (int) $id);
        }

        $list = $this->readList($mapping);
        if (! ctype_digit($id) || ! array_key_exists((int) $id, $list)) {
            return response()->json(['message' => 'That mapping no longer exists. Reload the list.'], 404);
        }
        $item = $this->listItem($request, $mapping, $list, (int) $id);
        $list[(int) $id] = $item;
        $this->writeList($mapping, $list);
        $this->audit($mapping, 'updated', $item);

        return response()->json(['row' => $this->listRow($mapping, $item, $id)]);
    }

    public function destroy(Request $request, string $mapping, string $id): JsonResponse
    {
        $this->assertKnown($mapping);

        if (in_array($mapping, ['halo.clients', 'itglue.organisations'], true)) {
            $client = Client::find((int) $id);
            if (! $client) {
                return response()->json(['message' => 'That client no longer exists. Reload the list.'], 404);
            }
            $column = $mapping === 'halo.clients' ? 'halopsa_reference' : 'itglue_org_id';
            $old = $client->{$column};
            $client->{$column} = null;
            $client->save();
            $this->audit($mapping, 'removed', ['client_id' => $client->id, 'was' => $old]);

            return response()->json(['deleted' => true]);
        }

        $list = $this->readList($mapping);
        if (! ctype_digit($id) || ! array_key_exists((int) $id, $list)) {
            return response()->json(['message' => 'That mapping no longer exists. Reload the list.'], 404);
        }
        $removed = $list[(int) $id];
        unset($list[(int) $id]);
        $this->writeList($mapping, array_values($list));
        $this->audit($mapping, 'removed', (array) $removed);

        return response()->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------
    // Settings-backed lists
    // ------------------------------------------------------------------

    /** @return array<int,mixed> */
    private function readList(string $mapping): array
    {
        return match ($mapping) {
            'halo.ticket-types' => $this->haloSub('ticket_type_mappings'),
            'halo.statuses' => $this->haloSub('status_mappings'),
            'vocus.realms' => VocusOptions::realms(),
        };
    }

    private function writeList(string $mapping, array $list): void
    {
        $list = array_values($list);
        match ($mapping) {
            'halo.ticket-types' => $this->putSub('halo', 'ticket_type_mappings', $list),
            'halo.statuses' => $this->putSub('halo', 'status_mappings', $list),
            'vocus.realms' => $this->putSub('vocus', 'realms', $list),
        };
    }

    private function haloSub(string $key): array
    {
        $halo = Setting::get('halo', []);
        $list = is_array($halo) ? ($halo[$key] ?? []) : [];

        return is_array($list) ? array_values($list) : [];
    }

    /** Write one sub-key, leaving every other key of the setting untouched. */
    private function putSub(string $setting, string $key, mixed $value): void
    {
        $current = Setting::get($setting, []);
        $current = is_array($current) ? $current : [];
        $current[$key] = $value;
        Setting::put($setting, $current);
    }

    private function listRow(string $mapping, array|string $item, string $id): array
    {
        if ($mapping === 'vocus.realms') {
            $realm = (string) (is_array($item) ? ($item[0] ?? '') : $item);

            return ['id' => $id, 'cells' => ['realm' => $realm], 'values' => ['realm' => $realm]];
        }

        if ($mapping === 'halo.ticket-types') {
            $tid = (int) ($item['halo_ticket_type_id'] ?? 0);
            $tname = trim((string) ($item['halo_ticket_type_name'] ?? '')) ?: ($tid ? 'Ticket type #' . $tid : '');
            $class = (string) ($item['ticket_type'] ?? 'Support/Issue');
            $cat = (string) ($item['service_category'] ?? '');

            return [
                'id' => $id,
                'cells' => ['service_category' => $cat, 'halo_ticket_type' => $tname, 'ticket_class' => $class],
                'values' => [
                    'service_category' => ['id' => $cat, 'label' => $cat],
                    'halo_ticket_type' => ['id' => (string) $tid, 'label' => $tname],
                    'ticket_class' => $class,
                ],
            ];
        }

        $sid = (int) ($item['halo_status_id'] ?? 0);
        $sname = trim((string) ($item['halo_status_name'] ?? '')) ?: ($sid ? 'Status #' . $sid : '');
        $label = (string) ($item['domaindash_status'] ?? '');

        return [
            'id' => $id,
            'cells' => ['domaindash_status' => $label, 'halo_status' => $sname],
            'values' => [
                'domaindash_status' => ['id' => $label, 'label' => $label],
                'halo_status' => ['id' => (string) $sid, 'label' => $sname],
            ],
        ];
    }

    /** Validate the body and build the stored item. Throws a 422 for bad or duplicate input. */
    private function listItem(Request $request, string $mapping, array $list, ?int $editing): array|string
    {
        if ($mapping === 'vocus.realms') {
            $data = $request->validate(['realm' => 'required|string|max:' . VocusOptions::MAX_REALM_LENGTH]);
            $others = $list;
            if ($editing !== null) {
                unset($others[$editing]);
            }
            [$realms, $errors] = VocusOptions::validateRealms(array_merge(array_values($others), [$data['realm']]));
            if ($errors !== []) {
                throw ValidationException::withMessages(['realm' => [$errors[0]]]);
            }

            return (string) end($realms);
        }

        if ($mapping === 'halo.ticket-types') {
            $data = $request->validate([
                'service_category' => 'required|string|max:120',
                'halo_ticket_type' => 'required|integer|min:1',
                'halo_ticket_type_label' => 'nullable|string|max:180',
                'ticket_class' => 'nullable|in:' . implode(',', self::TICKET_CLASSES),
            ]);
            $item = [
                'service_category' => trim($data['service_category']),
                'ticket_type' => $data['ticket_class'] ?? 'Support/Issue',
                'halo_ticket_type_id' => (int) $data['halo_ticket_type'],
                'halo_ticket_type_name' => trim((string) ($data['halo_ticket_type_label'] ?? '')),
            ];
            foreach ($list as $i => $existing) {
                if ($i === $editing) {
                    continue;
                }
                if (strcasecmp((string) ($existing['service_category'] ?? ''), $item['service_category']) === 0
                    && ($existing['ticket_type'] ?? 'Support/Issue') === $item['ticket_type']) {
                    throw ValidationException::withMessages(['service_category' => ['That service category already has a mapping for this ticket class.']]);
                }
            }

            return $item;
        }

        $data = $request->validate([
            'domaindash_status' => 'required|string|max:120',
            'halo_status' => 'required|integer|min:1',
            'halo_status_label' => 'nullable|string|max:180',
        ]);
        $item = [
            'domaindash_status' => trim($data['domaindash_status']),
            'halo_status_id' => (int) $data['halo_status'],
            'halo_status_name' => trim((string) ($data['halo_status_label'] ?? '')),
        ];
        foreach ($list as $i => $existing) {
            if ($i !== $editing && (int) ($existing['halo_status_id'] ?? 0) === $item['halo_status_id']) {
                throw ValidationException::withMessages(['halo_status' => ['That Halo status is already mapped.']]);
            }
        }

        return $item;
    }

    // ------------------------------------------------------------------
    // Client <-> remote system (columns on the clients table)
    // ------------------------------------------------------------------

    private function clientIndex(string $mapping, int $page, int $perPage, string $q): JsonResponse
    {
        $halo = $mapping === 'halo.clients';
        $column = $halo ? 'halopsa_reference' : 'itglue_org_id';

        $query = Client::query()->whereNotNull($column)->where($column, '!=', '')->orderBy('business_name');
        if ($q !== '') {
            $query->where('business_name', 'like', '%' . addcslashes($q, '%_\\') . '%');
        }
        $total = (clone $query)->count();
        $clients = $query->forPage($page, $perPage)->get(['id', 'business_name', $column]);

        $remoteNames = $halo
            ? HaloClient::whereIn('halo_id', $clients->pluck($column)->map(fn ($v) => (int) $v)->all())->pluck('name', 'halo_id')
            : ItglueOrganization::whereIn('itglue_id', $clients->pluck($column)->all())->pluck('name', 'itglue_id');

        $rows = $clients->map(function (Client $c) use ($column, $remoteNames, $halo) {
            $ref = (string) $c->{$column};
            $name = (string) ($remoteNames[$halo ? (int) $ref : $ref] ?? '');
            $label = $name !== '' ? $name : ($halo ? 'Halo client #' : 'Organisation #') . $ref;

            return [
                'id' => (string) $c->id,
                'cells' => ['dash_client' => (string) $c->business_name, 'remote' => $label, 'remote_id' => $ref],
                'values' => [
                    'dash_client' => ['id' => (string) $c->id, 'label' => (string) $c->business_name],
                    'remote' => ['id' => $ref, 'label' => $label],
                ],
            ];
        })->values()->all();

        return response()->json(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    private function clientSave(Request $request, string $mapping, ?int $editingClientId): JsonResponse
    {
        $halo = $mapping === 'halo.clients';
        $data = $request->validate([
            'dash_client' => 'required|integer|exists:clients,id',
            'remote' => 'required|string|max:64',
        ]);
        $column = $halo ? 'halopsa_reference' : 'itglue_org_id';
        $remote = trim($data['remote']);

        if ($halo) {
            $mirror = HaloClient::find((int) $remote);
            if (! $mirror) {
                throw ValidationException::withMessages(['remote' => ['That Halo client is not in the local mirror. Refresh the Halo client list first.']]);
            }
            $taken = Client::where($column, (string) $mirror->halo_id)->where('id', '!=', (int) $data['dash_client'])->first();
            if ($taken) {
                throw ValidationException::withMessages(['remote' => ['That Halo client is already mapped to ' . $taken->business_name . '.']]);
            }
            $remote = (string) $mirror->halo_id;
        } elseif (! ItglueOrganization::where('itglue_id', $remote)->exists()) {
            throw ValidationException::withMessages(['remote' => ['That organisation is not in the local mirror. Refresh the IT Glue list first.']]);
        }

        $client = Client::findOrFail((int) $data['dash_client']);

        // Moving a mapping to a different DomainDash client frees the old one.
        if ($editingClientId !== null && $editingClientId !== $client->id) {
            $previous = Client::find($editingClientId);
            if ($previous) {
                $previous->{$column} = null;
                $previous->save();
            }
        }

        $client->{$column} = $remote;
        if ($halo && ! empty($mirror->abn)) {
            $client->abn = $mirror->abn;
        }
        $client->save();

        $queued = false;
        if ($halo) {
            try {
                app(SyncDispatcher::class)->refreshHaloClient($client, $request->user()?->id);
                $queued = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->audit($mapping, $editingClientId === null ? 'added' : 'updated', ['client_id' => $client->id, 'remote' => $remote]);

        $row = $this->clientIndexRow($mapping, $client);

        return response()->json(['row' => $row, 'queued' => $queued], $editingClientId === null ? 201 : 200);
    }

    private function clientIndexRow(string $mapping, Client $client): array
    {
        $json = $this->clientIndex($mapping, 1, 100, mb_strtolower((string) $client->business_name))->getData(true);
        foreach ($json['rows'] as $row) {
            if ($row['id'] === (string) $client->id) {
                return $row;
            }
        }

        return ['id' => (string) $client->id, 'cells' => [], 'values' => []];
    }

    // ------------------------------------------------------------------

    private function assertKnown(string $mapping): void
    {
        abort_unless(in_array($mapping, self::MAPPINGS, true), 404);
    }

    private function audit(string $mapping, string $what, array|string $detail): void
    {
        AuditLogger::logSystem('settings.mapping.' . $what, "Mapping {$mapping} {$what}.", [
            'service' => 'settings',
            'function' => 'mapping-' . $mapping,
        ], ['new_values' => ['mapping' => $mapping, 'item' => is_array($detail) ? $detail : ['value' => $detail]]]);
    }
}
