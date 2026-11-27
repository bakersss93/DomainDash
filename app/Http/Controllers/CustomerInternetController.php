<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\InternetServiceDiagnostic;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\Halo\HaloPsaClient;
use App\Services\Vocus\VocusWsmClient;
use App\Support\InternetCustomerSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Read-only customer view of the internet services of the user's own clients.
 *
 * Only an explicit allow-list of fields ever reaches the views (see present()): no PPPoE username, carrier
 * identifiers (AVC/CVC/NTD/...), cost or sell prices, Halo/IT Glue internals or transaction payloads.
 */
class CustomerInternetController extends Controller
{
    /**
     * Diagnostics a customer may run. AUTH-LOG only reads the authentication log.
     * DISCONNECT is deliberately excluded: it kicks the live PPPoE session (service-affecting).
     */
    public const CUSTOMER_DIAGNOSTICS = ['AUTH-LOG'];

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->isStaff()) {
            return redirect()->route('admin.services.internet');
        }
        $this->authorize('viewAny', InternetService::class);

        $services = InternetService::query()
            ->with(['client:id,business_name', 'vocusPlan'])
            ->whereIn('client_id', $user->clients()->pluck('clients.id'))
            ->orderBy('address_long')
            ->orderBy('id')
            ->get();

        return view('internet.index', [
            'services' => $services->map(fn (InternetService $s) => $this->present($s)),
            'canRequestNew' => $this->canRequestNew($user),
            'clients' => $user->clients()->orderBy('business_name')->get(['clients.id', 'clients.business_name']),
        ]);
    }

    public function show(Request $request, InternetService $service)
    {
        $user = $request->user();
        $this->authorize('view', $service);
        $service->loadMissing(['client', 'vocusPlan']);

        $canDiagnose = $user->can('diagnose', $service);
        $history = $canDiagnose
            ? $service->diagnostics()->where('run_by_user_id', '!=', null)->where('diagnostic_type', 'AUTH-LOG')->take(5)->get()
                ->map(fn (InternetServiceDiagnostic $d) => $this->presentAuthLog($d))
            : collect();

        return view('internet.show', [
            'service' => $this->present($service),
            'serviceId' => $service->id,
            'canDiagnose' => $canDiagnose,
            'history' => $history,
            'canRequestCancellation' => $this->canRequestCancellation($user, $service),
        ]);
    }

    public function diagnostic(Request $request, InternetService $service)
    {
        $this->authorize('diagnose', $service);

        $request->validate(['type' => ['required', 'in:' . implode(',', self::CUSTOMER_DIAGNOSTICS)]]);

        if ($service->isLayer2() || ! $service->plan_id || ! $service->vocus_service_id) {
            return back()->with('error', 'The connection test is not available for this service.');
        }
        if (VocusWsmClient::configurationProblem() !== null) {
            return back()->with('error', 'The connection test is not available right now. Please try again later or contact support.');
        }

        try {
            $response = app(VocusWsmClient::class)->getAuthLog($service->vocus_service_id, $service->plan_id);

            // Store only what is shown: time and pass/fail. The carrier "reason" text is not kept.
            $records = array_map(fn (array $r) => [
                'datetime' => (string) ($r['datetime'] ?? ''),
                'auth_result' => (string) ($r['auth_result'] ?? ''),
            ], array_slice($response['records'] ?? [], 0, 50));

            InternetServiceDiagnostic::create([
                'internet_service_id' => $service->id,
                'diagnostic_type' => 'AUTH-LOG',
                'transaction_id' => $response['transaction_id'] ?? null,
                'result' => ['records' => $records],
                'run_by_user_id' => Auth::id(),
                'created_at' => now(),
            ]);

            AuditLogger::logSystem('internet.diagnostic', "Customer ran AUTH-LOG diagnostic on {$service->vocus_service_id}.", [
                'service' => 'vocus',
                'function' => 'customer-internet-diagnostic',
                'client_id' => $service->client_id,
                'user_id' => Auth::id(),
            ], []);

            return back()->with('status', 'Connection test completed.');
        } catch (\Throwable $e) {
            Log::warning('Customer internet diagnostic failed', ['service' => $service->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'The connection test could not be completed. Please try again later or contact support.');
        }
    }

    public function requestNew(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canRequestNew($user), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'address' => ['required', 'string', 'max:255'],
            'speed' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $client = $user->clients()->where('clients.id', $data['client_id'])->first();
        abort_unless($client, 403);

        $details = "New internet service request\n"
            . "Requested by: {$user->name} ({$user->email})\n"
            . "Address: {$data['address']}\n"
            . 'Preferred speed: ' . (($data['speed'] ?? null) ?: 'Not specified') . "\n"
            . 'Notes: ' . (($data['notes'] ?? null) ?: 'None');

        $error = $this->createTicket($client, 'New internet service request', $details, null);
        if ($error) {
            return back()->withErrors(['halo' => $error])->withInput();
        }

        AuditLogger::logSystem('internet.request_new', "Customer requested a new internet service for client {$client->id}.", [
            'function' => 'customer-internet-request',
            'client_id' => $client->id,
            'user_id' => $user->id,
        ], []);

        return redirect()->route('internet.index')->with('status', 'Your request has been logged with our support team.');
    }

    public function requestCancellation(Request $request, InternetService $service)
    {
        $user = $request->user();
        abort_unless($this->canRequestCancellation($user, $service), 403);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $service->loadMissing('client');

        $details = "Internet service cancellation request\n"
            . "Requested by: {$user->name} ({$user->email})\n"
            . "Service: {$service->vocus_service_id}\n"
            . 'Address: ' . ($service->address_long ?: 'Not recorded') . "\n"
            . 'Reason: ' . (($data['reason'] ?? null) ?: 'Not given');

        $error = $this->createTicket($service->client, 'Internet cancellation request: ' . $service->vocus_service_id, $details, $service);
        if ($error) {
            return back()->withErrors(['halo' => $error]);
        }

        AuditLogger::logSystem('internet.request_cancellation', "Customer requested cancellation of {$service->vocus_service_id}.", [
            'function' => 'customer-internet-request',
            'client_id' => $service->client_id,
            'user_id' => $user->id,
        ], []);

        return back()->with('status', 'Your cancellation request has been logged with our support team. The service stays active until we confirm.');
    }

    // -------------------------------------------------------------------------

    private function canRequestNew($user): bool
    {
        return InternetCustomerSettings::allowNewRequests() && $user->can('requestNew', InternetService::class);
    }

    private function canRequestCancellation($user, InternetService $service): bool
    {
        return InternetCustomerSettings::allowCancellationRequests() && $user->can('requestCancellation', $service);
    }

    /** Create the Halo ticket through the same client and mapping rules as TicketController. Returns an error string or null. */
    private function createTicket(Client $client, string $subject, string $details, ?InternetService $service): ?string
    {
        $halo = Setting::get('halo', []);
        $halo = is_array($halo) ? $halo : [];

        if (! $client->halopsa_reference) {
            return 'Your account is not linked to our support system yet. Please contact us directly.';
        }
        if (trim((string) ($halo['base_url'] ?? '')) === '') {
            return 'Support ticketing is not configured yet. Please contact us directly.';
        }

        $mapping = null;
        foreach ((array) ($halo['ticket_type_mappings'] ?? []) as $m) {
            if (! is_array($m) || (int) ($m['halo_ticket_type_id'] ?? 0) <= 0
                || strcasecmp(trim((string) ($m['service_category'] ?? '')), 'Internet') !== 0) {
                continue;
            }
            $mapping ??= $m;
            if (strcasecmp(trim((string) ($m['ticket_type'] ?? '')), 'Service Request') === 0) {
                $mapping = $m;
                break;
            }
        }
        if ($mapping === null) {
            return 'No ticket type is configured for Internet requests. Please contact us directly.';
        }

        $payload = [
            'summary' => $subject,
            'details' => $details,
            'client_id' => (int) $client->halopsa_reference,
            'tickettype_id' => (int) $mapping['halo_ticket_type_id'],
        ];
        if ($service && $service->halo_asset_id) {
            $assetId = (int) $service->halo_asset_id;
            $payload['asset_id'] = $assetId;
            $payload['assets'] = [['id' => $assetId]];
        }

        try {
            app(HaloPsaClient::class)->createTicket($payload);
        } catch (\Throwable $e) {
            Log::warning('Customer internet request ticket failed', ['client' => $client->id, 'error' => $e->getMessage()]);

            return 'We could not log your request right now. Please try again later or contact us directly.';
        }

        return null;
    }

    /** The ONLY fields a customer ever sees. */
    private function present(InternetService $s): array
    {
        $plan = $s->vocusPlan;
        $speed = $plan && ($plan->download_mbps || $plan->upload_mbps)
            ? trim(($plan->download_mbps ?: '?') . '/' . ($plan->upload_mbps ?: '?') . ' Mbps')
            : ($plan?->speed_tier ?: null);

        return [
            'id' => $s->id,
            'service_id' => $s->vocus_service_id,
            'plan' => $plan?->name,
            'speed' => $speed,
            'address' => $s->address_long,
            'technology' => $s->service_type,
            'status' => $s->service_status,
            'ip_type' => $s->ip_assignment,
            'ip_address' => $s->ip_address,
            'reference' => $s->reference,
            'client' => $s->client?->business_name,
            'can_diagnose_hint' => (bool) $s->client?->internet_diagnostics_enabled,
        ];
    }

    private function presentAuthLog(InternetServiceDiagnostic $d): array
    {
        $records = (array) ($d->result['records'] ?? []);
        $passed = count(array_filter($records, fn ($r) => strtoupper((string) ($r['auth_result'] ?? '')) === 'PASS'));

        return [
            'ran_at' => $d->created_at,
            'total' => count($records),
            'passed' => $passed,
            'failed' => count($records) - $passed,
            'rows' => array_map(fn ($r) => [
                'time' => (string) ($r['datetime'] ?? ''),
                'ok' => strtoupper((string) ($r['auth_result'] ?? '')) === 'PASS',
            ], array_slice($records, 0, 10)),
        ];
    }
}
