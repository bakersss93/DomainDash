<?php

namespace App\Services\Microsoft\Partner;

use App\Models\Client;
use App\Models\PartnerCustomerTenant;
use App\Services\AuditLogger;
use App\Services\Microsoft\ClientTenantChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirm / change / clear the mapping of a partner customer tenant to a DomainDash client. The mapping is
 * clients.microsoft_tenant_id: one tenant belongs to one client and a client has one tenant. Changing it revokes
 * the client users' sessions and is audited exactly like the client form (ClientTenantChange), plus partner.mapping.*.
 */
class PartnerTenantMapper
{
    /** Map $tenant to $client (replaces the tenant's previous client, and the client's previous tenant). */
    public function map(PartnerCustomerTenant $tenant, Client $client): void
    {
        $tenantId = strtolower($tenant->tenant_id);
        if (! PartnerSettings::isGuid($tenantId)) {
            throw ValidationException::withMessages(['client_id' => 'The tenant ID is not a valid GUID.']);
        }

        DB::transaction(function () use ($tenant, $client, $tenantId) {
            $previousClient = Client::query()->whereRaw('lower(microsoft_tenant_id) = ?', [$tenantId])->where('id', '!=', $client->id)->lockForUpdate()->first();
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);

            if (strtolower((string) $client->microsoft_tenant_id) === $tenantId && $previousClient === null) {
                return; // already mapped this way
            }

            // Free the tenant from its previous client first (unique), revoking that client's users too.
            if ($previousClient !== null) {
                $this->apply($previousClient, null, $tenant, 'partner.mapping.cleared', "Microsoft tenant {$tenant->display_name} unmapped from {$previousClient->business_name} (reassigned)");
            }

            $this->apply($client, $tenantId, $tenant, 'partner.mapping.confirmed', "Microsoft tenant {$tenant->display_name} mapped to {$client->business_name}");
        });
    }

    /** Remove the tenant's mapping (clears clients.microsoft_tenant_id). */
    public function clear(PartnerCustomerTenant $tenant): bool
    {
        $client = Client::query()->whereRaw('lower(microsoft_tenant_id) = ?', [strtolower($tenant->tenant_id)])->first();
        if ($client === null) {
            return false;
        }

        DB::transaction(fn () => $this->apply($client, null, $tenant, 'partner.mapping.cleared', "Microsoft tenant {$tenant->display_name} unmapped from {$client->business_name}"));

        return true;
    }

    private function apply(Client $client, ?string $tenantId, PartnerCustomerTenant $tenant, string $action, string $description): void
    {
        $original = $client->getAttributes();
        $client->forceFill(['microsoft_tenant_id' => $tenantId])->save();

        if (! $client->wasChanged('microsoft_tenant_id')) {
            return;
        }

        $revoked = ClientTenantChange::revokeAndAudit($client, $original);

        AuditLogger::logSystem($action, $description, ['function' => 'partner-mapping', 'automated' => false], [
            'new_values' => [
                'client_id' => $client->id,
                'partner_tenant_id' => $tenant->tenant_id,
                'old_microsoft_tenant_id' => $original['microsoft_tenant_id'] ?? null,
                'new_microsoft_tenant_id' => $tenantId,
                'sessions_revoked' => $revoked,
            ],
        ]);
    }
}
