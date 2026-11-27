<?php

namespace App\Services\Assets;

use App\Models\Domain;
use App\Models\HostingService;
use App\Models\InternetService;
use App\Models\SslCertificate;
use Illuminate\Database\Eloquent\Model;

/**
 * The DomainDash item types that can be documented as assets in HaloPSA / IT Glue: their data points ("DomainDash
 * values" the mapping maps onto asset-type fields), the value of each point for one record, the per-record sync
 * columns, and the SyncRun scope per provider.
 *
 * Domains keep their historical point keys and columns (halo_asset_id, halo_synced_at ...); the other entities use
 * halo_asset_* because internet_services already uses halo_synced_at / halo_sync_* for subscription billing.
 */
final class AssetEntity
{
    public const DOMAIN = 'domain';
    public const INTERNET = 'internet_service';
    public const HOSTING = 'hosting_service';
    public const SSL = 'ssl_certificate';

    public const KEYS = [self::DOMAIN, self::INTERNET, self::HOSTING, self::SSL];

    public const PROVIDERS = ['halo', 'itglue'];

    /** entity => [label, plural, model, SyncRun scopes per provider, client-facing short name] */
    private const META = [
        self::DOMAIN => ['Domains', 'domains', Domain::class, ['halo' => 'domains', 'itglue' => 'configurations'], 'domain'],
        self::INTERNET => ['Internet services', 'internet services', InternetService::class, ['halo' => 'push-internet', 'itglue' => 'push-internet'], 'internet service'],
        self::HOSTING => ['Hosting services', 'hosting services', HostingService::class, ['halo' => 'push-hosting', 'itglue' => 'push-hosting'], 'hosting service'],
        self::SSL => ['SSL certificates', 'SSL certificates', SslCertificate::class, ['halo' => 'push-ssl', 'itglue' => 'push-ssl'], 'SSL certificate'],
    ];

    /** Points shared by both providers for the non-domain entities. */
    private const POINTS = [
        self::INTERNET => [
            'service_id' => 'Service ID',
            'reference' => 'Service reference',
            'location_id' => 'Location ID (LOC / Directory ID)',
            'postcode' => 'Postcode (location reference)',
            'avc_id' => 'AVC ID',
            'technology' => 'Technology',
            'service_type' => 'Service type',
            'plan' => 'Plan and speed',
            'username' => 'Username (PPPoE)',
            'ip_address' => 'IP address',
            'address' => 'Service address',
            'status' => 'Status',
            'service_class' => 'Service class',
            'ntd_port' => 'NTD and UNI-D port',
            'client' => 'Client',
        ],
        self::HOSTING => [
            'domain' => 'Domain',
            'plan' => 'Plan / package',
            'server' => 'Server',
            'ip_address' => 'IP address',
            'username' => 'Username',
            'status' => 'Status',
            'renewal' => 'Renewal date',
            'disk' => 'Disk usage and limit',
            'bandwidth' => 'Bandwidth usage and limit',
        ],
        self::SSL => [
            'common_name' => 'Common name',
            'product' => 'Product',
            'expiry' => 'Expiry date',
            'status' => 'Status',
            'issued' => 'Issued date',
            'cert_id' => 'Certificate ID',
        ],
    ];

    /** Domains differ per provider: Halo has no "dns" value and calls name servers "nameservers". */
    private const DOMAIN_POINTS = [
        'halo' => ['domain' => 'Domain name', 'expiry' => 'Expiry date', 'nameservers' => 'Name servers', 'whois' => 'WHOIS / registrant summary'],
        'itglue' => ['domain' => 'Domain name', 'name_servers' => 'Name servers', 'expiry' => 'Expiry date', 'whois' => 'WHOIS / registrant summary', 'dns' => 'DNS records'],
    ];

    /** Halo key fields 1-3 used until a mapping chooses its own (domains keep their historical defaults). */
    private const HALO_KEY_DEFAULTS = [
        self::DOMAIN => [1 => 'domain', 2 => 'expiry', 3 => 'nameservers'],
        self::INTERNET => [1 => 'service_id', 2 => 'plan', 3 => 'status'],
        self::HOSTING => [1 => 'domain', 2 => 'plan', 3 => 'status'],
        self::SSL => [1 => 'common_name', 2 => 'expiry', 3 => 'status'],
    ];

    public static function valid(string $entity): bool
    {
        return isset(self::META[$entity]);
    }

    public static function label(string $entity): string
    {
        return self::META[$entity][0];
    }

    public static function plural(string $entity): string
    {
        return self::META[$entity][1];
    }

    public static function singular(string $entity): string
    {
        return self::META[$entity][4];
    }

    /** @return class-string<Model> */
    public static function model(string $entity): string
    {
        return self::META[$entity][2];
    }

    /** SyncRun scope for one entity pushed to one provider. */
    public static function scope(string $entity, string $provider): string
    {
        return self::META[$entity][3][$provider];
    }

    /** Reverse lookup: [entity, provider] for a SyncRun (provider, scope), or null. */
    public static function fromRun(string $provider, string $scope): ?string
    {
        foreach (self::META as $entity => $meta) {
            if (($meta[3][$provider] ?? null) === $scope) {
                return $entity;
            }
        }

        return null;
    }

