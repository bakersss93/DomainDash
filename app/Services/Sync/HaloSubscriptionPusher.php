<?php

namespace App\Services\Sync;

use App\Models\Client;
use App\Models\InternetService;
use App\Services\Halo\HaloBillingSettings;
use App\Services\Halo\HaloPsaClient;

/**
 * Bills one Vocus internet service in HaloPSA (billing model A):
 *
 *   plan -> Halo Item
 *   service -> client subscription (POST /api/SoftwareLicence, found again by a marker in its notes)
 *           -> line on the client's recurring invoice (POST /api/RecurringInvoice/Lines | updatelines)
 *
 * Idempotent: ids are stored on the service (halo_subscription_id / halo_recurring_line_id); an unchanged payload is
 * skipped through halo_sync_hash before any remote call; a cancelled service gets an end date / is deactivated, never
 * deleted. Recurring invoices are never created: with none to use the service is marked with a clear error.
 */
class HaloSubscriptionPusher
{
    /** @var array<int,int|string> per haloClientId: the resolved recurring invoice id, or an error message */
    private array $invoiceCache = [];

    /**
     * @return string created|updated|ended|skipped
     */
    public function push(InternetService $service, HaloPsaClient $halo, HaloBillingSettings $cfg, bool $force = false): string
    {
        if (! $cfg->enabled()) {
            throw new SyncItemFailed('Billing sync is switched off in Settings → HaloPSA → Billing.');
        }

        $client = $service->client;
        if (! $client) {
            throw new SyncItemFailed('No client assigned.');
        }
        if (! $client->halopsa_reference) {
            throw new SyncItemFailed("Client {$this->clientName($client)} is not linked to HaloPSA.");
        }
        $haloClientId = (int) $client->halopsa_reference;

        $plan = $service->vocusPlan;
        if (! $plan || ! $plan->halo_item_id) {
            throw new SyncItemFailed('Plan ' . ($plan?->plan_id ?? ($service->plan_id ?: '(none)')) . ' has no Halo item. Link one in Settings → Vocus → Plans.');
        }

        $status = strtoupper((string) $service->service_status);
        $hasBilling = $service->halo_subscription_id || $service->halo_recurring_line_id;
        $ended = $status === 'INACTIVE' && ! $service->isPending();

        if ($status === 'INACTIVE' && $service->isPending()) {
            return $this->skip($service, 'Awaiting Vocus provisioning.');
        }
        if ($ended && ! $hasBilling) {
            return $this->skip($service, null); // never billed, nothing to end
        }

        $invoiceId = $cfg->syncLines ? $this->recurringInvoiceId($client, $haloClientId, $halo, $cfg, $service) : null;

        $sell = (float) ($service->sell_price ?? $plan->sell_price);
        $cost = (float) ($service->cost_price ?? $plan->cost_price);
        $start = $service->created_at?->toDateString() ?? now()->toDateString();
        $name = $this->subscriptionName($service, (string) $plan->name);

        $hash = hash('sha256', json_encode([
            $haloClientId, (int) $plan->halo_item_id, $name, round($sell, 2), round($cost, 2), $ended, $start,
            $invoiceId, $cfg->fingerprint(), $this->reference($service),
        ]));
        if (! $force && $service->halo_sync_hash === $hash && ! $service->halo_sync_error && $hasBilling) {
            return 'skipped';
        }

        $endDate = $ended ? now()->format('Y-m-d\T00:00:00') : null;
        $subscriptionId = $service->halo_subscription_id ? (int) $service->halo_subscription_id : null;
        $outcome = $ended ? 'ended' : 'updated';

        if ($cfg->syncSubscriptions) {
            $payload = [
                'client_id' => $haloClientId,
                'name' => $name,
                'item_id' => (int) $plan->halo_item_id,
                'count' => 1,
                'price' => round($sell, 2),
                'purchase_price' => round($cost, 2),
                'start_date' => $start . 'T00:00:00',
                'notes' => $this->notes($service, (string) $plan->plan_id),
                'is_active' => ! $ended,
                'end_date' => $endDate,
            ];

            if (! $subscriptionId) {
                $existing = $halo->findSubscriptionByMarker($haloClientId, (string) $service->vocus_service_id);
                $subscriptionId = $existing ? (int) $existing['id'] : null;
            }

            if ($subscriptionId) {
                $halo->updateSubscription($subscriptionId, $payload);
            } else {
                $saved = $halo->createSubscription($payload);
                $subscriptionId = (int) ($saved['id'] ?? 0);
                if ($subscriptionId <= 0) {
                    // Halo accepted the POST but did not echo an id: look it up before anything can be created twice.
                    $found = $halo->findSubscriptionByMarker($haloClientId, (string) $service->vocus_service_id);
                    $subscriptionId = $found ? (int) $found['id'] : 0;
                }
                if ($subscriptionId <= 0) {
                    throw new SyncItemFailed('HaloPSA did not return the new subscription id.');
                }
                $outcome = 'created';
            }

            // Persist at once: if the line step fails, the next run updates instead of creating a duplicate.
            $service->forceFill(['halo_subscription_id' => $subscriptionId])->save();
        }

        if ($cfg->syncLines && $invoiceId) {
            $lineId = $this->syncLine($service, $halo, $cfg, (int) $invoiceId, $subscriptionId, (int) $plan->halo_item_id, $name, $sell, $cost, $start, $endDate, $ended);
            $service->forceFill(['halo_recurring_invoice_id' => $invoiceId, 'halo_recurring_line_id' => $lineId])->save();
        }

        $service->forceFill([
            'halo_synced_at' => now(),
            'halo_sync_error' => null,
            'halo_sync_hash' => $hash,
        ])->save();

        return $outcome;
    }

