<?php

namespace App\Services\Halo;

use App\Models\Setting;

/**
 * Settings → HaloPSA → Billing (stored under settings key "halo", sub-key "billing").
 *
 *  - sync_subscriptions   create/update the client's Halo subscription (SoftwareLicence) per internet service
 *  - sync_lines           add/update the recurring invoice line that bills it
 *  - strategy             "contract": the recurring invoice of the client's active contract (optionally of one contract
 *                         type / matching a ref pattern); "client": only the recurring invoice picked per client
 *  - contract_type_id     optional Halo contract type filter (strategy "contract")
 *  - contract_ref         optional ref filter: plain text = contains, "*" and "?" wildcards allowed
 *
 * A recurring invoice picked on the client always wins. DomainDash never creates recurring invoices itself.
 */
final class HaloBillingSettings
{
    public const STRATEGY_CONTRACT = 'contract';
    public const STRATEGY_CLIENT = 'client';

    public function __construct(
        public readonly bool $syncSubscriptions = false,
        public readonly bool $syncLines = true,
        public readonly string $strategy = self::STRATEGY_CONTRACT,
        public readonly ?int $contractTypeId = null,
        public readonly string $contractRef = '',
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
        $strategy = in_array($b['strategy'] ?? null, [self::STRATEGY_CONTRACT, self::STRATEGY_CLIENT], true)
            ? $b['strategy'] : self::STRATEGY_CONTRACT;
        $type = isset($b['contract_type_id']) && (int) $b['contract_type_id'] > 0 ? (int) $b['contract_type_id'] : null;

        return new self(
            $flag($b['sync_subscriptions'] ?? null, false),
            $flag($b['sync_lines'] ?? null, true),
            $strategy,
            $type,
            trim((string) ($b['contract_ref'] ?? '')),
        );
    }

    /** Anything to do at all? Used by the automatic triggers. */
    public function enabled(): bool
    {
        return $this->syncSubscriptions || $this->syncLines;
    }

    public function contractRefMatches(string $ref): bool
    {
        if ($this->contractRef === '') {
            return true;
        }
        $pattern = $this->contractRef;
        if (! str_contains($pattern, '*') && ! str_contains($pattern, '?')) {
            return stripos($ref, $pattern) !== false;
        }

        return fnmatch(strtolower($pattern), strtolower($ref));
    }

    /** Part of the sync hash: a changed setting re-pushes everything once. */
    public function fingerprint(): array
    {
        return [$this->syncSubscriptions, $this->syncLines, $this->strategy, $this->contractTypeId, $this->contractRef];
    }
}
