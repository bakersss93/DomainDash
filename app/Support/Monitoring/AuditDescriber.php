<?php

namespace App\Support\Monitoring;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Turns a mutating HTTP request into a sentence a human can read in the Activity tab:
 * "Updated client Example Pty Ltd", "Ordered NBN service", "Changed HaloPSA settings".
 * Rules are keyed by "METHOD route-uri-template" (exact), so unnamed routes work too.
 */
class AuditDescriber
{
    /** @var array<string, array{0: string, 1: string}> template => [description, action] ({label} optional) */
    private const RULES = [
        'POST admin/clients' => ['Created client {label}', 'client.create'],
        'PUT admin/clients/{client}' => ['Updated client {label}', 'client.update'],
        'DELETE admin/clients/{client}' => ['Deleted client {label}', 'client.delete'],
        'POST admin/clients/import-halo' => ['Imported clients from HaloPSA', 'client.import'],
        'POST admin/clients/{client}/itglue/sync-domains' => ['Synced domains to IT Glue for client {label}', 'sync.itglue'],
        'POST admin/clients/{client}/halo/sync-dns' => ['Synced DNS records to HaloPSA for client {label}', 'sync.halo'],
        'POST admin/clients/{client}/halo/link-domains' => ['Linked domains to HaloPSA for client {label}', 'sync.halo'],
        'PUT admin/clients/{client}/halo/recurring-invoice' => ['Changed HaloPSA recurring invoice for client {label}', 'client.halo_recurring_invoice'],

        'POST admin/users' => ['Created user {label}', 'user.create'],
        'PUT admin/users/{user}' => ['Updated user {label}', 'user.update'],
        'DELETE admin/users/{user}' => ['Deleted user {label}', 'user.delete'],
        'PUT admin/users/{user}/password' => ['Changed password for user {label}', 'user.password'],
        'POST admin/users/{user}/password-link' => ['Sent password reset link to user {label}', 'user.password_link'],
        'POST admin/users/{user}/mfa-reset' => ['Reset MFA for user {label}', 'user.mfa_reset'],
        'POST admin/users/{user}/impersonate' => ['Started impersonating user {label}', 'impersonation.start'],

        'POST admin/api-keys' => ['Created API key {label}', 'apikey.create'],
        'PUT admin/api-keys/{key}' => ['Updated API key {label}', 'apikey.update'],
        'DELETE admin/api-keys/{key}' => ['Deleted API key {label}', 'apikey.delete'],
        'POST admin/api-keys/{key}/deactivate' => ['Deactivated API key {label}', 'apikey.deactivate'],
        'POST admin/api-keys/{key}/reactivate' => ['Reactivated API key {label}', 'apikey.reactivate'],
        'POST admin/api-keys/{key}/regenerate' => ['Regenerated API key {label}', 'apikey.regenerate'],

        'POST admin/permissions' => ['Changed role permissions', 'permissions.update'],
        'POST admin/permissions/roles' => ['Created role {label}', 'permissions.role_create'],
        'PUT admin/permissions/roles/{role}' => ['Changed permissions of role {label}', 'permissions.update'],
        'DELETE admin/permissions/roles/{role}' => ['Deleted role {label}', 'permissions.role_delete'],

        'POST admin/audit/retention' => ['Changed Audit Logs settings', 'monitoring.settings'],

        'POST admin/https/letsencrypt' => ['Requested a Let\'s Encrypt certificate for the platform', 'https.request'],
        'POST admin/https/upload' => ['Uploaded a platform HTTPS certificate', 'https.upload'],
        'POST admin/https/force' => ['Changed forced-HTTPS setting', 'https.force'],

        'POST admin/domains/bulk-sync' => ['Synced domains from Synergy Wholesale', 'sync.domains'],
        'POST admin/domains/{domain}/renew' => ['Requested renewal of domain {label}', 'domain.renew'],
        'POST admin/domains/{domain}/assign' => ['Assigned domain {label} to a client', 'domain.assign-client'],
        'POST admin/domains/transfer' => ['Requested a domain transfer', 'domain.transfer'],
        'POST admin/domains/purchase/complete' => ['Registered domain {label}', 'domain.purchase'],
        'POST admin/domains/transfer/complete' => ['Submitted domain transfer {label}', 'domain.transfer'],
        'POST admin/domains/pricing/import' => ['Imported domain pricing', 'pricing.import'],
        'POST admin/domains/pricing/bulk-markup' => ['Applied bulk markup to domain pricing', 'pricing.bulk-markup'],
        'PUT admin/domains/pricing/{domainPricing}/sell-price' => ['Updated sell price for {label}', 'pricing.sell-price-update'],
        'PUT admin/domains/pricing/{domainPricing}/common-domain' => ['Updated common-domain flag for {label}', 'pricing.common-flag-update'],

        'POST domains/{domain}/dns' => ['Added a DNS record to {label}', 'dns.create'],
        'PUT domains/{domain}/dns/{recordId}' => ['Updated a DNS record on {label}', 'dns.update'],
        'DELETE domains/{domain}/dns/{recordId}' => ['Deleted a DNS record from {label}', 'dns.delete'],
        'POST domains/{domain}/dns/options' => ['Changed DNS options of {label}', 'dns.options-update'],

        'POST admin/services/internet/order' => ['Ordered NBN service {label}', 'internet.order'],
        'POST admin/services/internet/transfer' => ['Submitted NBN service transfer {label}', 'internet.transfer'],
        'POST admin/services/internet/sync' => ['Started Vocus internet service sync', 'sync.internet'],
        'POST admin/services/internet/import' => ['Imported Vocus internet services', 'sync.internet'],
        'POST admin/services/internet/halo-sync' => ['Started internet service sync to HaloPSA', 'sync.halo'],
        'POST admin/services/internet/{service}/halo-sync' => ['Synced internet service {label} to HaloPSA', 'sync.halo'],
        'POST admin/services/internet/{service}/assign-client' => ['Assigned internet service {label} to a client', 'internet.assign_client'],
        'POST admin/services/internet/{service}/status' => ['Changed status of internet service {label}', 'internet.set_status'],
        'POST admin/services/internet/{service}/diagnostic' => ['Ran a diagnostic on internet service {label}', 'internet.diagnostic'],
        'POST admin/services/hosting/purchase' => ['Ordered hosting service {label}', 'hosting.purchase'],
        'POST admin/services/hosting/sync' => ['Synced hosting services from Synergy Wholesale', 'sync.hosting'],
        'POST admin/services/hosting/{service}/assign-client' => ['Assigned hosting service {label} to a client', 'hosting.assign_client'],
        'POST admin/services/hosting/{service}/change-domain' => ['Changed domain of hosting service {label}', 'hosting.change_domain'],
        'POST admin/services/hosting/{service}/suspend' => ['Changed suspension of hosting service {label}', 'hosting.suspend'],
        'POST admin/services/ssl/sync' => ['Synced SSL certificates from Synergy Wholesale', 'sync.ssl'],
        'POST admin/services/ssl/purchase' => ['Ordered SSL certificate {label}', 'ssl.purchase'],
        'POST admin/services/ssl/{ssl}/renew' => ['Requested renewal of SSL certificate {label}', 'ssl.renew'],
        'POST admin/services/ssl/{ssl}/rekey' => ['Re-keyed SSL certificate {label}', 'ssl.rekey'],
        'POST admin/services/ssl/{ssl}/assign-client' => ['Assigned SSL certificate {label} to a client', 'ssl.assign_client'],
        'POST admin/services/ssl/{ssl}/certificate' => ['Downloaded SSL certificate {label}', 'ssl.certificate'],
        'POST admin/services/ssl/{ssl}/resend-completion-email' => ['Resent SSL completion e-mail for {label}', 'ssl.resend'],

        'POST admin/sync/halo/clients/sync' => ['Synced clients from HaloPSA', 'sync.halo'],
        'POST admin/sync/halo/domains/sync' => ['Synced domains to HaloPSA', 'sync.halo'],
        'POST admin/sync/itglue/clients/sync' => ['Synced organisations from IT Glue', 'sync.itglue'],
        'POST admin/sync/itglue/configurations/sync' => ['Synced configurations to IT Glue', 'sync.itglue'],
        'POST admin/sync/ip2whois/domains/sync' => ['Ran IP2WHOIS lookups for domains', 'sync.ip2whois'],
        'POST admin/sync/halo/refresh' => ['Started HaloPSA refresh', 'sync.halo'],
        'POST admin/sync/itglue/refresh' => ['Started IT Glue refresh', 'sync.itglue'],
        'POST admin/sync/clients/{client}/{provider}/refresh' => ['Refreshed client {label} from {provider}', 'sync.refresh'],
        'POST admin/sync/halo/domains/run-now' => ['Ran HaloPSA domain sync now', 'sync.halo'],
        'POST admin/sync/assets/{provider}/{entity}/sync' => ['Synced {entity} to {provider} as assets', 'sync.assets'],
        'PUT admin/settings/asset-mappings/{provider}/{entity}' => ['Changed the {provider} asset mapping for {entity}', 'settings.asset_mapping'],
        'DELETE admin/settings/asset-mappings/{provider}/{entity}' => ['Reset the {provider} asset mapping for {entity}', 'settings.asset_mapping'],

        'POST admin/settings' => ['Changed {settings} settings', 'settings.update'],
        'PUT admin/settings/vocus/plans/{plan}/halo-item' => ['Linked Vocus plan {label} to a HaloPSA item', 'vocus.plan.halo_item'],

        'POST me/account' => ['Updated own account details', 'account.update'],
        'POST me/account/password' => ['Changed own password', 'account.password'],
        'POST me/account/mfa-reenroll' => ['Re-enrolled own MFA', 'account.mfa'],
        'POST me/mfa/start' => ['Started MFA enrolment', 'account.mfa'],
        'POST me/mfa/confirm' => ['Confirmed MFA enrolment', 'account.mfa'],

        'POST tickets' => ['Created a support ticket', 'ticket.create'],
        'POST tickets/{ticketId}/reply' => ['Replied to support ticket #{ticketId}', 'ticket.reply'],
        'POST tickets/{ticketId}/close' => ['Closed support ticket #{ticketId}', 'ticket.close'],
    ];

