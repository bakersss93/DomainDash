<?php

namespace App\Support;

use App\Models\Setting;

class MailSettings
{
    public static function apply(array $smtp): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $smtp['host'],
            'mail.mailers.smtp.port' => (int) $smtp['port'],
            'mail.mailers.smtp.username' => $smtp['username'] ?? null,
            'mail.mailers.smtp.password' => $smtp['password'] ?? null,
            'mail.mailers.smtp.encryption' => $smtp['encryption'] ?? null,
            'mail.from.address' => $smtp['from'],
            'mail.from.name' => $smtp['from_name'] ?? config('app.name', 'DomainDash'),
        ]);
    }

    public static function applyFromDatabase(): bool
    {
        $smtp = Setting::get('smtp', []);

        if (! self::isConfigured($smtp)) {
            return false;
        }

        self::apply($smtp);

        return true;
    }

    public static function isConfigured(array $smtp): bool
    {
        return ! empty($smtp['host'])
            && ! empty($smtp['port'])
            && ! empty($smtp['from']);
    }
}
