<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Admin switches (Settings > Vocus > Ordering) that let customers raise internet order / cancellation REQUESTS.
 * Both default to off. They only ever create a support ticket; a carrier order is always placed by staff.
 */
class InternetCustomerSettings
{
    public static function allowNewRequests(): bool
    {
        return self::flag('allow_new');
    }

    public static function allowCancellationRequests(): bool
    {
        return self::flag('allow_cancel');
    }

    private static function flag(string $key): bool
    {
        $vocus = Setting::get('vocus', []);
        $requests = is_array($vocus) ? ($vocus['customer_requests'] ?? []) : [];

        return is_array($requests) && filter_var($requests[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
