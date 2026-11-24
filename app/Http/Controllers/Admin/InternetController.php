<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\InternetServiceDiagnostic;
use App\Models\SyncRun;
use App\Models\VocusPlan;
use App\Services\AuditLogger;
use App\Services\Sync\QueueHealth;
use App\Services\Sync\SyncDispatcher;
use App\Services\Vocus\VocusOptions;
use App\Services\Vocus\VocusWsmClient;
use App\Services\Vocus\VocusWsmException;
use App\Support\VocusAddressParser;
use App\Support\VocusAddressStandard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InternetController extends Controller
{
    /** ServiceLevel values documented in the Vocus Data Types Definition. */
    public const SERVICE_LEVELS = [
        'STANDARD', 'ENHANCED4', 'ENHANCED6', 'ENHANCED8', 'ENHANCED12',
        'ENHANCED4-24/7', 'ENHANCED6-24/7', 'ENHANCED8-24/7', 'ENHANCED12-24/7',
    ];

    /** Documented ServiceID charset and length (Data Types: a-z A-Z 0-9 dash underscore dot, 1..30). */
    private const SERVICE_ID_REGEX = '/^[A-Za-z0-9._-]{1,30}$/';

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function index(Request $request)
    {
        $q = InternetService::query()->with('client');

        if ($clientId = $request->get('client_id')) {
            $q->where('client_id', $clientId);
        }

        $statusFilter = $request->has('status') ? $request->get('status') : 'ACTIVE';
        if ($statusFilter !== '') {
            $q->where('service_status', $statusFilter);
        }

        if ($typeFilter = $request->get('service_type')) {
            $q->where('service_type', $typeFilter);
        }

        $services = $q->orderBy('address_long')->paginate(25)->withQueryString();
        $clients  = Client::orderBy('business_name')->get();

        return view('admin.services.internet.index', [
            'services'     => $services,
            'clients'      => $clients,
            'clientId'     => $request->get('client_id'),
            'statusFilter' => $statusFilter,
            'typeFilter'   => $request->get('service_type'),
            'vocusProblem' => VocusWsmClient::configurationProblem(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Detail
    // -------------------------------------------------------------------------

    public function show(InternetService $service)
    {
        $liveData      = null;
        $liveError     = null;
        $liveNotice    = null;
        $clientMatch   = null;

        if ($service->vocus_service_id) {
            if ($service->isPending()) {
                // Ordering is asynchronous: the service does not exist at Vocus until the
                // transaction completes, and Get SERVICE is "not for checking order progress"
                // (Examples 4.1.8). Show what we stored and let the user poll the transaction.
                $liveNotice = 'Awaiting Vocus provisioning. Live details appear once the transaction completes; use "Refresh Status" to check it.';
            } elseif (($problem = VocusWsmClient::configurationProblem()) !== null) {
                $liveError = $problem;
            } else {
                try {
                    $response = app(VocusWsmClient::class)->getService($service->vocus_service_id);
                    $liveData = $response['params'] ?? [];
                } catch (\Throwable $e) {
                    $liveError = $this->friendlyError($e, 'Live details could not be loaded from Vocus.');
                }
            }
        }

        // Suggest a client match if not yet assigned
        if (!$service->client_id && $service->customer_name) {
            $clientMatch = Client::where('business_name', 'like', '%' . $service->customer_name . '%')
                ->orWhere('primary_contact_name', 'like', '%' . $service->customer_name . '%')
                ->first();
        }

        $clients     = Client::orderBy('business_name')->get();
        $diagnostics = $service->diagnostics()->take(10)->get();

        return view('admin.services.internet.show', [
            'service'     => $service,
            'liveData'    => $liveData,
            'liveError'   => $liveError,
            'liveNotice'  => $liveNotice,
            'clientMatch' => $clientMatch,
            'clients'     => $clients,
            'diagnostics' => $diagnostics,
        ]);
    }

    /** Short admin-safe text for any failure: Vocus/config messages as they are, anything else generic (and logged). */
    private function friendlyError(\Throwable $e, string $fallback): string
    {
        if ($e instanceof VocusWsmException) {
            return $e->getMessage();
        }

        Log::warning('Vocus request failed', ['message' => $e->getMessage(), 'class' => class_basename($e)]);

        return $fallback;
    }

    // -------------------------------------------------------------------------
    // Sync from Vocus (queued: live progress through the shared sync panel)
    // -------------------------------------------------------------------------

    public function sync(Request $request, SyncDispatcher $dispatcher)
    {
        if (($problem = VocusWsmClient::configurationProblem()) !== null) {
            return $this->refused($request, $problem, 422);
        }

        $run = $dispatcher->refreshVocus(null, false, $request->user()?->id);

        return $this->queued($request, $run, 'Vocus sync queued. Progress is shown below.');
    }

    // -------------------------------------------------------------------------
    // Manual service ID import (queued)
    // -------------------------------------------------------------------------

    public function importByIds(Request $request, SyncDispatcher $dispatcher)
    {
        $request->validate([
            'service_ids' => 'required|string|max:20000',
        ]);

        $raw     = preg_split('/[\s,;]+/', trim($request->input('service_ids')));
        $ids     = array_values(array_unique(array_filter($raw)));
        $invalid = array_values(array_filter($ids, fn ($id) => ! preg_match(self::SERVICE_ID_REGEX, $id)));
        $ids     = array_values(array_diff($ids, $invalid));

        if ($ids === []) {
            return $this->refused($request, $invalid !== []
                ? 'No valid service IDs. IDs may use letters, digits, dot, dash and underscore (1 to 30 characters). Rejected: ' . implode(', ', array_slice($invalid, 0, 5))
                : 'No service IDs provided.', 422);
        }

        if (($problem = VocusWsmClient::configurationProblem()) !== null) {
            return $this->refused($request, $problem, 422);
        }

        if ($active = SyncRun::active('vocus', 'services')) {
            return $this->refused($request, 'A Vocus sync is already running. Wait for it to finish, then import again.', 409, $active);
        }

        // Rows are created by the job only after Vocus returns each service, so a failed import leaves nothing behind.
        $run = $dispatcher->refreshVocus($ids, true, $request->user()?->id);

        $note = count($ids) . ' service ID(s) queued for import.'
            . ($invalid !== [] ? ' Skipped invalid ID(s): ' . implode(', ', array_slice($invalid, 0, 5)) . '.' : '');

        return $this->queued($request, $run, $note);
    }

    private function queued(Request $request, SyncRun $run, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'queued'  => true,
                'message' => $message,
                'runs'    => [$run->toStatusArray()],
                'worker'  => app(QueueHealth::class)->status(),
            ], 202);
        }

        return redirect()->route('admin.services.internet')->with('status', $message);
    }

    private function refused(Request $request, string $message, int $status, ?SyncRun $run = null)
    {
        if ($request->expectsJson()) {
            $body = ['queued' => false, 'error' => $message];
            if ($run) {
                $body['runs'] = [$run->toStatusArray()];
            }

            return response()->json($body, $status);
        }

        return redirect()->route('admin.services.internet')->with('error', $message);
    }

    // -------------------------------------------------------------------------
    // Address qualification
    // -------------------------------------------------------------------------

    public function qualify()
    {
        return view('admin.services.internet.qualify', [
            'streetTypes' => VocusAddressStandard::streetTypeOptions(),
            'unitTypes'   => VocusAddressStandard::unitTypeOptions(),
            'levelTypes'  => VocusAddressStandard::levelTypeOptions(),
            'states'      => VocusAddressStandard::stateOptions(),
            'vocusProblem' => VocusWsmClient::configurationProblem(),
        ]);
    }

    /**
     * AJAX: search Vocus DIR for matching addresses.
     * Accepts either a simple address string (parsed) or structured fields.
     * Limits follow StandardDataTypes.xsd and the Vocus street address standard
     * (street name 25, suburb 40, standard street / unit / level types and states).
     */
    public function qualifyLookup(Request $request)
    {
        $request->merge([
            'state'       => strtoupper(trim((string) $request->input('state'))),
            'street_type' => strtoupper(trim((string) $request->input('street_type'))),
        ]);

        $request->validate([
            'address'         => 'nullable|string|max:255',
            'street_number'   => ['nullable', 'regex:/^\d{1,5}[A-Za-z]{0,3}$/'],
            'street_name'     => 'nullable|string|max:' . VocusAddressStandard::MAX_STREET_NAME,
            'street_type'     => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && VocusAddressStandard::streetType($value) === null) {
                    $fail('The street type "' . $value . '" is not in the Vocus address standard. Use a standard abbreviation such as ST, RD, AV, DR, CR or PL.');
                }
            }],
            'suburb'          => 'nullable|string|max:' . VocusAddressStandard::MAX_SUBURB,
            'state'           => ['nullable', Rule::in(VocusAddressStandard::stateCodes())],
            'postcode'        => ['nullable', 'regex:/^\d{1,6}$/'],
            'unit_number'     => ['nullable', 'regex:/^\d{1,5}[A-Za-z]?$/'],
            'unit_type'       => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && VocusAddressStandard::unitType($value) === null) {
                    $fail('The unit type "' . $value . '" is not in the Vocus address standard (for example U, F, SE, SP, AT).');
                }
            }],
            'level_type'      => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && VocusAddressStandard::levelType($value) === null) {
                    $fail('The level type "' . $value . '" is not in the Vocus address standard (L, FL, UG, LG, B or M).');
                }
            }],
            'level_number'    => 'nullable|integer|min:0|max:9999',
            'property_name'   => 'nullable|string|max:40',
        ], [
            'street_number.regex' => 'The street number must be digits with an optional letter, for example 12 or 12A.',
            'unit_number.regex'   => 'The unit number must be digits with an optional single letter, for example 2 or 2A.',
            'postcode.regex'      => 'The postcode must be numeric.',
            'state.in'            => 'The state must be one of ' . implode(', ', VocusAddressStandard::stateCodes()) . '.',
        ]);

        try {
            if ($problem = VocusWsmClient::configurationProblem()) {
                return response()->json(['error' => $problem, 'addresses' => []], 422);
            }

            // Build DIR params from either structured or free-text input
            $params = $request->filled('street_name')
                ? VocusAddressParser::fromStructured($request->all())
                : VocusAddressParser::parse((string) $request->input('address', ''));

            if (empty($params['Main.StreetName'] ?? null)) {
                return response()->json([
                    'error' => 'Could not find a street name in that address. Enter it like "4 Example St, Suburb SA 5000" or use the manual form.',
                    'addresses' => [],
                ], 422);
            }

            $result = app(VocusWsmClient::class)->findAddresses($params);

            $payload = [
                'addresses'      => $result['addresses'],
                'transaction_id' => $result['transaction_id'],
                'searched'       => $this->describeSearch($params),
                // Read-only address parts carried through to the order form (LocationReference is the postcode).
                'postcode'       => (string) ($params['Main.PostCode'] ?? $params['Main.Postcode'] ?? ''),
                'suburb'         => (string) ($params['Main.Suburb'] ?? ''),
                'state'          => (string) ($params['Main.State'] ?? ''),
            ];

            if ($result['addresses'] === []) {
                $payload['message'] = 'Vocus returned no addresses for ' . $payload['searched']
                    . ' (' . count($result['attempts']) . ' search variant(s) tried). Check the street type, suburb and postcode, or try fewer details.';
            }

            return response()->json($payload);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->friendlyError($e, 'The address search failed.'), 'addresses' => []], 422);
        }
    }

    /** Human-readable summary of what was searched (no secrets). */
    private function describeSearch(array $p): string
    {
        $street = trim(implode(' ', array_filter([
            $p['Main.Street1stNumber'] ?? null, $p['Main.StreetName'] ?? null, $p['Main.StreetType'] ?? null,
        ])));

        return trim(implode(', ', array_filter([
            $street,
            trim(($p['Main.Suburb'] ?? '') . ' ' . ($p['Main.State'] ?? '') . ' ' . ($p['Main.PostCode'] ?? '')),
        ])));
    }

    /**
     * AJAX: run service qualification (FIBRE / Get / QUALIFY) against a Vocus DirectoryID.
     * The DirectoryID is either chosen from the address search or, for an NBN LOC ID
     * (LOC + 12 digits), typed directly: for NBN products the DirectoryID is the LOC ID
     * (Examples 4.1.1 / 4.1.2: "Vocus Address ID LOC000340636851"). AVC / CVC are not
     * sent: they only belong to transfers (Examples 4.2).
     */
    public function qualifyCheck(Request $request)
    {
        $request->merge(['loc_id' => strtoupper(trim((string) $request->input('loc_id')))]);

        $request->validate([
            'loc_id'       => ['nullable', 'string', 'regex:/^LOC\d{12}$/'],
            'directory_id' => ['required_without:loc_id', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'address_long' => 'nullable|string|max:255',
        ], [
            'loc_id.regex'                  => 'An NBN LOC ID is "LOC" followed by 12 digits, for example LOC001122334455.',
            'directory_id.required_without' => 'Choose an address or enter an NBN LOC ID.',
        ]);

        $directoryId = $request->filled('directory_id') ? (string) $request->input('directory_id') : (string) $request->input('loc_id');

        try {
            if ($problem = VocusWsmClient::configurationProblem()) {
                return response()->json(['error' => $problem], 422);
            }

            $result = app(VocusWsmClient::class)->qualifyAddress($directoryId);

            return response()->json([
                'directory_id'   => $directoryId,
                'address_long'   => $request->input('address_long'),
                'result'         => $result['params']['Result'] ?? null,
                'service_type'   => $result['params']['ServiceType'] ?? null,
                'service_class'  => $result['params']['ServiceClass'] ?? null,
                'zone'           => $result['params']['Zone'] ?? null,
                'copper_pairs'   => $result['params']['CopperPairRecord'] ?? [],
                'nbn_ports'      => $result['params']['NBNPortRecord'] ?? [],
                'params'         => $result['params'],
                'transaction_id' => $result['transaction_id'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->friendlyError($e, 'Qualification failed.')], 422);
        }
    }

    // -------------------------------------------------------------------------
    // New order / transfer
    // -------------------------------------------------------------------------

    private function orderFormData(Request $request): array
    {
        $postcode = (string) $request->get('location_reference', '');
        $address  = (string) $request->get('address_long', '');
        if (! preg_match('/^\d{4}$/', $postcode) && preg_match('/\b(\d{4})\s*$/', $address, $m)) {
            $postcode = $m[1];
        }

        return [
            'directory_id'       => (string) $request->get('directory_id', ''),
            'address_long'       => $address,
            'service_type'       => (string) $request->get('service_type', ''),
            'copper_pair_id'     => (string) $request->get('copper_pair_id', ''),
            'location_reference' => preg_match('/^\d{4}$/', $postcode) ? $postcode : '',
            'suburb'             => (string) $request->get('suburb', ''),
            'state'              => (string) $request->get('state', ''),
            'serviceLevels'      => self::SERVICE_LEVELS,
            'scopes'             => VocusOptions::enabledScopes(),
            'scopeLabels'        => VocusOptions::SCOPES,
            'realms'             => VocusOptions::realms(),
            'plans'              => VocusPlan::active()->orderBy('name')->get(),
            'vocusProblem'       => VocusWsmClient::configurationProblem(),
        ];
    }

    public function order(Request $request)
    {
        return view('admin.services.internet.order', $this->orderFormData($request));
    }

    private function isLayer2Order(Request $request, ?VocusPlan $plan): bool
    {
        return $request->input('scope') === 'NETWORK-CONNECT'
            || str_starts_with(strtoupper((string) $plan?->plan_id), 'NCL2');
    }

    /** Rules shared by the new-order and transfer forms (mirrors the hints shown on the forms). */
    private function sharedRules(): array
    {
        $realms = VocusOptions::realms();

        return [
            'vocus_plan_id'      => ['required', 'integer', Rule::exists('vocus_plans', 'id')->where('active', true)],
            'scope'              => ['required', Rule::in(VocusOptions::enabledScopes())],
            'customer_name'      => ['required', 'string', 'regex:' . VocusOptions::CONTACT_NAME_REGEX],
            'phone'              => ['required', 'regex:/^\d{10}$/'],
            'directory_id'       => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'location_reference' => ['required', 'regex:/^\d{4}$/'],
            'service_type'       => ['required', 'string', 'regex:/^[A-Za-z0-9 \/+_-]{2,20}$/'],
            // With no realm configured neither realm nor username can be sent.
            'realm'              => $realms === [] ? ['prohibited'] : ['required', Rule::in($realms)],
            'username'           => $realms === [] ? ['prohibited'] : ['nullable', 'regex:' . VocusOptions::USERNAME_REGEX],
            'service_level'      => ['nullable', Rule::in(self::SERVICE_LEVELS)],
            'address_long'       => 'nullable|string|max:255',
            'client_id'          => 'nullable|exists:clients,id',
            'notes'              => 'nullable|string|max:500',
        ];
    }

    private const SHARED_MESSAGES = [
        'vocus_plan_id.required'   => 'Choose a plan.',
        'vocus_plan_id.exists'     => 'That plan is not available. Choose an active plan.',
        'scope.in'                 => 'That service scope is not enabled in Settings.',
        'customer_name.regex'      => 'Contact name must be a first and last name, 4 to 39 characters (letters, digits and spaces).',
        'phone.regex'              => 'Contact phone must be 10 digits.',
        'location_reference.regex' => 'The postcode (LocationReference) must be 4 digits. Use the address from the qualify step.',
        'realm.required'           => 'Choose the NBN domain (realm).',
        'realm.in'                 => 'That realm is not configured in Settings.',
        'realm.prohibited'         => 'No PPPoE realm is configured in Settings.',
        'username.prohibited'      => 'No PPPoE realm is configured in Settings.',
        'username.regex'           => 'The username must be 4 to 22 characters: letters, digits or underscore (the realm is added for you).',
        'password.regex'           => 'The password may only contain letters, digits, dash and dot (2 to 50 characters).',
        'cvc_id.required'          => 'The CVC (AggCVC) ID is required for Layer 2 / Network-Connect (NCL2) transfers.',
    ];

    /** Resolve the chosen plan and make sure it is valid for the chosen scope. */
    private function planFor(array $validated): VocusPlan
    {
        $plan = VocusPlan::active()->findOrFail((int) $validated['vocus_plan_id']);
        if (! $plan->appliesToScope($validated['scope'])) {
            throw ValidationException::withMessages(['vocus_plan_id' => 'That plan is not available for the ' . $validated['scope'] . ' scope.']);
        }

        return $plan;
    }

    /** Parameters common to NEW and TRANSFER (Examples 4.1.4 / 4.2.4). Password is added by the caller. */
    private function baseParams(array $validated, string $serviceId): array
    {
        $params = [
            'ServiceID'         => $serviceId,
            'CustomerName'      => $validated['customer_name'],
            'Phone'             => $validated['phone'],
            'DirectoryID'       => $validated['directory_id'],
            'LocationReference' => $validated['location_reference'],
            'ServiceType'       => $validated['service_type'],
            'ServiceLevel'      => $validated['service_level'] ?? 'STANDARD',
        ];

        if (! empty($validated['realm'])) {
            $params['Realm'] = $validated['realm'];
            if ($username = VocusOptions::composeUsername($validated['username'] ?? null, $validated['realm'])) {
                $params['Username'] = $username;
            }
        }

        return $params;
    }

    public function orderStore(Request $request)
    {
        $validated = $request->validate($this->sharedRules() + [
            'copper_pair_id' => 'nullable|string|max:15',
            'password'       => ['nullable', 'regex:' . VocusOptions::PASSWORD_REGEX],
        ], self::SHARED_MESSAGES);

        $plan      = $this->planFor($validated);
        $serviceId = VocusOptions::generateServiceId($validated['directory_id']);

        $params = $this->baseParams($validated, $serviceId);
        foreach (['copper_pair_id' => 'CopperPairID', 'password' => 'Password'] as $field => $param) {
            if (! empty($validated[$field])) {
                $params[$param] = $validated[$field];
            }
        }

        // AVC / CVC are not part of a NEW order (Examples 4.1.4).
        return $this->placeOrder($request, $validated, $plan, $serviceId, $params, 'NEW', 'internet.order', 'internet-order', 'Order');
    }

    /**
     * Insert the local record first (so a database failure can never leave an
     * order at Vocus with no local trace), place the order, then record the
     * transaction. If Vocus rejects the order the record is removed again.
     * The record stays INACTIVE with transaction state QUEUED until polled.
     * The plan id and the cost / sell price are snapshotted on the record.
     */
    private function placeOrder(Request $request, array $validated, VocusPlan $plan, string $serviceId, array $params, string $orderType, string $auditAction, string $auditFunction, string $label)
    {
        // Never flash the PPPoE password back into the session.
        $input = $request->except(['password', '_token']);

        if ($problem = VocusWsmClient::configurationProblem()) {
            return back()->withInput($input)->with('error', $problem);
        }

        $service = InternetService::create([
            'vocus_service_id'   => $serviceId,
            'client_id'          => $validated['client_id'] ?? null,
            'plan_id'            => $plan->plan_id,
            'vocus_plan_id'      => $plan->id,
            'cost_price'         => $plan->cost_price,
            'sell_price'         => $plan->sell_price,
            'service_scope'      => $validated['scope'],
            'service_status'     => 'INACTIVE',
            'service_type'       => $validated['service_type'],
            'order_type'         => $orderType,
            'customer_name'      => $validated['customer_name'],
            'phone'              => $validated['phone'],
            'directory_id'       => $validated['directory_id'],
            'location_reference' => $validated['location_reference'],
            'realm'              => $validated['realm'] ?? null,
            'username'           => $params['Username'] ?? null,
            'service_level'      => $validated['service_level'] ?? 'STANDARD',
            'copper_pair_id'     => $validated['copper_pair_id'] ?? null,
            'avc_id'             => $validated['avc_id'] ?? null,
            'cvc_id'             => $validated['cvc_id'] ?? null,
            'address_long'       => $validated['address_long'] ?? null,
            'notes'              => $validated['notes'] ?? null,
        ]);

        try {
            $response = app(VocusWsmClient::class)->orderService($plan->plan_id, $validated['scope'], $params);
        } catch (\Throwable $e) {
            $service->delete(); // nothing was ordered
            Log::warning('Vocus order rejected', ['service_id' => $serviceId, 'order_type' => $orderType, 'message' => $e->getMessage()]);

            return back()->withInput($input)->with('error', "{$label} failed: " . $this->friendlyError($e, 'Vocus could not be reached.'));
        }

        try {
            $service->update([
                'last_transaction_id'    => $response['transaction_id'],
                'last_transaction_state' => 'QUEUED',
            ]);
        } catch (\Throwable $e) {
            Log::error('Vocus order placed but the transaction id could not be saved', [
                'service_id' => $serviceId, 'transaction_id' => $response['transaction_id'],
            ]);
        }

        AuditLogger::logSystem($auditAction, "NBN service {$label} submitted: {$serviceId}.", [
            'service'  => 'vocus',
            'function' => $auditFunction,
        ], ['new_values' => [
            'vocus_service_id' => $serviceId, 'order_type' => $orderType, 'transaction_id' => $response['transaction_id'],
            'plan_id' => $plan->plan_id, 'cost_price' => (string) $plan->cost_price, 'sell_price' => (string) $plan->sell_price,
        ]]);

        return redirect()->route('admin.services.internet.show', $service)
            ->with('status', "{$label} submitted. Service ID {$serviceId}, transaction ID {$response['transaction_id']}. Status will update as Vocus processes it.");
    }

    // -------------------------------------------------------------------------
    // Transfer
    // -------------------------------------------------------------------------

    public function transfer(Request $request)
    {
        return view('admin.services.internet.transfer', $this->orderFormData($request) + [
            'avc_id' => (string) $request->get('avc_id', ''),
            'cvc_id' => (string) $request->get('cvc_id', ''),
        ]);
    }

    /**
     * Transfer order. Examples 4.2.4: "Since this is a TRANSFERS order, client need
     * to specify the OrderType as TRANSFER and also the CopperPairID to move", with
     * ServiceID, OrderType, CustomerName, Phone, CVCID (AggCVC), LocationReference,
     * DirectoryID, AVCID, Realm, ServiceLevel, ServiceType, CopperPairID and CADate
     * (YYYYMMDD). Layer 2 (NCL2) additionally needs the AVC and CVC IDs.
     */
    public function transferStore(Request $request)
    {
        $layer2 = $this->isLayer2Order($request, VocusPlan::find((int) $request->input('vocus_plan_id')));

        $validated = $request->validate($this->sharedRules() + [
            'copper_pair_id' => 'required|string|max:15',
            'ca_date'        => 'required|date_format:Y-m-d',
            'avc_id'         => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'cvc_id'         => [Rule::requiredIf($layer2), 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], self::SHARED_MESSAGES);

        $plan      = $this->planFor($validated);
        $serviceId = VocusOptions::generateServiceId($validated['directory_id']);

        $params = ['OrderType' => 'TRANSFER'] + $this->baseParams($validated, $serviceId) + [
            'CopperPairID' => $validated['copper_pair_id'],
            // CADate must be YYYYMMDD format for Vocus
            'CADate'       => str_replace('-', '', $validated['ca_date']),
        ];

        foreach (['avc_id' => 'AVCID', 'cvc_id' => 'CVCID'] as $field => $param) {
            if (! empty($validated[$field])) {
                $params[$param] = $validated[$field];
            }
        }

        return $this->placeOrder($request, $validated, $plan, $serviceId, $params, 'TRANSFER', 'internet.transfer', 'internet-transfer', 'Transfer');
    }

    // -------------------------------------------------------------------------
    // Poll transaction status (AJAX)
    // -------------------------------------------------------------------------

    public function pollStatus(Request $request, InternetService $service)
    {
        if (!$service->last_transaction_id) {
            return response()->json(['error' => 'No transaction ID on record.'], 422);
        }

        if ($problem = VocusWsmClient::configurationProblem()) {
            return response()->json(['error' => $problem, 'stop' => true], 422);
        }

        // Cool-down: every poll is a TCAS call (two on SUCCESS) and Vocus warns that
        // excessive polling triggers an automatic ban (Examples 4.1.6).
        if ($service->isPending() && $service->isPollCoolingDown()) {
            $wait = (int) config('services.vocus.poll_cooldown_seconds', 300);

            return response()->json([
                'transaction_state' => $service->last_transaction_state,
                'cooldown'          => true,
                'last_polled_at'    => $service->last_polled_at?->toIso8601String(),
                'message'           => 'Checked recently; Vocus asks for polling no more often than every ' . max(1, intdiv($wait, 60)) . ' minute(s).',
                'fault_code'        => $service->last_fault_code,
                'fault_message'     => $service->last_fault_message,
            ]);
        }

        try {
            $vocus  = app(VocusWsmClient::class);
            $result = VocusWsmClient::normalizeTransactionResult($vocus->pollTransaction($service->last_transaction_id));
            $state  = $result['state'];

            $service->last_polled_at = now();

            if ($state) {
                $service->last_transaction_state = $state;

                if ($state === 'FAILED') {
                    // Persist the TCAS faultcode / faultmessage so the details survive the page reload.
                    $service->last_fault_code    = $result['fault_code'] ? mb_substr($result['fault_code'], 0, 40) : null;
                    $service->last_fault_message = $result['fault_message'] ? mb_substr($result['fault_message'], 0, 500) : null;
                } else {
                    $service->last_fault_code = $service->last_fault_message = null;
                }

                // SUCCESS may be the result of a suspend or cancel, so the new
                // service status comes from the live record, never assumed ACTIVE.
                if ($state === 'SUCCESS' && $service->vocus_service_id) {
                    try {
                        $live = $vocus->getService($service->vocus_service_id)['params'] ?? [];
                        if ($new = VocusWsmClient::resolveServiceStatus($state, $live['ServiceStatus'] ?? null)) {
                            $service->service_status = $new;
                        }
                        if ($live) {
                            $service->applyLiveParams($live);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Vocus status refresh after transaction failed', [
                            'service_id' => $service->vocus_service_id,
                            'message'    => $e->getMessage(),
                        ]);
                    }
                }
            }
            $service->save();

            return response()->json([
                'transaction_state' => $service->last_transaction_state,
                'final'             => $result['final'],
                'params'            => $result['params'],
                'fault_code'        => $result['fault_code'],
                'fault_message'     => $result['fault_message'],
            ]);
        } catch (VocusWsmException $e) {
            $expired = in_array($e->errorCode, ['TCAS-4300', 'TCAS-4310'], true);

            // Unknown / expired transaction: nothing will ever change, stop the pending state.
            if ($expired) {
                $service->update([
                    'last_transaction_state' => $e->errorCode === 'TCAS-4310' ? 'EXPIRED' : 'UNKNOWN',
                    'last_fault_code'        => $e->errorCode,
                    'last_fault_message'     => mb_substr($e->getMessage(), 0, 500),
                    'last_polled_at'         => now(),
                ]);
            }

            return response()->json([
                'error'      => $e->getMessage(),
                'error_code' => $e->errorCode,
                // The UI must stop polling and show the error for account-level problems.
                'stop'       => $e->isAccountLevel() || $expired,
                'reload'     => $expired,
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->friendlyError($e, 'The status check failed.'), 'stop' => true], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Assign client
    // -------------------------------------------------------------------------

    public function assignClient(Request $request, InternetService $service)
    {
        $request->validate(['client_id' => 'nullable|exists:clients,id']);

        $service->update(['client_id' => $request->client_id ?: null]);

        AuditLogger::logSystem('internet.assign_client', "Client assigned to internet service {$service->vocus_service_id}.", [
            'service'  => 'vocus',
            'function' => 'internet-assign-client',
        ], ['new_values' => ['client_id' => $request->client_id]]);

        return back()->with('status', 'Client assignment updated.');
    }

    // -------------------------------------------------------------------------
    // Suspend / Resume / Cancel
    // -------------------------------------------------------------------------

    public function setStatus(Request $request, InternetService $service)
    {
        $request->validate(['status' => 'required|in:ACTIVE,SUSPEND,INACTIVE']);

        // Suspend / Resume are "only valid for Layer 3 / Reseller-Connect" (Examples 4.1.9).
        if (in_array($request->status, ['SUSPEND', 'ACTIVE'], true) && ! $service->supportsSuspendResume()) {
            return back()->with('error', 'Suspend and Resume are only supported for Layer 3 / Reseller-Connect services, not Layer 2 (Network-Connect / NCL2).');
        }

        if (! $service->canChangeStatus()) {
            return back()->with('error', 'A Vocus transaction is still in progress for this service. Wait for it to finish before changing the status.');
        }

        if ($problem = VocusWsmClient::configurationProblem()) {
            return back()->with('error', $problem);
        }

        try {
            $vocus    = app(VocusWsmClient::class);
            $response = $vocus->setServiceStatus($service->vocus_service_id, $request->status);

            $service->update([
                'last_transaction_id'    => $response['transaction_id'],
                'last_transaction_state' => 'QUEUED',
                'last_fault_code'        => null,
                'last_fault_message'     => null,
                'last_polled_at'         => null,
            ]);

            AuditLogger::logSystem('internet.set_status', "Service {$service->vocus_service_id} status change to {$request->status} submitted.", [
                'service'  => 'vocus',
                'function' => 'internet-set-status',
            ], ['new_values' => ['status' => $request->status, 'transaction_id' => $response['transaction_id']]]);

            $label = match ($request->status) {
                'ACTIVE'   => 'Resume',
                'SUSPEND'  => 'Suspend',
                'INACTIVE' => 'Cancellation',
            };

            return back()->with('status', "{$label} submitted. Transaction ID: {$response['transaction_id']}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Request failed: ' . $this->friendlyError($e, 'Vocus could not be reached.'));
        }
    }

    // -------------------------------------------------------------------------
    // Diagnostics
    // -------------------------------------------------------------------------

    public function diagnostic(Request $request, InternetService $service)
    {
        $request->validate(['type' => 'required|in:AUTH-LOG,DISCONNECT']);

        // The AUTH-LOG / DISCONNECT plan form for FIBRE is not documented (the OPER
        // catalogue is not available), so never invent a plan: use the service's own.
        $planId = $service->plan_id;
        if (! $planId) {
            return back()->with('error', 'This service has no plan on record yet. Refresh it from Vocus (Sync) before running diagnostics.');
        }

        if ($problem = VocusWsmClient::configurationProblem()) {
            return back()->with('error', $problem);
        }

        try {
            $vocus = app(VocusWsmClient::class);

            $response = match ($request->type) {
                'AUTH-LOG'   => $vocus->getAuthLog($service->vocus_service_id, $planId),
                'DISCONNECT' => $vocus->disconnectSession($service->vocus_service_id, $planId),
            };

            $result = $request->type === 'AUTH-LOG'
                ? ['records' => $response['records'] ?? []]
                : ['transaction_id' => $response['transaction_id'] ?? null];

            InternetServiceDiagnostic::create([
                'internet_service_id' => $service->id,
                'diagnostic_type'     => $request->type,
                'transaction_id'      => $response['transaction_id'] ?? null,
                'transaction_state'   => $response['params']['TransactionState'] ?? null,
                'result'              => $result,
                'run_by_user_id'      => Auth::id(),
                'created_at'          => now(),
            ]);

            AuditLogger::logSystem('internet.diagnostic', "Diagnostic {$request->type} run on {$service->vocus_service_id}.", [
                'service'  => 'vocus',
                'function' => 'internet-diagnostic',
            ], []);

            return back()->with('status', "Diagnostic '{$request->type}' completed.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Diagnostic failed: ' . $this->friendlyError($e, 'Vocus could not be reached.'));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Best-effort free-text parse (see VocusAddressParser). */
    protected function parseSimpleAddress(string $address): array
    {
        return VocusAddressParser::parse($address);
    }
}
