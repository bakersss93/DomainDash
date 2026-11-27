<?php

namespace App\Services\Microsoft;

use App\Models\Client;
use App\Models\User;
use App\Services\Saml\SsoPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Decides which PRE-EXISTING client user a validated Microsoft identity may sign in as. Never creates users.
 *
 *  1. A user already linked to (tid, oid) is matched on (tid, oid) ONLY. Email / UPN changes are irrelevant.
 *  2. Otherwise first link: the UPN (`preferred_username`, whose domain is verified in the tenant) must equal users.email,
 *     case-insensitively. The `email` claim is mutable and unverified (nOAuth), so it is ignored unless
 *     `xms_edov` says the tenant verified the domain owner.
 *  3. A user already linked to a different identity is refused; so is a staff user (staff use SAML).
 *  4. Every client of the user must have its Entra tenant (clients.microsoft_tenant_id) recorded when the setting
 *     "require a tenant ID for each client" is on (default); the tenant must match. Only when that requirement is
 *     off is a missing tenant tolerated, and then recorded on first link for a user with exactly one client.
 */
class MicrosoftUserResolver
{
    /** @throws MicrosoftOidcException */
    public function resolve(MicrosoftIdentity $id): User
    {
        $allowed = MicrosoftSettings::allowedTenants();
        if ($allowed !== [] && ! in_array($id->tenantId, $allowed, true)) {
            throw new MicrosoftOidcException('Your organisation is not allowed to sign in here.', 'tenant_not_allowed', ['tid' => $id->tenantId]);
        }

        $user = User::query()->where('microsoft_tid', $id->tenantId)->where('microsoft_oid', $id->objectId)->first();
        $firstLink = false;

        if (! $user) {
            $user = $this->findByEmail($id);
            if (! $user) {
                throw new MicrosoftOidcException('Your account has not been set up in DomainDash yet. Please contact your administrator.', 'unknown_user', ['upn' => $id->upn, 'tid' => $id->tenantId]);
            }
            if ($user->microsoft_oid !== null || $user->microsoft_tid !== null) {
                // Same mailbox name, different Microsoft identity: never re-link silently.
                throw new MicrosoftOidcException('This account is linked to a different Microsoft account.', 'link_mismatch', ['user_id' => $user->id, 'tid' => $id->tenantId]);
            }
            $firstLink = true;
        }

        if (! $user->is_active) {
            throw new MicrosoftOidcException('This account is disabled. Please contact your administrator.', 'inactive', ['user_id' => $user->id]);
        }
        if (SsoPolicy::isStaff($user)) {
            throw new MicrosoftOidcException('Staff accounts sign in with their organisation login. Enter your work email and use "Sign in with Microsoft".', 'staff_user', ['user_id' => $user->id]);
        }
        if ($user->saml_connection_id !== null) {
            throw new MicrosoftOidcException('This account uses staff sign-in.', 'saml_linked_user', ['user_id' => $user->id]);
        }

        $clients = $user->clients()->get(['clients.id', 'clients.microsoft_tenant_id']);
        if ($clients->isEmpty()) {
            throw new MicrosoftOidcException('This account is not assigned to a client. Please contact your administrator.', 'no_client', ['user_id' => $user->id]);
        }
        $requireTenant = MicrosoftSettings::requireClientTenant();
        foreach ($clients as $client) {
            $lock = strtolower(trim((string) $client->microsoft_tenant_id));
            if ($requireTenant && $lock === '') {
                // The global allowed-tenants list deliberately does not substitute for a per-client tenant.
                throw new MicrosoftOidcException('Microsoft sign-in has not been set up for your organisation yet. Please contact your administrator.', 'tenant_not_configured', ['user_id' => $user->id, 'client_id' => $client->id, 'tid' => $id->tenantId]);
            }
            if ($lock !== '' && $lock !== $id->tenantId) {
                throw new MicrosoftOidcException('Your Microsoft organisation is not the one registered for this account.', 'client_tenant_mismatch', ['user_id' => $user->id, 'client_id' => $client->id, 'tid' => $id->tenantId]);
            }
        }

        try {
            DB::transaction(function () use ($user, $id, $firstLink, $clients, $requireTenant) {
                $attrs = ['microsoft_last_login_at' => now()];
                if ($firstLink) {
                    $attrs += ['microsoft_oid' => $id->objectId, 'microsoft_tid' => $id->tenantId, 'microsoft_linked_at' => now()];
                }
                $user->forceFill($attrs)->save();

                if (! $requireTenant && $firstLink && $clients->count() === 1 && trim((string) $clients->first()->microsoft_tenant_id) === '') {
                    Client::query()->whereKey($clients->first()->id)->whereNull('microsoft_tenant_id')->update(['microsoft_tenant_id' => $id->tenantId]);
                }
            });
        } catch (QueryException) {
            // unique(microsoft_tid, microsoft_oid): this identity was just linked to someone else.
            throw new MicrosoftOidcException('This Microsoft account is already linked to another user.', 'identity_in_use', ['user_id' => $user->id]);
        }

        return $user;
    }

    private function findByEmail(MicrosoftIdentity $id): ?User
    {
        $candidates = [];
        if ($id->upn !== null && str_contains($id->upn, '@')) {
            $candidates[] = $id->upn;
        }
        if ($id->emailDomainOwnerVerified && $id->email !== null && str_contains($id->email, '@')) {
            $candidates[] = $id->email;
        }

        foreach (array_unique($candidates) as $email) {
            $matches = User::query()->whereRaw('lower(email) = ?', [$email])->limit(2)->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }
}
