<?php

namespace App\Services\Microsoft;

use App\Models\Client;
use App\Services\AuditLogger;
use App\Support\SessionRevoker;

/**
 * What must happen after a client's Microsoft tenant / sign-in method changed: its users' sessions were issued
 * under the old trust relationship, so they are revoked, and the change is audited. Shared by the client form
 * (ClientsController) and the Microsoft Partner mapping so both behave identically.
 */
class ClientTenantChange
{
    /** @param array $originalValues the client's attributes before the update */
    public static function revokeAndAudit(Client $client, array $originalValues): int
    {
        $revoked = 0;
        foreach ($client->users()->get() as $u) {
            $revoked += SessionRevoker::revoke($u);
        }

        AuditLogger::logSystem('client.sso_settings.changed', "Microsoft tenant / sign-in method changed for {$client->business_name}; client user sessions revoked", [
            'function' => 'sso', 'automated' => false,
        ] + [
            'client_id' => $client->id,
            'old' => ['microsoft_tenant_id' => $originalValues['microsoft_tenant_id'] ?? null, 'login_method' => $originalValues['login_method'] ?? null],
            'new' => ['microsoft_tenant_id' => $client->microsoft_tenant_id, 'login_method' => $client->login_method],
            'sessions_revoked' => $revoked,
        ]);

        return $revoked;
    }
}
