<?php

namespace App\Services\Synergy;

use RuntimeException;
use Throwable;

/**
 * Raised when Synergy Wholesale reports a failure (status=ERR_*) or the SOAP
 * transport itself fails (SoapFault, timeout, bad WSDL).
 *
 * Synergy reports API failures as a normal response with `status` = ERR_* and
 * a human `errorMessage` (API docs v3.18, "API Error Handling"), not as a
 * SoapFault, so the client has to turn them into exceptions itself.
 */
class SynergyApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $apiStatus = null,
        public readonly ?string $operation = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** True when the failure came from the transport rather than an API status. */
    public function isTransportError(): bool
    {
        return $this->apiStatus === null;
    }
}
