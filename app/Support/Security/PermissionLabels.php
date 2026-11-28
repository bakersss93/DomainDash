<?php

namespace App\Support\Security;

/**
 * Friendly names for permissions that need an explanation on Admin > Roles & Permissions.
 * Permissions not listed here are shown by their key only.
 */
class PermissionLabels
{
    /** @return array<string,array{label:string,description:string}> */
    public static function all(): array
    {
        return [
            'help.integrations.view' => [
                'label' => 'Help: integration set-up guides',
                'description' => 'Lets staff open the integration configuration articles in Support > Help (API keys, certificates, single sign-on, backups). Staff only: it cannot be granted to customer roles.',
            ],
        ];
    }
}