    private function syncLine(InternetService $service, HaloPsaClient $halo, HaloBillingSettings $cfg, int $invoiceId, ?int $subscriptionId, int $itemId, string $name, float $sell, float $cost, string $start, ?string $endDate, bool $ended): int
    {
        $line = [
            '_itemid' => $itemId,
            'item_shortdescription' => $name,
            'qty_order' => 1,
            'unit_price' => round($sell, 2),
            'unit_cost' => round($cost, 2),
            'startdate' => $start . 'T00:00:00',
            'enddate' => $endDate,
            'isinactive' => $ended,
        ];
        if ($subscriptionId) {
            $line['quantity_subscriptions'] = [[
                'licence_id' => $subscriptionId,
                'use_linked_subscription_price' => false,
                'use_linked_subscription_cost' => false,
            ]];
        }
        if (($type = config('integrations.halo.recurring_quantity_type')) !== null && $type !== '') {
            $line['recurring_invoice_quantity_type'] = (int) $type;
        }

        $lineId = (int) $service->halo_recurring_line_id;
        $sameInvoice = (int) $service->halo_recurring_invoice_id === $invoiceId;

        // Find the line again when its id is unknown, belongs to another invoice, or vanished in Halo.
        $existing = null;
        foreach ($halo->getRecurringInvoiceLines($invoiceId) as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($lineId && $sameInvoice && $rowId === $lineId) {
                $existing = $rowId;
                break;
            }
            if ($subscriptionId) {
                foreach ((array) ($row['quantity_subscriptions'] ?? []) as $q) {
                    if ((int) ($q['licence_id'] ?? 0) === $subscriptionId && $rowId > 0) {
                        $existing = $rowId;
                        break 2;
                    }
                }
            }
        }

        if ($existing) {
            $halo->updateRecurringInvoiceLine($invoiceId, $existing, $line);

            return $existing;
        }

        $saved = $halo->addRecurringInvoiceLine($invoiceId, $line);
        $newId = (int) ($saved['id'] ?? 0);
        if ($newId <= 0) {
            throw new SyncItemFailed('HaloPSA did not return the new recurring invoice line id.');
        }

        return $newId;
    }

    /**
     * The recurring invoice to bill on: the one picked on the client; else (strategy "contract") the single recurring
     * invoice of the client's active contract(s) matching the type / ref filter. Anything else is a clear error.
     */
    private function recurringInvoiceId(Client $client, int $haloClientId, HaloPsaClient $halo, HaloBillingSettings $cfg, InternetService $service): int
    {
        if ($client->halo_recurring_invoice_id) {
            return (int) $client->halo_recurring_invoice_id;
        }

        $name = $this->clientName($client);
        $select = "No recurring invoice for client {$name} - select one (Clients → edit → HaloPSA recurring invoice).";

        if ($cfg->strategy === HaloBillingSettings::STRATEGY_CLIENT) {
            throw new SyncItemFailed($select);
        }

        if (! array_key_exists($haloClientId, $this->invoiceCache)) {
            $this->invoiceCache[$haloClientId] = $this->resolveFromContracts($name, $haloClientId, $halo, $cfg, $select);
        }

        $found = $this->invoiceCache[$haloClientId];
        if (is_string($found)) {
            throw new SyncItemFailed($found);
        }

        return $found;
    }

    private function resolveFromContracts(string $name, int $haloClientId, HaloPsaClient $halo, HaloBillingSettings $cfg, string $select): int|string
    {
        $contracts = array_values(array_filter(
            $halo->listClientContracts($haloClientId, $cfg->contractTypeId),
            fn ($c) => $cfg->contractRefMatches($c['ref'])
        ));
        if ($contracts === []) {
            return "No active contract found for client {$name}"
                . ($cfg->contractRef !== '' ? " matching \"{$cfg->contractRef}\"" : '') . ' - select a recurring invoice on the client instead.';
        }

        $invoices = [];
        foreach ($contracts as $contract) {
            foreach ($halo->listClientRecurringInvoices($haloClientId, $contract['id']) as $invoice) {
                $invoices[$invoice['id']] = $invoice;
            }
        }

        if (count($invoices) === 1) {
            return (int) array_key_first($invoices);
        }

        return $invoices === []
            ? $select
            : "Client {$name} has " . count($invoices) . ' matching recurring invoices - select one (Clients → edit → HaloPSA recurring invoice).';
    }

    private function skip(InternetService $service, ?string $reason): string
    {
        if ($service->halo_sync_error !== null) {
            $service->forceFill(['halo_sync_error' => null])->save();
        }

        return 'skipped';
    }

    private function clientName(Client $client): string
    {
        return (string) ($client->business_name ?: $client->name ?: ('#' . $client->id));
    }

    private function reference(InternetService $service): string
    {
        return (string) ($service->address_long ?: $service->username ?: $service->vocus_service_id);
    }

    private function subscriptionName(InternetService $service, string $planName): string
    {
        return mb_substr(trim($planName . ' - ' . $this->reference($service)), 0, 200);
    }

    /** Human notes plus the idempotency marker the subscription is found by. */
    private function notes(InternetService $service, string $planId): string
    {
        return implode("\n", array_filter([
            sprintf(HaloPsaClient::SUBSCRIPTION_MARKER, $service->vocus_service_id),
            'Vocus service: ' . $service->vocus_service_id,
            'Plan: ' . $planId,
            $service->username ? 'Username: ' . $service->username : null,
            $service->address_long ? 'Address: ' . $service->address_long : null,
            $service->reference ? 'Reference: ' . $service->reference : null,
        ]));
    }
}
