<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Domain;
use App\Models\HostingService;
use App\Models\Setting;
use App\Models\SslCertificate;
use App\Services\Halo\HaloPsaClient;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    private const TICKET_TYPES = ['Support/Issue', 'Service Request'];

    public function index(Request $request)
    {
        $user = auth()->user();
        $clients = $this->availableClients($user);
        $selectedClientId = $this->resolveSelectedClientId($request, $clients);
        $selectedClient = $clients->firstWhere('id', $selectedClientId);
        $serviceFilter = trim((string) $request->query('service_category', ''));
        $ticketTypeFilter = $request->filled('ticket_type_id') ? (int) $request->query('ticket_type_id') : null;
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = 25;
        $ticketMappings = $this->configuredTicketMappings();
        $configuredTypeIds = $this->ticketTypeIds();
        $serviceOptions = array_values(array_unique(array_map(
            fn (array $mapping): string => (string) ($mapping['service_category'] ?? ''),
            $ticketMappings
        )));
        $serviceOptions = array_values(array_filter($serviceOptions, fn (string $value): bool => $value !== ''));
        $tickets = [];
        $error = null;
        $hasMore = false;
        $fetchedTicketCount = 0;

        if (!$this->isHaloConfigured()) {
            $error = 'HaloPSA is not configured yet. Please update Admin > Settings > HaloPSA.';
        } elseif ($selectedClient && $selectedClient->halopsa_reference) {
            try {
                $halo = app(HaloPsaClient::class);
                $tickets = $halo->listTicketsForClient(
                    (int) $selectedClient->halopsa_reference,
                    $configuredTypeIds,
                    $page,
                    $pageSize
                );
                $fetchedTicketCount = count($tickets);
            } catch (\RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        } elseif ($selectedClient) {
            $error = 'This client is not linked to HaloPSA yet.';
        }

        if ($selectedClient && $selectedClient->halopsa_reference) {
            $allowedTypeIds = [];
            foreach ($ticketMappings as $mapping) {
                $mapTypeId = (int) ($mapping['halo_ticket_type_id'] ?? 0);
                if ($mapTypeId > 0) {
                    $allowedTypeIds[$mapTypeId] = true;
                }
            }

            $tickets = array_values(array_filter($tickets, function (array $ticket) use ($selectedClient, $allowedTypeIds): bool {
                $ticketClientId = (int) ($ticket['client_id'] ?? $ticket['ClientId'] ?? 0);
                if ($ticketClientId !== (int) $selectedClient->halopsa_reference) {
                    return false;
                }

                if (empty($allowedTypeIds)) {
                    return false;
                }

                $ticketTypeId = $this->extractTicketTypeId($ticket);
                return isset($allowedTypeIds[$ticketTypeId]);
            }));
        }

        if ($serviceFilter !== '') {
            $tickets = array_values(array_filter($tickets, function (array $ticket) use ($serviceFilter): bool {
                $ticketServiceCategory = (string) ($ticket['category_1']
                    ?? $ticket['Category1']
                    ?? $ticket['category1']
                    ?? '');

                return strcasecmp($ticketServiceCategory, $serviceFilter) === 0;
            }));
        }

        if ($ticketTypeFilter !== null) {
            $tickets = array_values(array_filter($tickets, function (array $ticket) use ($ticketTypeFilter): bool {
                $ticketTypeId = $this->extractTicketTypeId($ticket);
                return $ticketTypeId === $ticketTypeFilter;
            }));
        }

        $hasMore = $fetchedTicketCount === $pageSize;
        $rows = $this->normalizeTicketRows($tickets);

        if ($request->wantsJson()) {
            return response()->json([
                'rows' => $rows,
                'has_more' => $hasMore,
                'page' => $page,
            ]);
        }

        return view('tickets.requests', [
            'clients' => $clients,
            'selectedClientId' => $selectedClientId,
            'selectedClient' => $selectedClient,
            'tickets' => $rows,
            'ticketMappings' => $ticketMappings,
            'serviceOptions' => $serviceOptions,
            'selectedServiceCategory' => $serviceFilter,
            'selectedTicketTypeId' => $ticketTypeFilter,
            'page' => $page,
            'hasMore' => $hasMore,
            'error' => $error,
        ]);
    }

    public function create()
    {
        $user = auth()->user();
        $clients = $this->availableClients($user);
        $clientIds = $clients->pluck('id');

        $domains = Domain::whereIn('client_id', $clientIds)->orderBy('name')->get();
        $services = HostingService::whereIn('client_id', $clientIds)->orderBy('username')->get();
        $sslCertificates = SslCertificate::whereIn('client_id', $clientIds)->orderBy('common_name')->get();

        return view('tickets.create', [
            'clients' => $clients,
            'domains' => $domains,
            'services' => $services,
            'sslCertificates' => $sslCertificates,
            'ticketTypes' => self::TICKET_TYPES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject'      => 'required|string|max:255',
            'message'      => 'required|string',
            'client_id'    => 'required|exists:clients,id',
            'ticket_type'  => ['required', Rule::in(self::TICKET_TYPES)],
            'reference_type' => ['required', Rule::in(['domain', 'service', 'ssl'])],
            'reference_id' => 'required|integer',
        ]);

        $userClientIds = $this->availableClients(auth()->user())->pluck('id');
        if (!$userClientIds->contains((int) $data['client_id'])) {
            abort(403, 'You are not allowed to log tickets for this client.');
        }

        $client = Client::query()->findOrFail($data['client_id']);
        if (!$client->halopsa_reference) {
            return back()->withErrors([
                'halo' => 'The selected client is not linked to HaloPSA.',
            ])->withInput();
        }

        if (!$this->isHaloConfigured()) {
            return back()->withErrors([
                'halo' => 'HaloPSA is not configured yet. Please update Admin > Settings > HaloPSA.',
            ])->withInput();
        }

        $referenceContext = $this->resolveReferenceContext($data);
        $serviceCategory = $this->serviceCategoryFromReferenceType($data['reference_type']);
        try {
            $mapping = $this->findTicketMapping($serviceCategory, $data['ticket_type']);
            if ($mapping === null) {
                return back()->withErrors([
                    'ticket_type' => 'No Halo ticket type mapping is configured for this service category and ticket type. Please ask an administrator to update Settings > HaloPSA.',
                ])->withInput();
            }

            $halo = app(HaloPsaClient::class);
            $payload = [
                'Summary'  => $data['subject'],
                'Details'  => $data['message'],
                'ClientId' => (int) $client->halopsa_reference,
                'TicketType' => $data['ticket_type'],
                'TicketTypeId' => (int) $mapping['halo_ticket_type_id'],
                'Category1' => $serviceCategory,
                'CustomFields' => [
                    [
                        'Name'  => 'DomainDash Reference',
                        'Value' => $referenceContext['label'],
                    ],
                ],
            ];

            // Attach the DomainDash-linked Halo asset so Halo tickets stay
            // connected to the same asset shown in Related Assets.
            if (!empty($referenceContext['asset_id'])) {
                $payload['AssetId'] = (int) $referenceContext['asset_id'];
            }

            $halo->createTicket($payload);
        } catch (\RuntimeException $exception) {
            return back()->withErrors([
                'halo' => $exception->getMessage(),
            ])->withInput();
        }

        return redirect()->back()->with('status', 'Ticket logged to HaloPSA');
    }

    private function availableClients($user)
    {
        return $user->hasRole('Administrator')
            ? Client::query()->orderBy('business_name')->get()
            : $user->clients()->orderBy('business_name')->get();
    }

    private function resolveSelectedClientId(Request $request, $clients): ?int
    {
        $requestedClientId = $request->integer('client_id');
        if ($requestedClientId && $clients->pluck('id')->contains($requestedClientId)) {
            return $requestedClientId;
        }

        return $clients->first()?->id;
    }

    private function ticketTypeIds(): array
    {
        $mappingTypeIds = array_values(array_filter(array_map(
            fn (array $mapping): ?int => isset($mapping['halo_ticket_type_id']) ? (int) $mapping['halo_ticket_type_id'] : null,
            $this->configuredTicketMappings()
        )));

        return array_values(array_unique($mappingTypeIds));
    }

    private function resolveReferenceContext(array $data): array
    {
        if ($data['reference_type'] === 'domain') {
            $domain = Domain::query()
                ->where('id', $data['reference_id'])
                ->where('client_id', $data['client_id'])
                ->firstOrFail();

            return [
                'label' => 'Domain: ' . $domain->name,
                'asset_id' => $domain->halo_asset_id ? (int) $domain->halo_asset_id : null,
            ];
        }

        if ($data['reference_type'] === 'service') {
            $service = HostingService::query()
                ->where('id', $data['reference_id'])
                ->where('client_id', $data['client_id'])
                ->firstOrFail();

            $domain = $service->domain;
            return [
                'label' => 'Hosting Service: ' . ($service->username ?: 'Service #' . $service->id),
                'asset_id' => $domain && $domain->halo_asset_id ? (int) $domain->halo_asset_id : null,
            ];
        }

        $ssl = SslCertificate::query()
            ->where('id', $data['reference_id'])
            ->where('client_id', $data['client_id'])
            ->firstOrFail();

        $domain = $ssl->domain;
        return [
            'label' => 'SSL: ' . ($ssl->common_name ?: 'Certificate #' . $ssl->id),
            'asset_id' => $domain && $domain->halo_asset_id ? (int) $domain->halo_asset_id : null,
        ];
    }

    private function haloSettings(): array
    {
        $haloSettings = Setting::get('halo', []);
        return is_array($haloSettings) ? $haloSettings : [];
    }

    private function configuredTicketMappings(): array
    {
        $haloSettings = $this->haloSettings();
        $mappings = $haloSettings['ticket_type_mappings'] ?? [];

        if (!is_array($mappings)) {
            return [];
        }

        return array_values(array_filter($mappings, function ($mapping): bool {
            return is_array($mapping)
                && !empty($mapping['service_category'])
                && !empty($mapping['halo_ticket_type_id']);
        }));
    }

    private function findTicketMapping(string $serviceCategory, string $ticketType): ?array
    {
        $serviceCategory = trim($serviceCategory);
        $ticketType = trim($ticketType);

        $serviceOnlyFallback = null;

        foreach ($this->configuredTicketMappings() as $mapping) {
            $mappedService = trim((string) ($mapping['service_category'] ?? ''));
            $mappedTicketType = trim((string) ($mapping['ticket_type'] ?? ''));
            $mappedTypeId = (int) ($mapping['halo_ticket_type_id'] ?? 0);

            if ($mappedTypeId <= 0) {
                continue;
            }

            if (strcasecmp($mappedService, $serviceCategory) !== 0) {
                continue;
            }

            if ($serviceOnlyFallback === null) {
                $serviceOnlyFallback = $mapping;
            }

            if ($mappedTicketType === '') {
                continue;
            }

            if (strcasecmp($mappedTicketType, $ticketType) !== 0) {
                continue;
            }

            return $mapping;
        }

        return $serviceOnlyFallback;
    }

    private function isHaloConfigured(): bool
    {
        return trim((string) ($this->haloSettings()['base_url'] ?? '')) !== '';
    }

    private function serviceCategoryFromReferenceType(string $referenceType): string
    {
        return match ($referenceType) {
            'domain' => 'Domain',
            'service' => 'Web Hosting',
            default => 'SSL',
        };
    }

    private function normalizeTicketRows(array $tickets): array
    {
        return array_values(array_map(function (array $ticket): array {
            return [
                'id' => $ticket['id'] ?? $ticket['Id'] ?? '-',
                'summary' => $ticket['summary'] ?? $ticket['Summary'] ?? '-',
                'service' => $this->extractTicketServiceLabel($ticket),
                'type' => $this->extractTicketTypeLabel($ticket),
                'type_id' => $this->extractTicketTypeId($ticket),
                'status' => $ticket['status_name'] ?? $ticket['StatusName'] ?? $ticket['status'] ?? '-',
                'updated' => $ticket['lastactiondate'] ?? $ticket['LastActionDate'] ?? $ticket['datecreated'] ?? '-',
            ];
        }, $tickets));
    }

    private function extractTicketTypeId(array $ticket): int
    {
        return (int) (
            $ticket['tickettype_id']
            ?? $ticket['TicketTypeId']
            ?? $ticket['ticket_type_id']
            ?? $ticket['tickettype']['id']
            ?? $ticket['tickettype']['Id']
            ?? $ticket['TicketType']['id']
            ?? $ticket['TicketType']['Id']
            ?? 0
        );
    }

    private function extractTicketTypeLabel(array $ticket): string
    {
        $label = $ticket['tickettype_name']
            ?? $ticket['TicketTypeName']
            ?? $ticket['tickettypename']
            ?? $ticket['tickettype']['name']
            ?? $ticket['tickettype']['Name']
            ?? $ticket['TicketType']['name']
            ?? $ticket['TicketType']['Name']
            ?? $ticket['tickettype']
            ?? $ticket['TicketType']
            ?? null;

        return is_string($label) && trim($label) !== '' ? $label : 'Unknown';
    }

    private function extractTicketServiceLabel(array $ticket): string
    {
        $direct = $ticket['asset_name']
            ?? $ticket['AssetName']
            ?? $ticket['asset']
            ?? $ticket['Asset']
            ?? null;
        if (is_string($direct) && trim($direct) !== '') {
            return $direct;
        }

        $assetCollections = [
            $ticket['related_assets'] ?? null,
            $ticket['RelatedAssets'] ?? null,
            $ticket['linked_assets'] ?? null,
            $ticket['LinkedAssets'] ?? null,
            $ticket['assets'] ?? null,
            $ticket['Assets'] ?? null,
            $ticket['ticketassets'] ?? null,
            $ticket['TicketAssets'] ?? null,
        ];

        foreach ($assetCollections as $assets) {
            if (!is_array($assets)) {
                continue;
            }

            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $label = $asset['inventory_number']
                    ?? $asset['InventoryNumber']
                    ?? $asset['key_field']
                    ?? $asset['KeyField']
                    ?? $asset['name']
                    ?? $asset['Name']
                    ?? null;
                if (is_string($label) && trim($label) !== '') {
                    return $label;
                }
            }
        }

        return (string) ($ticket['category_1'] ?? $ticket['Category1'] ?? $ticket['category1'] ?? '-');
    }
}
