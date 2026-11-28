<?php

namespace App\Services\Saml;

use RuntimeException;

/** A sign-in refusal. getMessage() is safe to show the user; $reason is for the audit log only. */
class SamlException extends RuntimeException
{
    public function __construct(string $publicMessage, public readonly string $reason = 'denied', public readonly array $context = [])
    {
        parent::__construct($publicMessage);
    }
}
