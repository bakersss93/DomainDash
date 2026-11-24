<?php

namespace App\Services\Monitoring;

use App\Models\ApiKey;
use App\Models\IntegrationRequest;
use App\Support\Redactor;
use App\Support\SyncErrorSanitizer;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Records outbound (HTTP + SOAP) and inbound (API key) integration calls into integration_requests.
 *
 * Guarantees:
 *  - secrets are never stored: header masking, URL query stripping, key-based JSON / XML / form redaction,
 *    text fall-back redaction, optional customer PII masking (see App\Support\Redactor);
 *  - bodies are truncated to monitoring.body_limit bytes;
 *  - a logging failure NEVER propagates into the integration call (everything runs inside safely()).
 *
 * Registered as a scoped service (context is per request / queued job).
 */
class IntegrationRequestLogger
{
    /** Inbound paths whose response bodies carry secrets and are therefore never stored. */
    private const SENSITIVE_PATH = '~(auth-code|password|credential|login|token)~i';

    private static bool $warned = false;

    /** @var array<int, array<string, mixed>> */
    private array $contextStack = [];

    private ?int $runId = null;

    public function __construct(private readonly MonitoringSettings $settings)
    {
    }

    // ------------------------------------------------------------------
    // Context (entity / reference / sync run) attached to the next calls
    // ------------------------------------------------------------------

    /**
     * Run $fn with an entity context, e.g. withContext(['entity_type' => 'domain', 'reference' => 'example.com'], fn () => ...).
     */
    public function withContext(array $context, callable $fn): mixed
    {
        $this->contextStack[] = $context;
        try {
            return $fn();
        } finally {
            array_pop($this->contextStack);
        }
    }

    /** Link following calls to a sync run (cleared by SyncRun::finish/fail and when a queue job ends). */
    public function setRun(?int $runId): void
    {
        $this->runId = $runId;
    }

    private function context(): array
    {
        $merged = [];
        foreach ($this->contextStack as $ctx) {
            $merged = array_merge($merged, $ctx);
        }
        $merged['sync_run_id'] ??= $this->runId;

        return $merged;
    }

    // ------------------------------------------------------------------
    // Laravel Http client (Guzzle middleware)
    // ------------------------------------------------------------------