    private const SETTING_GROUPS = [
        'halo' => 'HaloPSA', 'itglue' => 'IT Glue', 'synergy' => 'Synergy Wholesale', 'vocus' => 'Vocus',
        'ip2whois' => 'IP2WHOIS', 'smtp' => 'email (SMTP)', 'branding' => 'branding', 'sync_schedule' => 'sync schedule',
        'backup' => 'backup', 'notifications' => 'notification', 'mfa' => 'MFA', 'audit' => 'Audit Logs',
    ];

    private const MODULES = [
        'clients' => 'clients', 'domains' => 'domains', 'services/internet' => 'vocus', 'services/hosting' => 'synergy',
        'services/ssl' => 'synergy', 'settings' => 'settings', 'users' => 'users', 'permissions' => 'users', 'api-keys' => 'api',
        'tickets' => 'halo', 'sync' => 'sync', 'me' => 'account', 'https' => 'platform', 'notifications' => 'notifications',
        'audit' => 'monitoring', 'dns' => 'domains',
    ];

    /**
     * @param array<int, array<string, mixed>> $changes ChangeTracker output
     * @return array{description: string, action: string, module: string, label: string}
     */
    public static function describe(Request $request, array $changes = []): array
    {
        $route = $request->route();
        $uri = $route ? $route->uri() : trim($request->path(), '/');
        $method = strtoupper($request->method());
        $module = self::module($uri);
        $label = self::label($request, $changes);

        $rule = self::RULES[$method . ' ' . $uri] ?? null;
        if ($rule !== null) {
            [$template, $action] = $rule;
            $description = strtr($template, [
                '{label}' => $label,
                '{provider}' => (string) ($route?->parameter('provider') ?? ''),
                '{ticketId}' => (string) ($route?->parameter('ticketId') ?? ''),
                '{entity}' => \App\Services\Assets\AssetEntity::valid((string) $route?->parameter('entity'))
                    ? strtolower(\App\Services\Assets\AssetEntity::plural((string) $route?->parameter('entity'))) : '',
                '{settings}' => self::settingsGroups($request),
            ]);

            return ['description' => self::tidy($description), 'action' => $action, 'module' => $module, 'label' => $label];
        }

        return [
            'description' => self::generic($method, $uri, $label, $changes),
            'action' => 'action.' . (['POST' => 'submit', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'][$method] ?? 'submit'),
            'module' => $module,
            'label' => $label,
        ];
    }

    public static function module(string $uri): string
    {
        $path = preg_replace('~^(api/)?(v1/)?(admin/)?~', '', $uri) ?? $uri;
        foreach (self::MODULES as $prefix => $module) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $module;
            }
        }

        return 'app';
    }

