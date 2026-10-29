<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Integrations\IntegrationApiException;

/**
 * Turns exceptions / remote response bodies into short, secret-free text that is
 * safe to store on sync_runs.error / domains.*_sync_error and show in the UI.
 */
class SyncErrorSanitizer
{
    public static function clean(?string $text, int $max = 500): string
    {
        $text = (string) $text;

        // Known secrets from settings (never echo them back).
        foreach (['halo' => ['api_key', 'client_id'], 'itglue' => ['api_key']] as $group => $keys) {
            try {
                $settings = Setting::get($group, []);
            } catch (\Throwable) {
                $settings = [];
            }
            foreach ($keys as $key) {
                $secret = is_array($settings) ? ($settings[$key] ?? null) : null;
                if (is_string($secret) && strlen($secret) >= 6) {
                    $text = str_replace($secret, '[redacted]', $text);
                }
            }
        }

        $patterns = [
            '/(bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i' => '$1[redacted]',
            '/("?(?:access_token|refresh_token|client_secret|api_key|apikey|password|secret|token)"?\s*[:=]\s*)("[^"]*"|[^\s&,}]+)/i' => '$1[redacted]',
            '/([?&](?:access_token|client_secret|api_key|apikey|token|key)=)[^&\s]+/i' => '$1[redacted]',
        ];
        $text = preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;

        // Strip control characters / markup noise, collapse whitespace.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /** Message for an exception, including the (sanitized, truncated) remote body for HTTP errors. */
    public static function describe(\Throwable $e, int $max = 500): string
    {
        $message = $e->getMessage();

        if ($e instanceof IntegrationApiException && $e->responseBody) {
            $message .= ' - ' . $e->responseBody;
        }

        if ($message === '') {
            $message = class_basename($e);
        }

        return self::clean($message, $max);
    }
}
