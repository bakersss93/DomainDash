<?php

namespace App\Services\Microsoft\Partner;

use RuntimeException;

/** A Microsoft Graph / token failure with an operator-safe message (never contains the secret or a token). */
class PartnerGraphException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
