<?php

namespace App\Services\Integrations;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Shared HTTP plumbing for the Halo and IT Glue clients.
 *
 *  - connect + request timeouts
 *  - retry with exponential backoff + jitter, honouring Retry-After,
 *    on 429/5xx/connection errors for idempotent requests (GET) only
 *  - any non-2xx final response is thrown as IntegrationApiException
 */
class IntegrationHttp
{
    public const RETRY_STATUSES = [429, 500, 502, 503, 504];

    public static function pending(string $baseUrl = '', array $headers = []): PendingRequest
    {
        $request = Http::connectTimeout((int) config('integrations.http.connect_timeout', 5))
            ->timeout((int) config('integrations.http.timeout', 30));

        if ($baseUrl !== '') {
            $request = $request->baseUrl($baseUrl);
        }

        return $headers === [] ? $request : $request->withHeaders($headers);
    }

    /**
     * @param Closure():Response $call performs exactly one HTTP request
     */
    public static function send(string $provider, string $method, string $url, Closure $call, bool $idempotent): Response
    {
        $maxAttempts = $idempotent ? max(1, (int) config('integrations.http.max_attempts', 4)) : 1;
        $maxInline = (int) config('integrations.http.max_inline_sleep', 30);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $call();
            } catch (ConnectionException $e) {
                $error = IntegrationApiException::fromConnection($provider, $method, $url, $e);
                if ($attempt >= $maxAttempts) {
                    throw $error;
                }
                self::pause($attempt, null);
                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $error = IntegrationApiException::fromResponse($provider, $method, $url, $response);

            if (! $error->retryable || $attempt >= $maxAttempts) {
                throw $error;
            }

            // A long Retry-After is the caller's problem (queue release), do not block a worker/request.
            if ($error->retryAfter !== null && $error->retryAfter > $maxInline) {
                throw $error;
            }

            self::pause($attempt, $error->retryAfter);
        }
    }

    private static function pause(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null) {
            Sleep::for(max(1, $retryAfter))->seconds();

            return;
        }

        $base = (int) config('integrations.http.base_delay_ms', 500);
        $delay = min(
            (int) config('integrations.http.max_inline_sleep', 30) * 1000,
            $base * (2 ** ($attempt - 1))
        );
        $jitter = random_int(0, (int) ($delay * 0.2));

        Sleep::for($delay + $jitter)->milliseconds();
    }
}