    /**
     * Guzzle middleware: $pending->withMiddleware($logger->middleware('halo')).
     */
    public function middleware(string $provider): callable
    {
        return function (callable $handler) use ($provider): callable {
            return function (RequestInterface $request, array $options) use ($handler, $provider) {
                $started = microtime(true);
                $requestBody = $this->safeBody($request->getBody());

                try {
                    $promise = $handler($request, $options);
                } catch (\Throwable $e) {
                    // Some handlers (and Http::fake callbacks) throw instead of returning a rejected promise.
                    $this->safely(fn () => $this->recordHttp($provider, $request, $requestBody, null, $e, $this->elapsed($started)));

                    throw $e;
                }

                return $promise->then(
                    function (ResponseInterface $response) use ($provider, $request, $requestBody, $started) {
                        $this->safely(fn () => $this->recordHttp(
                            $provider, $request, $requestBody, $response, null, $this->elapsed($started)
                        ));

                        return $response;
                    },
                    function ($reason) use ($provider, $request, $requestBody, $started) {
                        $this->safely(fn () => $this->recordHttp(
                            $provider, $request, $requestBody, null,
                            $reason instanceof \Throwable ? $reason : new \RuntimeException((string) (is_scalar($reason) ? $reason : 'Request failed')),
                            $this->elapsed($started)
                        ));

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    private function recordHttp(string $provider, RequestInterface $request, ?string $requestBody, ?ResponseInterface $response, ?\Throwable $failure, int $durationMs): void
    {
        $status = $response?->getStatusCode();
        $isError = $failure !== null || ($status !== null && $status >= 400);

        if (! $this->shouldRecord('outbound', $isError)) {
            return;
        }

        $responseBody = $response ? $this->safeBody($response->getBody()) : null;

        [$reqBody, $reqTrunc] = $this->formatBody($requestBody, $request->getHeaderLine('Content-Type'));
        [$respBody, $respTrunc] = $this->formatBody($responseBody, $response?->getHeaderLine('Content-Type') ?? '');

        $url = (string) $request->getUri();
        $ctx = $this->context();
        [$derivedType, $derivedRef] = $this->deriveEntity($request);

        $error = null;
        if ($failure !== null) {
            $error = SyncErrorSanitizer::clean(Redactor::redactText($failure->getMessage()), 500);
        } elseif ($isError) {
            $error = trim("HTTP {$status} " . SyncErrorSanitizer::clean((string) $responseBody, 400));
        }

        $this->store([
            'direction' => IntegrationRequest::OUTBOUND,
            'provider' => $provider,
            'method' => strtoupper($request->getMethod()),
            'url' => Redactor::stripUrl($url),
            'request_headers' => Redactor::maskHeaders($request->getHeaders()),
            'request_body' => $reqBody,
            'request_truncated' => $reqTrunc,
            'response_status' => $status,
            'response_headers' => $response ? Redactor::filterResponseHeaders($response->getHeaders()) : null,
            'response_body' => $respBody,
            'response_truncated' => $respTrunc,
            'duration_ms' => $durationMs,
            'entity_type' => $ctx['entity_type'] ?? $derivedType,
            'reference' => $ctx['reference'] ?? $derivedRef,
            'sync_run_id' => $ctx['sync_run_id'] ?? null,
            'is_error' => $isError,
            'error_message' => $error,
        ]);
    }

    // ------------------------------------------------------------------
    // SOAP (Synergy Wholesale, Vocus WSM)
    // ------------------------------------------------------------------

    /**
     * @param array|string    $request   operation parameters (array) or the request XML (string); credentials are redacted
     * @param mixed           $response  decoded SOAP response (object / array) or null on a fault
     * @param string|null     $statusText provider status string (e.g. "OK", "ERR_LOGIN_FAILED")
     */
    public function logSoap(
        string $provider,
        string $operation,
        string $endpoint,
        array|string $request,
        mixed $response,
        ?\Throwable $fault,
        int $durationMs,
        ?string $reference = null,
        ?string $entityType = null,
        ?string $statusText = null,
    ): void {
        $this->safely(function () use ($provider, $operation, $endpoint, $request, $response, $fault, $durationMs, $reference, $entityType, $statusText) {
            $isError = $fault !== null || ($statusText !== null && str_starts_with(strtoupper($statusText), 'ERR'));

            if (! $this->shouldRecord('outbound', $isError)) {
                return;
            }

            $limit = $this->settings->bodyLimit();

            if (is_array($request)) {
                $safe = Redactor::redact($request);
                if ($this->settings->maskPii()) {
                    $safe = Redactor::maskPii($safe);
                }
                $xml = $this->arrayToXml($operation, $safe);
            } else {
                $xml = Redactor::redactXml($request);
            }
            [$reqBody, $reqTrunc] = $this->finishText($this->prettyXml($xml, $limit), $limit);

            $respBody = null;
            $respTrunc = false;
            $decoded = null;
            if ($response !== null) {
                $decoded = json_decode((string) json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE), true);
                if (is_array($decoded)) {
                    $decoded = Redactor::redact($decoded);
                    if ($this->settings->maskPii()) {
                        $decoded = Redactor::maskPii($decoded);
                    }
                    [$respBody, $respTrunc] = $this->finishText(
                        (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                        $limit
                    );
                }
            }

            $ctx = $this->context();
            $faultText = null;
            if ($fault !== null) {
                $faultText = SyncErrorSanitizer::clean(Redactor::redactText($fault->getMessage()), 500);
                [$respBody, $respTrunc] = $this->finishText('SOAP fault: ' . $faultText, $limit);
            } elseif ($isError) {
                $faultText = SyncErrorSanitizer::clean((string) (is_array($decoded) ? ($decoded['errorMessage'] ?? $statusText) : $statusText), 500);
            }

            $this->store([
                'direction' => IntegrationRequest::OUTBOUND,
                'provider' => $provider,
                'method' => 'SOAP',
                'operation' => mb_substr($operation, 0, 120),
                'url' => Redactor::stripUrl($endpoint),
                'request_headers' => ['content-type' => 'text/xml; charset=utf-8', 'soapaction' => $operation],
                'request_body' => $reqBody,
                'request_truncated' => $reqTrunc,
                'response_status' => $fault !== null ? 500 : 200,
                'response_headers' => $statusText !== null ? ['soap-status' => $statusText] : null,
                'response_body' => $respBody,
                'response_truncated' => $respTrunc,
                'duration_ms' => $durationMs,
                'entity_type' => $ctx['entity_type'] ?? $entityType,
                'reference' => $ctx['reference'] ?? $reference,
                'sync_run_id' => $ctx['sync_run_id'] ?? null,
                'is_error' => $isError,
                'error_message' => $faultText,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Inbound API requests
    // ------------------------------------------------------------------

    public function logInbound(Request $request, ?ApiKey $key, int $status, $response = null, ?float $startedAt = null): void
    {
        $this->safely(function () use ($request, $key, $status, $response, $startedAt) {
            $isError = $status >= 400;
            if (! $this->shouldRecord('inbound', $isError)) {
                return;
            }

            $path = '/' . ltrim($request->path(), '/');
            $sensitive = (bool) preg_match(self::SENSITIVE_PATH, $path);

            $headers = [];
            foreach ($request->headers->all() as $name => $values) {
                $headers[$name] = implode(', ', (array) $values);
            }

            [$reqBody, $reqTrunc] = $sensitive
                ? ['[omitted: sensitive endpoint]', false]
                : $this->formatBody((string) $request->getContent(), (string) $request->header('Content-Type', ''));

            $respBody = null;
            $respTrunc = false;
            $respHeaders = null;
            if ($response !== null && method_exists($response, 'getContent')) {
                $respHeaders = Redactor::filterResponseHeaders($response->headers->all());
                $content = $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
                    || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? false : $response->getContent();
                if ($sensitive) {
                    $respBody = '[omitted: sensitive endpoint]';
                } elseif (is_string($content) && $content !== '') {
                    [$respBody, $respTrunc] = $this->formatBody($content, (string) $response->headers->get('Content-Type', ''));
                }
            }

            $route = $request->route();
            $routeParam = null;
            if ($route) {
                foreach ($route->parameters() as $value) {
                    $routeParam = $value instanceof \Illuminate\Database\Eloquent\Model
                        ? (string) ($value->getAttribute('name') ?? $value->getAttribute('business_name') ?? $value->getKey())
                        : (is_scalar($value) ? (string) $value : null);
                    break;
                }
            }

            $error = null;
            if ($isError) {
                $decoded = is_string($respBody) ? json_decode($respBody, true) : null;
                $error = trim("HTTP {$status} " . SyncErrorSanitizer::clean((string) (is_array($decoded) ? ($decoded['message'] ?? '') : ''), 300));
            }

            $this->store([
                'direction' => IntegrationRequest::INBOUND,
                'provider' => 'api',
                'method' => strtoupper($request->method()),
                'operation' => $route?->getName() ?: ($route ? mb_substr($route->uri(), 0, 120) : null),
                'url' => Redactor::stripUrl($request->fullUrl()),
                'request_headers' => Redactor::maskHeaders($headers),
                'request_body' => $reqBody,
                'request_truncated' => $reqTrunc,
                'response_status' => $status,
                'response_headers' => $respHeaders,
                'response_body' => $respBody,
                'response_truncated' => $respTrunc,
                'duration_ms' => $startedAt !== null ? $this->elapsed($startedAt) : null,
                'entity_type' => $routeParam !== null ? 'api' : null,
                'reference' => $routeParam !== null ? mb_substr($routeParam, 0, 191) : null,
                'api_key_id' => $key?->id,
                'actor' => $key?->name ? mb_substr((string) $key->name, 0, 120) : null,
                'ip' => $request->ip(),
                'is_error' => $isError,
                'error_message' => $error,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    public function shouldRecord(string $direction, bool $isError): bool
    {
        return $isError || $this->settings->level($direction) === 'all';
    }

    private function store(array $attributes): void
    {
        IntegrationRequest::query()->create($attributes + ['created_at' => now()]);
    }

    /** Run $fn and swallow everything: logging must never break an integration call. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('Integration request logging failed: ' . SyncErrorSanitizer::clean($e->getMessage(), 200));
            }
        }
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function safeBody(StreamInterface $stream): ?string
    {
        try {
            $text = (string) $stream;
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            return $text;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Redact, pretty-print and truncate a body.
     *
     * @return array{0: ?string, 1: bool} [stored text, truncated]
     */
    public function formatBody(?string $raw, string $contentType = ''): array
    {
        if ($raw === null || $raw === '') {
            return [null, false];
        }

        $limit = $this->settings->bodyLimit();

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return ['[binary content, ' . strlen($raw) . ' bytes]', false];
        }

        $oversize = strlen($raw) > $limit * 8;
        if ($oversize) {
            $raw = mb_strcut($raw, 0, $limit * 2);
        }

        $trim = ltrim($raw);
        $first = $trim[0] ?? '';
        $out = null;

        if (! $oversize && ($first === '{' || $first === '[')) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $data = Redactor::redact($data);
                if ($this->settings->maskPii()) {
                    $data = Redactor::maskPii($data);
                }
                $out = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        } elseif ($first === '<') {
            $out = $this->prettyXml(Redactor::redactXml($raw), $limit);
        } elseif (stripos($contentType, 'x-www-form-urlencoded') !== false || preg_match('/^[\w.%\[\]-]+=[^\s]*$/', $trim)) {
            parse_str($raw, $form);
            if ($form !== []) {
                $form = Redactor::redact($form);
                if ($this->settings->maskPii()) {
                    $form = Redactor::maskPii($form);
                }
                $out = (string) json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $out ??= Redactor::redactText($raw);

        [$text, $truncated] = $this->finishText($out, $limit);

        return [$text, $truncated || $oversize];
    }

    /** @return array{0: string, 1: bool} */
    private function finishText(string $text, int $limit): array
    {
        if (strlen($text) <= $limit) {
            return [$text, false];
        }

        return [mb_strcut($text, 0, $limit) . "\n… [truncated, " . (strlen($text) - $limit) . ' more bytes]', true];
    }

    private function prettyXml(string $xml, int $limit): string
    {
        if (strlen($xml) > $limit * 2) {
            return $xml;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            // No LIBXML_NOENT / DTD loading: nothing external is ever resolved.
            if ($dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
                $pretty = $dom->saveXML($dom->documentElement);

                return $pretty !== false ? $pretty : $xml;
            }
        } catch (\Throwable) {
            // fall through to the raw (already redacted) text
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml;
    }

    private function arrayToXml(string $root, array $data): string
    {
        $name = $this->xmlName($root);

        return "<{$name}>" . $this->xmlChildren($data) . "</{$name}>";
    }

    private function xmlChildren(array $data): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            $tag = is_int($key) ? 'item' : $this->xmlName((string) $key);
            if (is_object($value)) {
                $value = (array) $value;
            }
            $out .= is_array($value)
                ? "<{$tag}>" . $this->xmlChildren($value) . "</{$tag}>"
                : "<{$tag}>" . htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</{$tag}>";
        }

        return $out;
    }

    private function xmlName(string $name): string
    {
        $name = preg_replace('/[^\w.-]/', '_', $name) ?? 'item';

        return preg_match('/^[A-Za-z_]/', $name) ? $name : '_' . $name;
    }

    /** @return array{0: ?string, 1: ?string} [entity_type, reference] guessed from the URL */
    private function deriveEntity(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);
        if (! empty($query['domain']) && is_string($query['domain'])) {
            return ['domain', mb_substr($query['domain'], 0, 191)];
        }

        $path = trim($request->getUri()->getPath(), '/');
        $path = preg_replace('~^api/~i', '', $path) ?? $path;
        if ($path === '') {
            return [null, null];
        }

        $segments = explode('/', $path);

        return [mb_strtolower(mb_substr($segments[0], 0, 64)), mb_substr($path, 0, 191)];
    }
}