    /** @return array<string,string> point => label */
    public static function points(string $entity, string $provider): array
    {
        return $entity === self::DOMAIN ? self::DOMAIN_POINTS[$provider] : self::POINTS[$entity];
    }

    /** @return array<int,string> slot => point */
    public static function haloKeyDefaults(string $entity): array
    {
        return self::HALO_KEY_DEFAULTS[$entity];
    }

    /** Column names of the per-record sync state for one provider. */
    public static function columns(string $entity, string $provider): array
    {
        if ($provider === 'itglue') {
            return ['id' => 'itglue_id', 'synced' => 'itglue_synced_at', 'error' => 'itglue_sync_error', 'hash' => 'itglue_sync_hash'];
        }

        return $entity === self::DOMAIN
            ? ['id' => 'halo_asset_id', 'synced' => 'halo_synced_at', 'error' => 'halo_sync_error', 'hash' => 'halo_sync_hash']
            : ['id' => 'halo_asset_id', 'synced' => 'halo_asset_synced_at', 'error' => 'halo_asset_sync_error', 'hash' => 'halo_asset_sync_hash'];
    }

    /** Asset name / Halo inventory number: the human identifier of the record. */
    public static function name(string $entity, Model $m): string
    {
        $name = match ($entity) {
            self::DOMAIN => (string) $m->name,
            self::INTERNET => (string) ($m->vocus_service_id ?: ''),
            self::HOSTING => (string) ($m->domain_name ?: ($m->domain?->name ?? '') ?: ($m->hoid ?: '')),
            self::SSL => (string) ($m->common_name ?: ($m->cert_id ?: '')),
        };

        return $name !== '' ? $name : self::singular($entity) . ' #' . $m->getKey();
    }

    /** Relations to eager-load before values() / name(). */
    public static function with(string $entity): array
    {
        return match ($entity) {
            self::DOMAIN => ['client'],
            self::INTERNET => ['client', 'vocusPlan'],
            self::HOSTING => ['client', 'domain'],
            self::SSL => ['client', 'domain'],
        };
    }

    /**
     * Value of every point for one record, as plain strings ('' when unknown). Used for the Halo / IT Glue payloads
     * and the sync hash. Domains build their own values (WHOIS, DNS, Synergy fallback) in their pushers.
     *
     * @return array<string,string>
     */
    public static function values(string $entity, Model $m): array
    {
        return match ($entity) {
            self::INTERNET => self::internetValues($m),
            self::HOSTING => self::hostingValues($m),
            self::SSL => self::sslValues($m),
            default => [],
        };
    }

    private static function internetValues(InternetService $s): array
    {
        $plan = $s->vocusPlan;
        $planName = trim((string) ($plan?->name ?: $s->plan_id));
        $speed = '';
        if ($plan && $plan->download_mbps && $plan->upload_mbps) {
            $speed = $plan->download_mbps . '/' . $plan->upload_mbps . ' Mbps';
        } elseif ($plan && $plan->speed_tier) {
            $speed = (string) $plan->speed_tier;
        }

        $ip = trim((string) $s->ip_address);
        if ($ip !== '' && $s->ip_assignment) {
            $ip .= ' (' . $s->ip_assignment . ')';
        } elseif ($ip === '' && $s->ip_assignment) {
            $ip = (string) $s->ip_assignment;
        }

        $ntd = trim(implode(' / ', array_filter([(string) $s->ntd_id, $s->uni_port ? 'UNI-D ' . $s->uni_port : ''])));

        return [
            'service_id' => (string) $s->vocus_service_id,
            'reference' => (string) $s->reference,
            'location_id' => (string) $s->directory_id,
            'postcode' => (string) $s->location_reference,
            'avc_id' => (string) $s->avc_id,
            'technology' => (string) ($plan?->technology ?? ''),
            'service_type' => (string) $s->service_type,
            'plan' => trim($planName . ($speed !== '' ? ' (' . $speed . ')' : '')),
            'username' => (string) $s->username,
            'ip_address' => $ip,
            'address' => (string) $s->address_long,
            'status' => (string) $s->service_status,
            'service_class' => (string) $s->service_class,
            'ntd_port' => $ntd,
            'client' => (string) ($s->client?->business_name ?? ''),
        ];
    }

    private static function hostingValues(HostingService $h): array
    {
        $usage = fn ($used, $limit) => $limit ? (int) $used . ' / ' . (int) $limit . ' MB' : ($used ? (int) $used . ' MB' : '');

        return [
            'domain' => self::name(self::HOSTING, $h),
            'plan' => (string) $h->plan,
            'server' => (string) $h->server,
            'ip_address' => (string) $h->ip_address,
            'username' => (string) $h->username,
            'status' => (string) $h->service_status,
            'renewal' => $h->next_renewal_due?->toDateString() ?? '',
            'disk' => $usage($h->disk_usage_mb, $h->disk_limit_mb),
            'bandwidth' => $usage($h->bandwidth_used_mb, $h->bandwidth_limit_mb),
        ];
    }

    private static function sslValues(SslCertificate $c): array
    {
        return [
            'common_name' => (string) $c->common_name,
            'product' => (string) $c->product_name,
            'expiry' => $c->expire_date?->toDateString() ?? '',
            'status' => (string) $c->status,
            'issued' => $c->start_date?->toDateString() ?? '',
            'cert_id' => (string) $c->cert_id,
        ];
    }
}