    private static function settingsGroups(Request $request): string
    {
        $names = [];
        foreach (array_keys($request->except(['_token', '_return', '_method'])) as $key) {
            if (isset(self::SETTING_GROUPS[$key])) {
                $names[] = self::SETTING_GROUPS[$key];
            }
        }

        return $names === [] ? 'system' : implode(' / ', array_unique($names));
    }

    private static function label(Request $request, array $changes): string
    {
        foreach ($changes as $change) {
            if (($change['type'] ?? '') !== 'setting' && ! empty($change['label']) && ! str_starts_with((string) $change['label'], '#')) {
                return (string) $change['label'];
            }
        }

        $route = $request->route();
        if ($route) {
            foreach ($route->parameters() as $value) {
                if ($value instanceof Model) {
                    return ChangeTracker::label($value);
                }
            }
        }

        foreach (['domainName', 'domain', 'business_name', 'name', 'email', 'service_id'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return '';
    }

    private static function generic(string $method, string $uri, string $label, array $changes): string
    {
        $verb = ['POST' => 'Submitted', 'PUT' => 'Updated', 'PATCH' => 'Updated', 'DELETE' => 'Deleted'][$method] ?? 'Submitted';
        foreach ($changes as $change) {
            if (($change['type'] ?? '') === 'setting') {
                continue;
            }
            $verb = ['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted'][$change['event'] ?? ''] ?? $verb;

            return self::tidy("{$verb} {$change['type']} " . ($label !== '' ? $label : ''));
        }

        $segments = array_filter(
            explode('/', preg_replace('~^(api/)?(v1/)?(admin/)?~', '', $uri) ?? $uri),
            fn ($s) => $s !== '' && ! str_starts_with($s, '{')
        );

        return self::tidy($verb . ' ' . str_replace('-', ' ', implode(' ', $segments)) . ($label !== '' ? " ({$label})" : ''));
    }

    private static function tidy(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(' ()', '', $text)) ?? $text);
    }
}
