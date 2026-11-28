<?php

namespace App\Services\Saml;

/** The verified facts out of an accepted assertion. Only built after every signature/timing check passed. */
final class SamlIdentity
{
    /** @param list<string> $groups */
    public function __construct(
        public readonly string $objectId,
        public readonly string $tenantId,
        public readonly string $nameId,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly array $groups,
        public readonly string $assertionId,
    ) {
    }

    public function emailDomain(): ?string
    {
        return $this->email && str_contains($this->email, '@') ? substr(strrchr($this->email, '@'), 1) : null;
    }
}
