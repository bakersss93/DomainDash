<?php

namespace App\Support\Settings;

/**
 * Information architecture of Admin -> Settings (docs/review/18-settings-redesign.md).
 *
 * Home = categorised tiles. Every area is one page (/admin/settings/{area}) with horizontal tabs; each tab is the
 * Blade partial resources/views/admin/settings/tabs/{area}/{tab}.blade.php. A tile may instead link elsewhere
 * (`route` / `url`): Platform HTTPS keeps its own page. Billing has one home only: HaloPSA → Billing (/admin/settings/halopsa?tab=billing).
 */
final class SettingsAreas
{
    /** @return array<string,array{title:string,areas:array<int,string>}> */
    public static function categories(): array
    {
        return [
            'general' => ['title' => 'General', 'areas' => ['branding', 'email', 'backups']],
            'security' => ['title' => 'Security', 'areas' => ['https', 'mfa']],
            'integrations' => ['title' => 'Integrations', 'areas' => ['halopsa', 'itglue', 'synergy', 'vocus', 'ip2whois']],
            'operations' => ['title' => 'Sync and queue', 'areas' => ['sync']],
        ];
    }

    /**
     * @return array<string,array{title:string,description:string,icon:string,tabs:array<string,string>,route?:string,url?:string,setting?:string}>
     */
    public static function all(): array
    {
        return [
            'branding' => ['title' => 'Branding', 'description' => 'Colours and logo for light and dark mode.', 'icon' => 'sun', 'tabs' => ['branding' => 'Branding']],
            'email' => ['title' => 'Email (SMTP)', 'description' => 'Outgoing mail server and a test message.', 'icon' => 'inbox', 'tabs' => ['smtp' => 'SMTP'], 'setting' => 'smtp'],
            'backups' => ['title' => 'Backups', 'description' => 'SFTP destination, schedule and retention.', 'icon' => 'download', 'tabs' => ['backup' => 'Backups'], 'setting' => 'backup'],
            'https' => ['title' => 'Platform HTTPS', 'description' => "Let's Encrypt or uploaded certificate, force HTTPS, renewal.", 'icon' => 'shield', 'tabs' => [], 'route' => 'admin.https'],
            'mfa' => ['title' => 'MFA and audit', 'description' => 'Multi-factor defaults and audit-log retention.', 'icon' => 'key', 'tabs' => ['mfa' => 'Multi-factor', 'audit' => 'Audit log'], 'setting' => 'mfa'],
            'halopsa' => ['title' => 'HaloPSA', 'description' => 'Connection, clients, asset mapping, tickets, billing and sync.', 'icon' => 'cog', 'tabs' => ['connection' => 'Connection', 'clients' => 'Clients', 'assets' => 'Assets', 'tickets' => 'Tickets', 'billing' => 'Billing', 'sync' => 'Sync'], 'setting' => 'halo'],
            'itglue' => ['title' => 'IT Glue', 'description' => 'Connection, organisation mapping, flexible asset mapping and sync.', 'icon' => 'copy', 'tabs' => ['connection' => 'Connection', 'organisations' => 'Organisations', 'assets' => 'Assets', 'sync' => 'Sync'], 'setting' => 'itglue'],
            'synergy' => ['title' => 'Synergy Wholesale', 'description' => 'Domain registrar API credentials.', 'icon' => 'globe', 'tabs' => ['connection' => 'Connection'], 'setting' => 'synergy'],
            'vocus' => ['title' => 'Vocus Wholesale', 'description' => 'NBN service API, realms, plans and sandbox.', 'icon' => 'globe', 'tabs' => ['connection' => 'Connection', 'ordering' => 'Ordering', 'plans' => 'Plans', 'sandbox' => 'Sandbox'], 'setting' => 'vocus'],
            'ip2whois' => ['title' => 'IP2WHOIS', 'description' => 'WHOIS lookups for domains DomainDash cannot read from the registrar.', 'icon' => 'search', 'tabs' => ['connection' => 'Connection'], 'setting' => 'ip2whois'],
            'sync' => ['title' => 'Sync scheduler', 'description' => 'Scheduled syncs, worker health and recent runs.', 'icon' => 'refresh', 'tabs' => ['schedule' => 'Schedule'], 'setting' => 'sync_schedule'],
        ];
    }

    public static function has(string $area): bool
    {
        return isset(self::all()[$area]) && self::all()[$area]['tabs'] !== [];
    }

    /** @return array{title:string,description:string,icon:string,tabs:array<string,string>}|null */
    public static function get(string $area): ?array
    {
        return self::has($area) ? self::all()[$area] : null;
    }

    public static function href(string $area): string
    {
        $def = self::all()[$area];
        if (isset($def['route'])) {
            return route($def['route']);
        }

        return $def['url'] ?? route('admin.settings.area', $area);
    }
}
