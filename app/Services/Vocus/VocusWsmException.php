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

    /**
     * Error codes that describe the whole account / credentials / session rather
     * than one request: repeating the call for the next row cannot succeed, so
     * bulk work must stop at once. WSM-4005 only reaches callers after the
     * client has already re-logged in once.
     * WSM-4010 is not in the vendor documents; it is what production returned
     * for a rejected Access Key (inferred, see docs/review/13-vocus-wsm-audit.md).
     */
    public const ACCOUNT_LEVEL_CODES = ['WSM-4005', 'WSM-4010', 'WSM-4020', 'WSM-4050', 'LOCAL-CONFIG', 'LOCAL-LOGIN'];

    public function isAccountLevel(): bool
    {
        return in_array($this->errorCode, self::ACCOUNT_LEVEL_CODES, true);
    }

    /** True for a failure that is the user's configuration (key/cert), not a Vocus fault. */
    public function isConfigurationProblem(): bool
    {
        return in_array($this->errorCode, ['LOCAL-CONFIG', 'LOCAL-LOGIN'], true);
    }
}
