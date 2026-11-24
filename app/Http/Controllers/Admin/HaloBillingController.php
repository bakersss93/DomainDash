<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\VocusPlan;
use App\Services\AuditLogger;
use App\Services\Halo\HaloBillingSettings;
use App\Services\Halo\HaloPsaClient;
use App\Services\Sync\QueueHealth;
use App\Services\Sync\SyncDispatcher;
use App\Support\SyncErrorSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Halo billing endpoints (Administrator area; sync actions also need the sync.run permission on the route):
 *  - item search proxy for the plan → Halo item picker (debounced in the browser, cached 60 s server side)
 *  - link / unlink a Vocus plan to a Halo item
 *  - a client's recurring invoices and the per-client choice
 *  - "Sync to Halo" for one service and for all services (queued, live panel)
 * Only ids, names and prices reach the browser, never raw Halo payloads or credentials.
 */
class HaloBillingController extends Controller
{
    // ----- item picker --------------------------------------------------------------------

    public function items(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:200',
            'include_inactive' => 'nullable|boolean',
            'refresh' => 'nullable|boolean',
        ]);

        return $this->guard(function () use ($data) {
            if ($problem = HaloPsaClient::configurationProblem()) {
                return $this->fail($problem);
            }

            return response()->json(app(HaloPsaClient::class)->searchItems(
                (string) ($data['q'] ?? ''),
                (bool) ($data['include_inactive'] ?? false),
                (int) ($data['page'] ?? 1),
                25,
                (bool) ($data['refresh'] ?? false),
            ));
        });
    }

    /** PUT {halo_item_id: int|null}. The name is read from Halo, never trusted from the browser. */
    public function linkItem(Request $request, VocusPlan $plan, SyncDispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate(['halo_item_id' => 'present|nullable|integer|min:1']);
        $old = $plan->toFormArray();

        if ($data['halo_item_id'] === null) {
            $plan->update(['halo_item_id' => null, 'halo_item_name' => null]);
            $action = 'unlinked';
        } else {
            $result = $this->guard(function () use ($data, $plan) {
                if ($problem = HaloPsaClient::configurationProblem()) {
                    return $this->fail($problem);
                }
                $item = app(HaloPsaClient::class)->getItem((int) $data['halo_item_id']);
                if (! $item) {
                    return $this->fail('That HaloPSA item was not found.');
                }
                $plan->update(['halo_item_id' => $item['id'], 'halo_item_name' => mb_substr($item['name'], 0, 255)]);

                return response()->json([]);
            });
            if ($result->getStatusCode() >= 400) {
                return $result;
            }
            $action = 'linked';
        }

        AuditLogger::logSystem("vocus.plan.halo_item_{$action}", "Vocus plan {$plan->plan_id} {$action} to a HaloPSA item.", ['service' => 'halo', 'function' => 'settings-plan-item'], [
            'old_values' => ['halo_item_id' => $old['halo_item_id'], 'halo_item_name' => $old['halo_item_name']],
            'new_values' => ['halo_item_id' => $plan->halo_item_id, 'halo_item_name' => $plan->halo_item_name],
        ]);

        $queued = $this->requeueServices(InternetService::where('vocus_plan_id', $plan->id)->whereNotNull('client_id')->limit(500)->get(), $dispatcher);

        return response()->json(['plan' => $plan->fresh()->toFormArray(), 'queued_services' => $queued]);
    }

    // ----- client recurring invoice --------------------------------------------------------

    public function clientRecurringInvoices(Client $client): JsonResponse
    {
        return $this->guard(function () use ($client) {
            if ($problem = HaloPsaClient::configurationProblem()) {
                return $this->fail($problem);
            }
            if (! $client->halopsa_reference) {
                return $this->fail('This client is not linked to HaloPSA yet.');
            }

            $invoices = array_map(
                fn ($i) => ['id' => $i['id'], 'name' => $i['name'], 'contract_ref' => $i['contract_ref']],
                app(HaloPsaClient::class)->listClientRecurringInvoices((int) $client->halopsa_reference)
            );

            return response()->json([
                'invoices' => $invoices,
                'selected_id' => $client->halo_recurring_invoice_id ? (int) $client->halo_recurring_invoice_id : null,
                'selected_name' => $client->halo_recurring_invoice_name,
            ]);
        });
    }

    /** PUT {recurring_invoice_id: int|null}: must be one of this client's recurring invoices in Halo. */
    public function setClientRecurringInvoice(Request $request, Client $client, SyncDispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate(['recurring_invoice_id' => 'present|nullable|integer|min:1']);

        if ($data['recurring_invoice_id'] === null) {
            $client->forceFill(['halo_recurring_invoice_id' => null, 'halo_recurring_invoice_name' => null])->save();
        } else {
            $result = $this->guard(function () use ($data, $client) {
                if (! $client->halopsa_reference) {
                    return $this->fail('This client is not linked to HaloPSA yet.');
                }
                $match = collect(app(HaloPsaClient::class)->listClientRecurringInvoices((int) $client->halopsa_reference))
                    ->firstWhere('id', (int) $data['recurring_invoice_id']);
                if (! $match) {
                    return $this->fail('That recurring invoice does not belong to this client in HaloPSA.');
                }
                $client->forceFill(['halo_recurring_invoice_id' => $match['id'], 'halo_recurring_invoice_name' => mb_substr($match['name'], 0, 255)])->save();

                return response()->json([]);
            });
            if ($result->getStatusCode() >= 400) {
                return $result;
            }
        }

        AuditLogger::logSystem('client.halo_recurring_invoice', "HaloPSA recurring invoice changed for client {$client->id}.", ['service' => 'halo', 'function' => 'client-recurring-invoice'], [
            'new_values' => ['client_id' => $client->id, 'halo_recurring_invoice_id' => $client->halo_recurring_invoice_id],
        ]);

        $queued = $this->requeueServices(InternetService::where('client_id', $client->id)->limit(500)->get(), $dispatcher);

        return response()->json([
            'selected_id' => $client->halo_recurring_invoice_id ? (int) $client->halo_recurring_invoice_id : null,
            'selected_name' => $client->halo_recurring_invoice_name,
            'queued_services' => $queued,
        ]);
    }

    // ----- sync actions ---------------------------------------------------------------------

    public function syncService(Request $request, InternetService $service, SyncDispatcher $dispatcher)
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }
        if (! $service->client_id) {
            return $this->refused($request, 'Assign a client to this service first.', 422, $service);
        }

        $run = $dispatcher->pushHaloInternetServices([$service->id], true, $request->user()?->id);

        return $this->queued($request, $run, 'Sync to HaloPSA queued.', $service);
    }

    public function syncAll(Request $request, SyncDispatcher $dispatcher)
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $run = $dispatcher->pushHaloInternetServices(null, true, $request->user()?->id);

        return $this->queued($request, $run, 'Sync of internet services to HaloPSA queued. Progress is shown below.', null);
    }

    // ----- helpers --------------------------------------------------------------------------

    private function refusal(Request $request)
    {
        if ($problem = HaloPsaClient::configurationProblem()) {
            return $this->refused($request, $problem, 422);
        }
        if (! HaloBillingSettings::fromSettings()->enabled()) {
            return $this->refused($request, 'Billing sync is switched off. Enable it in Settings → HaloPSA → Billing.', 422);
        }

        return null;
    }

    private function queued(Request $request, $run, string $message, ?InternetService $service)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'queued' => true,
                'message' => $message,
                'runs' => [$run->toStatusArray()],
                'worker' => app(QueueHealth::class)->status(),
            ], 202);
        }

        return $service
            ? redirect()->route('admin.services.internet.show', $service)->with('status', $message)
            : redirect()->route('admin.services.internet')->with('status', $message);
    }

    private function refused(Request $request, string $message, int $status, ?InternetService $service = null)
    {
        if ($request->expectsJson()) {
            return response()->json(['queued' => false, 'error' => $message], $status);
        }

        return $service
            ? redirect()->route('admin.services.internet.show', $service)->with('error', $message)
            : redirect()->route('admin.services.internet')->with('error', $message);
    }

    /** Debounced re-sync of the given services (their plan item / client invoice changed). */
    private function requeueServices($services, SyncDispatcher $dispatcher): int
    {
        $n = 0;
        foreach ($services as $service) {
            if ($dispatcher->queueInternetServiceBilling($service)) {
                $n++;
            }
        }

        return $n;
    }

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
