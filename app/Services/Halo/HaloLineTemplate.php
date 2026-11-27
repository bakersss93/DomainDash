<?php

namespace App\Services\Halo;

use App\Models\Client;
use App\Models\InternetService;

/**
 * Templates for the text of a Halo recurring invoice line (InvoiceDetail.item_shortdescription / item_longdescription)
 * and for the agreement reference pattern.
 *
 *  - DomainDash placeholders in braces, e.g. {plan_name}, are replaced from the service; unknown braces stay as typed.
 *  - Halo's own variables ($PERIODSTARTDATE, $PERIODENDDATE, agreement / recurring billing / asset variables) are never
 *    touched: Halo fills them in when it invoices.
 * One pass (strtr), so a value can never be expanded again.
 */
final class HaloLineTemplate
{
    /** placeholder => what it holds (shown in Settings and in the docs). */
    public const PLACEHOLDERS = [
        'plan_name' => 'Plan name',
        'plan_id' => 'Plan id',
        'speed' => 'Speed tier of the plan',
        'service_id' => 'Service id',
        'vocus_service_id' => 'Service id (same as service_id)',
        'avc_id' => 'AVC id',
        'loc_id' => 'Location (LOC) id',
        'username' => 'Username',
        'address' => 'Service address',
        'ip_address' => 'IP address',
        'technology' => 'Technology',
        'client_code' => 'Client reference in Halo',
        'client_name' => 'Client name',
    ];

    /** Extra names usable in the reference pattern only. */
    private const ALIASES = ['plan' => 'plan_name'];

    /**
     * @return array<string,string>
     */
    public static function variables(InternetService $service, ?Client $client = null, ?string $clientCode = null): array
    {
        $plan = $service->vocusPlan;
        $client ??= $service->client;
        $speed = $plan?->speed_tier ?: (($plan?->download_mbps && $plan?->upload_mbps) ? $plan->download_mbps . '/' . $plan->upload_mbps : '');

        $vars = [
            'plan_name' => (string) ($plan?->name ?? ''),
            'plan_id' => (string) ($plan?->plan_id ?? $service->plan_id ?? ''),
            'speed' => (string) $speed,
            'service_id' => (string) ($service->vocus_service_id ?? ''),
            'vocus_service_id' => (string) ($service->vocus_service_id ?? ''),
            'avc_id' => (string) ($service->avc_id ?? ''),
            'loc_id' => (string) ($service->directory_id ?? ''),
            'username' => (string) ($service->username ?? ''),
            'address' => (string) ($service->address_long ?? ''),
            'ip_address' => (string) ($service->ip_address ?? ''),
            'technology' => (string) ($plan?->technology ?: ($service->service_type ?? '')),
            'client_code' => (string) ($clientCode ?? ''),
            'client_name' => (string) ($client ? ($client->business_name ?: ($client->name ?? '')) : ''),
        ];

        return array_map(fn ($v) => self::clean($v), $vars);
    }

    /** Does the text use a placeholder that needs the client's Halo reference? */
    public static function needsClientCode(string ...$templates): bool
    {
        foreach ($templates as $t) {
            if (str_contains($t, '{client_code}')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $vars
     * @param string|null $emptyAs  what a placeholder with no value becomes (null = empty text)
     */
    public static function render(string $template, array $vars, ?string $emptyAs = null): string
    {
        $map = [];
        foreach ($vars as $key => $value) {
            $map['{' . $key . '}'] = ($value === '' && $emptyAs !== null) ? $emptyAs : $value;
        }
        foreach (self::ALIASES as $alias => $key) {
            if (isset($vars[$key])) {
                $map['{' . $alias . '}'] = ($vars[$key] === '' && $emptyAs !== null) ? $emptyAs : $vars[$key];
            }
        }

        return strtr(str_replace("\r\n", "\n", $template), $map);
    }

    /** Description / long description of a line, trimmed to what Halo columns take. @return array{0:string,1:string} */
    public static function lineTexts(HaloBillingSettings $cfg, array $vars): array
    {
        return [
            mb_substr(trim(self::render($cfg->lineDescription, $vars)), 0, 255),
            mb_substr(trim(self::render($cfg->lineLongDescription, $vars)), 0, 2000),
        ];
    }

    /** A value that comes from a vendor: no control characters (a newline in a username would break the layout). */
    private static function clean(string $v): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
    }
}
