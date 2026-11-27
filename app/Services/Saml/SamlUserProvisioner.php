<?php

namespace App\Services\Saml;

use App\Models\SamlConnection;
use App\Models\User;
use App\Support\SessionRevoker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Decides WHICH local user an accepted assertion becomes, and what roles that user holds.
 *
 * Isolation rules (tested in tests/Feature/Sso/SsoTenantIsolationTest):
 *  - users are found by (connection, Entra object ID) first; email is used only for the first link
 *  - SAML is staff-only: a connection never touches a user who belongs to a client, and never assigns a
 *    customer-class role (clients use Microsoft sign-in, App\Services\Microsoft\MicrosoftUserResolver)
 *  - users already linked elsewhere are never linked
 *  - roles are re-derived from the groups on every login; no mapped group means denial
 */
class SamlUserProvisioner
{
    /** @throws SamlException */
    public function resolve(SamlConnection $c, SamlIdentity $id): User
    {
        $domains = $c->domainList();
        $domain = $id->emailDomain();

        if (! $id->email || ! $domain) {
            throw new SamlException('Your Microsoft account did not provide an email address.', 'no_email');
        }
        if (! in_array($domain, $domains, true)) {
            throw new SamlException('Your email domain is not allowed to sign in through this connection.', 'domain_not_allowed', ['domain' => $domain]);
        }

        $roles = $this->rolesFor($c, $id);

        $user = User::query()
            ->where('saml_connection_id', $c->id)
            ->where('saml_object_id', $id->objectId)
            ->first();
        $isFirstLink = false;

        if (! $user) {
            $existing = User::query()->whereRaw('lower(email) = ?', [$id->email])->first();
            if ($existing) {
                $this->assertLinkable($c, $existing);
                $user = $existing;
                $isFirstLink = true;
            }
        }

        if ($user) {
            if (! $user->is_active) {
                throw new SamlException('This account is disabled. Please contact your administrator.', 'inactive', ['user_id' => $user->id]);
            }
            $this->assertBoundToConnection($c, $user, $isFirstLink);
        }

        if ($roles === []) {
            // Lost every mapped group: take access away, not just this attempt.
            if ($user && ! $isFirstLink) {
                $user->syncRoles([]);
                SessionRevoker::revoke($user);
            }
            throw new SamlException('Your Microsoft account is not a member of any group that grants access to DomainDash.', 'no_group_match', ['user_id' => $user?->id]);
        }

        return DB::transaction(function () use ($c, $id, $user, $roles) {
            if (! $user) {
                if (! $c->jit_provisioning) {
                    throw new SamlException('Your account has not been set up in DomainDash yet. Please contact your administrator.', 'jit_disabled');
                }
                $user = new User([
                    'name' => $id->name ?: Str::before($id->email, '@'),
                    'email' => $id->email,
                    'password' => Str::random(64),
                    'mfa_preference' => $c->skip_local_mfa ? 'disabled' : 'enforced',
                    'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->forceFill([
                'saml_connection_id' => $c->id,
                'saml_object_id' => $id->objectId,
                'saml_name_id' => $id->nameId,
                'saml_last_login_at' => now(),
            ])->save();

            $user->syncRoles($roles);

            return $user;
        });
    }

    /** @return list<string> role names this login grants; empty = deny */
    public function rolesFor(SamlConnection $c, SamlIdentity $id): array
    {
        $roles = [];
        foreach ((array) $c->group_role_map as $row) {
            if (in_array(strtolower((string) ($row['group_id'] ?? '')), $id->groups, true)) {
                $roles[] = (string) $row['role'];
            }
        }

        if ($roles === [] && $c->default_role) {
            $roles[] = $c->default_role;
        }

        $roles = array_values(array_unique($roles));

        // Customer-class roles can never come from SAML (also refused at save time; ignored here even if stored).
        $roles = array_values(array_filter($roles, fn ($r) => ! SsoPolicy::isCustomerSafeRole($r)));

        return array_values(array_filter($roles, fn ($r) => Role::query()->where('name', $r)->where('guard_name', 'web')->exists()));
    }

    /** First-link-by-email guard. */
    private function assertLinkable(SamlConnection $c, User $existing): void
    {
        if ($existing->saml_connection_id !== null) {
            throw new SamlException('This account is already linked to a different sign-in identity.', 'already_linked', ['user_id' => $existing->id]);
        }
    }

    private function assertBoundToConnection(SamlConnection $c, User $user, bool $isFirstLink): void
    {
        $clientIds = $user->clients()->pluck('clients.id')->all();

        if ($clientIds !== []) {
            throw new SamlException('This account is a client portal account and cannot use staff sign-in.', 'client_user_on_staff_connection', ['user_id' => $user->id]);
        }
        if ($user->microsoft_oid !== null) {
            throw new SamlException('This account is linked to Microsoft client sign-in and cannot use staff sign-in.', 'microsoft_linked_user', ['user_id' => $user->id]);
        }
        if ($user->saml_connection_id !== null && $user->saml_connection_id !== $c->id) {
            throw new SamlException('This account is linked to a different connection.', 'wrong_connection', ['user_id' => $user->id]);
        }
    }
}
