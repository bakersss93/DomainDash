<?php

namespace App\Support\Security;

/**
 * Permissions that only internal staff roles (Administrator / Technician) may hold. Customer and any custom
 * client-facing role can never be granted these. Single source of truth for the permission matrix UI and SSO policy.
 */
class StaffPermissions
{
    public const STAFF_ONLY = [
        'domains.manage', 'domains.transfer', 'domains.register', 'domains.renew',
        'services.manage', 'ssl.manage', 'internet.manage',
        'clients.view', 'clients.manage', 'users.view', 'users.manage', 'users.impersonate',
        'settings.manage', 'apikeys.manage', 'analytics.view', 'sync.run',
        'domain-pricing.view', 'domain-pricing.manage',
        // Integration set-up articles in Support > Help (API keys, certificates, SSO). Never for customer roles.
        'help.integrations.view',
    ];

    /** Role names that are internal staff roles. */
    public const STAFF_ROLES = ['Administrator', 'Technician'];
}
