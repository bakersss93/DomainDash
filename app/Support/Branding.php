<?php

namespace App\Support;

use App\Models\Setting;

/** Single source of truth for the platform company name (Settings > Branding > Company name). */
class Branding
{
    public static function companyName(): string
    {
        $name = null;
        try {
            $name = data_get((array) Setting::get('branding', []), 'company_name');
        } catch (\Throwable) {
            // settings table unavailable (very early boot): fall through to the app name
        }

        $name = trim((string) $name);
        if ($name !== '') {
            return $name;
        }

        $app = trim((string) config('app.name'));

        return ($app !== '' && $app !== 'Laravel') ? $app : 'DomainDash';
    }
}
