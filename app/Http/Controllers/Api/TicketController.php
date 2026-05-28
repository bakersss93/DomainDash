<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    private const TICKET_TYPES = ['Support/Issue', 'Service Request'];

    public function index(Request $request): JsonResponse
    {
        if (!$this->haloConfigured()) {
            return response()->json(['message' => 'HaloPSA is not configured.'], 503);
        }

        $halo     = app(HaloPsaClient::class);
        $page     = max(1, (int) $request->get('page', 1));
        $perPage  = min(100, max(1, (int) $request->get('per_page', 25)));
        $tickets  = [];

        $mappings       = $this->ticketMappings();
        $typeIds        = $this->ticketTypeIds($mappings);
        $typeFilter     = $request->get('ticket_type');
        $categoryFilter = $request->get('service_category');
        $clientId       = $request->get('client_id');

        if ($clientId) {
            $client = Client::find((int) $clientId);
            if (!$client || !$client->halopsa_reference) {
                return response()->json(['data' => [], 'message' => 'Client not found or has no HaloPSA reference.']);
            }

            $tickets = $halo->listTicketsForClient(
                (int) $client->halopsa_reference,
                $typeIds,
                $page,
                $perPage
            );
        } else {
            // Iterate clients that have a halopsa_reference
            $clients = Client::whereNotNull('halopsa_reference')->get();
            foreach ($clients as $c) {
                try {
                    $batch = $halo->listTicketsForClient((int) $c->halopsa_reference, $typeIds, 1, $perPage);
                    $tickets = array_merge($tickets, $batch);
                } catch (\Throwable) {
                    // skip failed clients
                }
            }
        }

        // Apply optional type/category filters locally
        if ($typeFilter) {
            $tickets = array_values(array_filter($tickets, fn($t) => ($t['tickettype_name'] ?? '') === $typeFilter));
        }
        if ($categoryFilter) {
            $tickets = array_values(array_filter($tickets, fn($t) => ($t['service_category'] ?? '') === $categoryFilter));
        }

        return response()->json([
            'data'         => $tickets,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => count($tickets),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->haloConfigured()) {
            return response()->json(['message' => 'HaloPSA is not configured.'], 503);
        }

        $data = $request->validate([
            'client_id'   => 'required|integer|exists:clients,id',
            'subject'     => 'required|string|max:500',
            'details'     => 'required|string',
            'ticket_type' => 'required|in:Support/Issue,Service Request',
            'domain_id'   => 'nullable|integer|exists:domains,id',
            'service_id'  => 'nullable|integer|exists:hosting_services,id',
            'ssl_id'      => 'nullable|integer|exists:ssl_certificates,id',
        ]);

        $client = Client::findOrFail((int) $data['client_id']);

        if (!$client->halopsa_reference) {
            return response()->json(['message' => 'Client has no HaloPSA reference.'], 422);
        }

        $mappings = $this->ticketMappings();
        $typeId   = $this->resolveTypeId($data['ticket_type'], $mappings);

        $halo   = app(HaloPsaClient::class);
        $result = $halo->createTicket([
            'summary'         => $data['subject'],
            'details'         => $data['details'],
            'client_id'       => (int) $client->halopsa_reference,
            'tickettype_id'   => $typeId,
        ]);

        return response()->json($result, 201);
    }

    public function show(int $ticketId): JsonResponse
    {
        if (!$this->haloConfigured()) {
            return response()->json(['message' => 'HaloPSA is not configured.'], 503);
        }

        $halo    = app(HaloPsaClient::class);
        $ticket  = $halo->getTicket($ticketId);
        $actions = $halo->getTicketActions($ticketId);

        return response()->json(array_merge($ticket, ['actions' => $actions]));
    }

    public function reply(Request $request, int $ticketId): JsonResponse
    {
        if (!$this->haloConfigured()) {
            return response()->json(['message' => 'HaloPSA is not configured.'], 503);
        }

        $data = $request->validate([
            'action_description' => 'required|string',
        ]);

        $halo   = app(HaloPsaClient::class);
        $result = $halo->createTicketAction($ticketId, $data['action_description'], false);

        return response()->json(['ok' => true, 'message' => 'Reply added.', 'action' => $result]);
    }

    public function close(int $ticketId): JsonResponse
    {
        if (!$this->haloConfigured()) {
            return response()->json(['message' => 'HaloPSA is not configured.'], 503);
        }

        $mappings = $this->ticketMappings();
        $closedStatusId = (int) ($mappings['closed_status_id'] ?? 9);

        $halo   = app(HaloPsaClient::class);
        $result = $halo->updateTicketStatus($ticketId, $closedStatusId);

        return response()->json(['ok' => true, 'message' => 'Ticket closed.', 'result' => $result]);
    }

    private function haloConfigured(): bool
    {
        $key = Setting::get('halo_api_key');
        $url = Setting::get('halo_api_url');
        return !empty($key) && !empty($url);
    }

    private function ticketMappings(): array
    {
        return Setting::get('ticket_type_mappings', []);
    }

    private function ticketTypeIds(array $mappings): array
    {
        return array_values(array_filter(array_column($mappings, 'halo_type_id')));
    }

    private function resolveTypeId(string $type, array $mappings): ?int
    {
        foreach ($mappings as $mapping) {
            if (($mapping['service_category'] ?? '') === $type || ($mapping['label'] ?? '') === $type) {
                return isset($mapping['halo_type_id']) ? (int) $mapping['halo_type_id'] : null;
            }
        }
        return null;
    }
}
