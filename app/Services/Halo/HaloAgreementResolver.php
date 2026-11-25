<?php

namespace App\Services\Halo;

use App\Models\Client;
use App\Models\InternetService;
use App\Services\Sync\SyncItemFailed;

/**
 * Which Halo recurring invoice a service bills on. One agreement (ClientContract) per service is the normal case, so the
 * agreement is found by the billing rule of the item type (Settings → HaloPSA → Billing → Agreements):
 *
 *  1. the recurring invoice picked on the service itself (internet_services.halo_override_invoice_id) always wins;
 *  2. strategy "client": only the invoice pinned on the client;
 *  3. strategy "contract": the client's active agreements filtered by Contract Type + Agreement Subtype + Agreement
 *     Reference pattern ({plan} {client_code} {service_id} ... are filled from the service). Exactly one left: use it.
 *     Several left: the one whose reference contains this service's identifiers (service id, username, AVC, LOC, then
 *     plan name / id); still not exactly one: an error that lists the candidates;
 *  4. the invoice pinned on the client is the fallback when the rule finds nothing usable.
 *
 * Reads only (GET /ClientContract, GET /RecurringInvoice); the client's data is fetched once per client per run.
 */
final class HaloAgreementResolver
{
    public const SOURCE_SERVICE = 'service';
    public const SOURCE_AGREEMENT = 'agreement';
    public const SOURCE_CLIENT = 'client';

    /** @var array<string,array{contracts:array,invoices:array}> */
    private array $cache = [];

    public function __construct(private readonly HaloPsaClient $halo)
    {
    }

    /**
     * @param array<string,string> $vars  HaloLineTemplate::variables() of the service
     * @return array{invoice_id:int,contract_id:?int,contract_ref:?string,source:string}
     * @throws SyncItemFailed
     */
    public function resolve(InternetService $service, Client $client, HaloBillingSettings $cfg, array $vars): array
    {
        if ($service->halo_override_invoice_id) {
            return $this->result((int) $service->halo_override_invoice_id, null, $service->halo_override_invoice_name, self::SOURCE_SERVICE);
        }

        $pin = $client->halo_recurring_invoice_id ? (int) $client->halo_recurring_invoice_id : null;
        $name = $this->clientName($client);

        if ($cfg->strategy === HaloBillingSettings::STRATEGY_CLIENT) {
            if ($pin) {
                return $this->result($pin, null, $client->halo_recurring_invoice_name, self::SOURCE_CLIENT);
            }
            throw new SyncItemFailed("No recurring invoice for client {$name} - select one (Clients → edit → HaloPSA recurring invoice, or on the service page).");
        }

        try {
            return $this->byAgreement((int) $client->halopsa_reference, $name, $cfg, $vars);
        } catch (SyncItemFailed $e) {
            if ($pin) {
                return $this->result($pin, null, $client->halo_recurring_invoice_name, self::SOURCE_CLIENT);
            }
            throw $e;
        }
    }

    /** @return array{invoice_id:int,contract_id:?int,contract_ref:?string,source:string} */
    private function byAgreement(int $haloClientId, string $name, HaloBillingSettings $cfg, array $vars): array
    {
        $data = $this->clientData($haloClientId, $cfg);
        $pattern = $this->renderPattern($cfg->contractRef, $vars);

        $candidates = array_values(array_filter($data['contracts'], fn ($c) => HaloBillingSettings::patternMatches($pattern, $c['ref'])));
        if ($candidates === []) {
            throw new SyncItemFailed("No active agreement found for client {$name}" . $this->describeRule($cfg, $pattern)
                . ' - select a recurring invoice on the service or the client instead.');
        }

        if (count($candidates) > 1) {
            $candidates = $this->narrow($candidates, $vars);
        }
        if (count($candidates) > 1) {
            throw new SyncItemFailed("Client {$name} has " . count($candidates) . ' matching agreements and none identifies this service: '
                . $this->list(array_map(fn ($c) => $c['ref'] !== '' ? $c['ref'] : 'Agreement #' . $c['id'], $candidates))
                . ' - select a recurring invoice on the service page.');
        }

        $contract = $candidates[0];
        $label = $contract['ref'] !== '' ? $contract['ref'] : 'Agreement #' . $contract['id'];
        $invoices = array_values(array_filter($data['invoices'], fn ($i) => $i['contract_id'] === $contract['id']));

        if ($invoices === []) {
            throw new SyncItemFailed("No recurring invoice for client {$name} - select one on the service page or the client (agreement \"{$label}\" has none).");
        }
        if (count($invoices) > 1) {
            throw new SyncItemFailed("Agreement \"{$label}\" has " . count($invoices) . ' recurring invoices ('
                . $this->list(array_map(fn ($i) => $i['name'] . ' #' . $i['id'], $invoices)) . ') - select one on the service page.');
        }

        return $this->result($invoices[0]['id'], $contract['id'], $contract['ref'], self::SOURCE_AGREEMENT);
    }

