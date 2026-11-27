<?php

namespace App\Services\Halo;

use App\Models\Setting;

/**
 * Settings → HaloPSA → Billing (stored under settings key "halo", sub-key "billing").
 *
 * Subscriptions
 *  - sync_subscriptions   create/update the client's Halo subscription (SoftwareLicence) per internet service
 * Agreements (which Halo agreement / recurring invoice a service bills on; one billing rule per item type, internet today)
 *  - strategy             "contract": find the client's agreement by the rule below; "client": only the invoice picked on the client
 *  - contract_type_id     Halo Contract Type (ClientContract.contracttype), optional
 *  - contract_subtype_id  Halo Agreement Subtype (ClientContract.subtype), optional
 *  - contract_ref         Agreement Reference pattern, optional: plain text = contains, "*" and "?" wildcards, tokens such
 *                         as {plan} {client_code} {service_id} are filled from the service before matching
 *  - type_lookup_id       Halo Lookup id of the Contract Types list (GET /Lookup?lookupid=), default 28
 *  - subtype_lookup_id    Halo Lookup id of the Agreement Subtypes list, default 76
 * Recurring invoice lines
 *  - sync_lines           add/update the recurring invoice line that bills the service
 *  - line_description     template of the line description (InvoiceDetail.item_shortdescription)
 *  - line_long_description template of the long description (InvoiceDetail.item_longdescription)
 *
 * Resolution order (HaloAgreementResolver): the invoice picked on the service, then the agreement rule, then the
 * invoice pinned on the client. DomainDash never creates agreements or recurring invoices itself.
 */
final class HaloBillingSettings
{
    public const STRATEGY_CONTRACT = 'contract';
    public const STRATEGY_CLIENT = 'client';

    public const DEFAULT_DESCRIPTION = '{plan_name} - $PERIODSTARTDATE - $PERIODENDDATE';
    public const DEFAULT_LONG_DESCRIPTION = "{plan_name} - Billing Period: \$PERIODSTARTDATE - \$PERIODENDDATE\nService ID: {service_id}\nAVC: {avc_id}";

    public function __construct(
        public readonly bool $syncSubscriptions = false,
        public readonly bool $syncLines = true,
        public readonly string $strategy = self::STRATEGY_CONTRACT,
        public readonly ?int $contractTypeId = null,
        public readonly string $contractRef = '',
        public readonly ?int $contractSubtypeId = null,
        public readonly int $subtypeLookupId = HaloPsaClient::CONTRACT_SUBTYPE_LOOKUP_ID,
        public readonly string $lineDescription = self::DEFAULT_DESCRIPTION,
        public readonly string $lineLongDescription = self::DEFAULT_LONG_DESCRIPTION,
        public readonly int $typeLookupId = HaloPsaClient::CONTRACT_TYPE_LOOKUP_ID,
    ) {
    }

    public static function fromSettings(): self
    {
        $halo = Setting::get('halo', []);

        return self::fromArray(is_array($halo) ? (array) ($halo['billing'] ?? []) : []);
    }

    public static function fromArray(array $b): self
    {
        $flag = fn ($v, bool $default) => $v === null ? $default : filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $id = fn ($v) => $v !== null && (int) $v > 0 ? (int) $v : null;
        $text = function ($v, string $default, int $max): string {
            $v = trim(str_replace("\r\n", "\n", (string) ($v ?? '')));

            return $v === '' ? $default : mb_substr($v, 0, $max);
        };
        $strategy = in_array($b['strategy'] ?? null, [self::STRATEGY_CONTRACT, self::STRATEGY_CLIENT], true)
            ? $b['strategy'] : self::STRATEGY_CONTRACT;

        return new self(
            $flag($b['sync_subscriptions'] ?? null, false),
            $flag($b['sync_lines'] ?? null, true),
            $strategy,
            $id($b['contract_type_id'] ?? null),
            trim((string) ($b['contract_ref'] ?? '')),
            $id($b['contract_subtype_id'] ?? null),
            $id($b['subtype_lookup_id'] ?? null) ?? HaloPsaClient::CONTRACT_SUBTYPE_LOOKUP_ID,
            $text($b['line_description'] ?? null, self::DEFAULT_DESCRIPTION, 255),
            $text($b['line_long_description'] ?? null, self::DEFAULT_LONG_DESCRIPTION, 2000),
            $id($b['type_lookup_id'] ?? null) ?? HaloPsaClient::CONTRACT_TYPE_LOOKUP_ID,
        );
    }

    /** Anything to do at all? Used by the automatic triggers. */
    public function enabled(): bool
    {
        return $this->syncSubscriptions || $this->syncLines;
    }

    /** Is any agreement filter configured (type, subtype or reference)? Without one every active agreement is a candidate. */
    public function hasAgreementRule(): bool
    {
        return $this->contractTypeId !== null || $this->contractSubtypeId !== null || $this->contractRef !== '';
    }

    public function contractRefMatches(string $ref): bool
    {
        return self::patternMatches($this->contractRef, $ref);
    }

    /** Plain text = contains; "*" and "?" are wildcards (whole-value match). Empty pattern matches everything. */
    public static function patternMatches(string $pattern, string $ref): bool
    {
        if ($pattern === '') {
            return true;
        }
        if (! str_contains($pattern, '*') && ! str_contains($pattern, '?')) {
            return stripos($ref, $pattern) !== false;
        }

        return fnmatch(strtolower($pattern), strtolower($ref));
    }

    /** Part of the sync hash: a changed setting re-pushes everything once. */
    public function fingerprint(): array
    {
        return [
            $this->syncSubscriptions, $this->syncLines, $this->strategy, $this->contractTypeId, $this->contractRef,
            $this->contractSubtypeId, $this->lineDescription, $this->lineLongDescription,
        ];
    }
}
