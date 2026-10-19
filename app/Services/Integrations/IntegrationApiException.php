<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\Response;

/**
 * Typed failure for any call to an external integration (Halo, IT Glue).
 *
 * Clients throw this instead of returning null/[] so callers never mistake a
 * transient failure for "record not found" (which leads to duplicate creates).
 */
class IntegrationApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $retryable = false,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(string $provider, string $method, string $url, Response $response): self
    {
        $status = $response->status();
        $retryAfter = self::parseRetryAfter($response->header('Retry-After'));

        return new self(
            sprintf('%s API %s %s failed with HTTP %d', ucfirst($provider), strtoupper($method), self::path($url), $status),
            $provider,
            $status,
            $retryAfter,
            in_array($status, IntegrationHttp::RETRY_STATUSES, true),
            mb_substr((string) $response->body(), 0, 500),
        );
    }

    public static function fromConnection(string $provider, string $method, string $url, \Throwable $e): self
    {
        return new self(
            sprintf('%s API %s %s connection error: %s', ucfirst($provider), strtoupper($method), self::path($url), $e->getMessage()),
            $provider,
            null,
            null,
            true,
            null,
            $e,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isAuthFailure(): bool
    {
        return in_array($this->status, [401, 403], true);
    }

    /**
     * 4xx responses that mean "this payload shape / endpoint was rejected"
     * (as opposed to a server/transport failure where the write may or may not
     * have been applied).
     */
    public function isRequestRejected(): bool
    {
        return in_array($this->status, [400, 404, 405, 415, 422], true);
    }

    /** Seconds (numeric) or HTTP-date form of Retry-After. */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $ts = strtotime($value);

        return $ts === false ? null : max(0, $ts - time());
    }

    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path ?: $url;
    }
}
