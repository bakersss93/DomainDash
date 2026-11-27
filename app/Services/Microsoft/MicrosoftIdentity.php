<?php

namespace App\Services\Microsoft;

/** Claims from a fully validated Entra ID token. Only fields the matcher may rely on are exposed. */
final class MicrosoftIdentity
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $objectId,
        /** preferred_username (UPN): its domain is verified in the tenant. */
        public readonly ?string $upn,
        /** The mutable `email` claim: only usable when emailDomainOwnerVerified is true. */
        public readonly ?string $email,
        public readonly bool $emailDomainOwnerVerified,
        public readonly ?string $name,
    ) {
    }
}
