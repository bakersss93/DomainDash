<?php

namespace App\Services\Synergy;

/**
 * ERR_LOGIN_FAILED / ERR_RESELLER_AUTH_FAILED: bad credentials or the server
 * egress IP is not on the Synergy allow-list.
 */
class SynergyAuthException extends SynergyApiException
{
}
