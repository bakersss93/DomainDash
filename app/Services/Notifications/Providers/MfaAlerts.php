<?php

namespace App\Services\Notifications\Providers;

use App\Models\User;
use App\Services\Notifications\AlertProvider;

class MfaAlerts implements AlertProvider
{
    public function alerts(): array
    {
        try {
            $missing = User::role('Administrator')->get()->reject(fn (User $u) => $u->hasConfiguredMfa())->count();
        } catch (\Throwable) {
            return [];
        }

        if ($missing === 0) {
            return [];
        }

        return [[
            'key' => 'mfa-admins',
            'severity' => 'warning',
            'title' => 'MFA not configured for administrators',
            'text' => $missing === 1 ? '1 administrator has no multi-factor authentication set up.' : "{$missing} administrators have no multi-factor authentication set up.",
            'url' => route('admin.users'),
            'at' => null,
        ]];
    }
}
