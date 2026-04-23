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
    private const SUB_CATEGORIES = ['Domain', 'Web Hosting', 'SSL'];
    private const TICKET_TYPES = ['Support/Issue', 'Service Request'];

    public function index(Request $request, HaloPsaClient $halo)
    {
        $user = auth()->user();
        $clients = $this->availableClients($user);
        $selectedClientId = $this->resolveSelectedClientId($request, $clients);
        $selectedClient = $clients->firstWhere('id', $selectedClientId);
        $tickets = [];
        $error = null;

        if ($selectedClient && $selectedClient->halopsa_reference) {
            $ticketTypeIds = $this->ticketTypeIds();
            $tickets = $halo->listTicketsForClient(
                (int) $selectedClient->halopsa_reference,
                $ticketTypeIds
            );
        } elseif ($selectedClient) {
            $error = 'This client is not linked to HaloPSA yet.';
        }

        return view('tickets.index', [
            'clients' => $clients,
            'selectedClientId' => $selectedClientId,
            'selectedClient' => $selectedClient,
            'tickets' => $tickets,
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
            'subCategories' => self::SUB_CATEGORIES,
        ]);
    }

    public function store(Request $request, HaloPsaClient $halo)
    {
        $data = $request->validate([
            'subject'      => 'required|string|max:255',
            'message'      => 'required|string',
            'client_id'    => 'required|exists:clients,id',
            'ticket_type'  => ['required', Rule::in(self::TICKET_TYPES)],
            'sub_category' => ['required', Rule::in(self::SUB_CATEGORIES)],
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

        $referenceLabel = $this->resolveReferenceLabel($data);

        $halo->createTicket([
            'Summary'  => $data['subject'],
            'Details'  => $data['message'],
            'ClientId' => (int) $client->halopsa_reference,
            'TicketType' => $data['ticket_type'],
            'Category1' => $data['sub_category'],
            'CustomFields' => [
                [
                    'Name'  => 'DomainDash Reference',
                    'Value' => $referenceLabel,
                ],
            ],
        ]);

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
        $haloSettings = Setting::get('halo', []);

        return array_values(array_filter([
            isset($haloSettings['support_issue_ticket_type_id']) ? (int) $haloSettings['support_issue_ticket_type_id'] : null,
            isset($haloSettings['service_request_ticket_type_id']) ? (int) $haloSettings['service_request_ticket_type_id'] : null,
        ]));
    }

    private function resolveReferenceLabel(array $data): string
    {
        if ($data['reference_type'] === 'domain') {
            $domain = Domain::query()
                ->where('id', $data['reference_id'])
                ->where('client_id', $data['client_id'])
                ->firstOrFail();

            return 'Domain: ' . $domain->name;
        }

        if ($data['reference_type'] === 'service') {
            $service = HostingService::query()
                ->where('id', $data['reference_id'])
                ->where('client_id', $data['client_id'])
                ->firstOrFail();

            return 'Hosting Service: ' . ($service->username ?: 'Service #' . $service->id);
        }

        $ssl = SslCertificate::query()
            ->where('id', $data['reference_id'])
            ->where('client_id', $data['client_id'])
            ->firstOrFail();

        return 'SSL: ' . ($ssl->common_name ?: 'Certificate #' . $ssl->id);
    }
}
