<?php

namespace App\Services\Vocus;

use RuntimeException;
use Throwable;

/**
 * A Vocus WSM SOAP fault (or client-side rate-limit failure).
 *
 * `errorCode` is the WSM-/TCAS- code from <FaultResponse><ErrorCode>, null for
 * pre-processing faults that legitimately carry none. `transactionId` is the
 * fault TransactionID Vocus asks for on support tickets.
 */
class VocusWsmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $transactionId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
