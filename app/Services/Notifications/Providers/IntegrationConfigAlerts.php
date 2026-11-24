<?php

namespace App\Services\Notifications\Providers;

use App\Casts\EncryptedSettingValue;
use App\Models\Setting;
use App\Services\Halo\HaloPsaClient;
use App\Services\Notifications\AlertProvider;
use App\Services\Vocus\VocusWsmClient;

/**
 * Integrations that are saved but unusable: stored credentials that cannot be decrypted (APP_KEY changed) or a
 * partially filled configuration. A never-configured integration is not a problem.
 */
class IntegrationConfigAlerts implements AlertProvider
{
    private const PROVIDERS = [
        'halo' => ['HaloPSA', 'halopsa'],
        'itglue' => ['IT Glue', 'itglue'],
        'vocus' => ['Vocus Wholesale', 'vocus'],
        'synergy' => ['Synergy Wholesale', 'synergy'],
    ];

    public function alerts(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $key => [$label, $area]) {
            $settings = [];
            try {
                $settings = (array) Setting::get($key, []);
            } catch (\Throwable) {
            }

            $url = route('admin.settings.area', $area);

            if (! empty(EncryptedSettingValue::$decryptFailures[$key])) {
                $out[] = [
                    'key' => "config:{$key}:decrypt",
                    'severity' => 'error',
                    'title' => "{$label} settings unreadable",
                    'text' => "The saved {$label} credentials could not be decrypted (was APP_KEY changed?). Re-enter them.",
                    'url' => $url,
                    'at' => null,
                ];
                continue;
            }

            $problem = null;
            if ($settings !== []) {
                if ($key === 'halo') {
                    $problem = HaloPsaClient::configurationProblem();
                } elseif ($key === 'vocus') {
                    $problem = VocusWsmClient::configurationProblem($settings);
                }
            }

            if ($problem !== null) {
                $out[] = [
                    'key' => "config:{$key}:incomplete",
                    'severity' => 'warning',
                    'title' => "{$label} is not fully configured",
                    'text' => $problem,
                    'url' => $url,
                    'at' => null,
                ];
            }
        }

        return $out;
    }
}