    /** @return array{contracts:array,invoices:array} */
    private function clientData(int $haloClientId, HaloBillingSettings $cfg): array
    {
        $key = $haloClientId . '|' . $cfg->contractTypeId . '|' . $cfg->contractSubtypeId;

        return $this->cache[$key] ??= [
            'contracts' => $this->halo->listClientContracts($haloClientId, $cfg->contractTypeId, $cfg->contractSubtypeId),
            'invoices' => $this->halo->listClientRecurringInvoices($haloClientId),
        ];
    }

    /**
     * Several agreements: keep those whose reference mentions this service. Identifiers that name one connection first
     * (service id, username, AVC, LOC), then the plan. A tier that matches nothing leaves the candidates unchanged.
     */
    private function narrow(array $candidates, array $vars): array
    {
        foreach ([['service_id', 'username', 'avc_id', 'loc_id'], ['plan_name', 'plan_id']] as $tier) {
            $needles = array_values(array_filter(array_map(fn ($k) => (string) ($vars[$k] ?? ''), $tier), fn ($v) => mb_strlen($v) >= 3));
            if ($needles === []) {
                continue;
            }
            $hits = array_values(array_filter($candidates, function ($c) use ($needles) {
                foreach ($needles as $n) {
                    if (stripos($c['ref'], $n) !== false) {
                        return true;
                    }
                }

                return false;
            }));
            if ($hits !== []) {
                $candidates = $hits;
            }
            if (count($candidates) === 1) {
                break;
            }
        }

        return $candidates;
    }

    /** Tokens filled from the service; one without a value becomes a wildcard. Values are literal when the pattern uses wildcards. */
    private function renderPattern(string $raw, array $vars): string
    {
        if ($raw === '' || ! str_contains($raw, '{')) {
            return $raw;
        }
        if (str_contains($raw, '*') || str_contains($raw, '?')) {
            $vars = array_map(fn ($v) => addcslashes($v, '*?[]\\'), $vars);
        }

        return HaloLineTemplate::render($raw, $vars, '*');
    }

    private function describeRule(HaloBillingSettings $cfg, string $pattern): string
    {
        $parts = [];
        if ($cfg->contractTypeId) {
            $parts[] = 'contract type #' . $cfg->contractTypeId;
        }
        if ($cfg->contractSubtypeId) {
            $parts[] = 'subtype #' . $cfg->contractSubtypeId;
        }
        if ($pattern !== '') {
            $parts[] = 'reference "' . $pattern . '"';
        }

        return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
    }

    private function list(array $items): string
    {
        $shown = array_slice($items, 0, 5);

        return implode(', ', $shown) . (count($items) > 5 ? ', and ' . (count($items) - 5) . ' more' : '');
    }

    private function result(int $invoiceId, ?int $contractId, ?string $contractRef, string $source): array
    {
        return ['invoice_id' => $invoiceId, 'contract_id' => $contractId, 'contract_ref' => $contractRef, 'source' => $source];
    }

    private function clientName(Client $client): string
    {
        return (string) ($client->business_name ?: $client->name ?: ('#' . $client->id));
    }
}
