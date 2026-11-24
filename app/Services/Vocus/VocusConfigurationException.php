<?php

namespace App\Services\Vocus;

use Throwable;

/**
 * Vocus is not usable because of local configuration (missing/undecryptable
 * Access Key, missing certificate, login rejected). Thrown before any SOAP
 * call is made. The message is safe to show to administrators: it never
 * contains the Access Key, the certificate password or a server path.
 */
class VocusConfigurationException extends VocusWsmException
{
    public const CODE = 'LOCAL-CONFIG';

    public function __construct(string $message, ?Throwable $previous = null, string $code = self::CODE)
    {
        parent::__construct($message, $code, null, $previous);
    }
}
