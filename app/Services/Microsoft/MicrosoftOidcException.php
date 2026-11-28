<?php

namespace App\Services\Microsoft;

use RuntimeException;

class MicrosoftOidcException extends RuntimeException
{
    /** @param array<string,mixed> $context audit context (never secrets) */
    public function __construct(string $message, public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
