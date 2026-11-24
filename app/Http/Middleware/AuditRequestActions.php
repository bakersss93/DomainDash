<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\Monitoring\MonitoringSettings;
use App\Support\Monitoring\AuditDescriber;
use App\Support\Monitoring\ChangeTracker;
use App\Support\Redactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activity log for mutating requests only (POST / PUT / PATCH / DELETE).
 *
 *  - GET page views, HEAD and OPTIONS are never recorded (unless Monitoring > Verbose is on).
 *  - Read-only POSTs (search, lookup, validate, preview ...) and 401/404/405/419/422/429 responses are skipped.
 *  - When a controller already wrote its own audit event during the request, no second generic row is added.
 *  - Otherwise one row is written with a readable description ("Updated client Example Pty Ltd") and the
 *    old -> new field diff collected by ChangeTracker.
 */
class AuditRequestActions
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Responses that are not an action worth auditing. */
    private const SKIP_STATUS = [401, 404, 405, 419, 422, 429];

    /** POST endpoints that only read / preview / validate / authenticate (auth has its own events). */
    private const READ_ONLY_URI = '~(search|lookup|validate|validate-au|preview|preview-mapping|availability|qualify|decode-csr|toggle-dark|test-smtp|/read$|/dismiss$|^login$|^logout$|^two-factor|^forgot-password|^reset-password|^livewire)~';

    public function handle(Request $request, Closure $next): Response
    {
        $mutating = in_array($request->method(), self::MUTATING, true);
        $settings = app(MonitoringSettings::class);
        $verbose = $settings->verbose();

        if (! $mutating && ! $verbose) {
            return $next($request);
        }

        $before = AuditLogger::$explicitWrites;
        if ($mutating) {
            ChangeTracker::start();
        }

        try {
            $response = $next($request);
        } finally {
            $changes = $mutating ? ChangeTracker::stop() : [];
        }

        try {
            $this->record($request, $response, $changes, $mutating, $verbose, AuditLogger::$explicitWrites > $before);
        } catch (\Throwable $e) {
            // Auditing must never break the request it describes.
            report($e);
        }

        return $response;
    }

    private function record(Request $request, Response $response, array $changes, bool $mutating, bool $verbose, bool $alreadyAudited): void
    {
        if (in_array($request->method(), ['HEAD', 'OPTIONS'], true)) {
            return;
        }

        $status = $response->getStatusCode();
        $route = $request->route();
        $uri = $route ? $route->uri() : trim($request->path(), '/');
        $path = '/' . ltrim($request->path(), '/');
        $apiKey = $request->attributes->get('api_key');

        if (! $mutating) {
            // Verbose mode only: page views.
            AuditLogger::logSystem('http.get', 'Viewed ' . $path, [
                'function' => 'http-request', 'service' => 'http', 'automated' => false,
                'method' => $request->method(), 'path' => $path, 'route_name' => $route?->getName(), 'status_code' => $status,
            ], ['user_email' => $apiKey?->name ? "api-key:{$apiKey->name}" : null]);

            return;
        }

        if (in_array($status, self::SKIP_STATUS, true) || preg_match(self::READ_ONLY_URI, $uri)) {
            return;
        }

        if ($alreadyAudited && ! $verbose) {
            return;
        }

        $described = AuditDescriber::describe($request, $changes);
        $failed = $status >= 400;
        $description = $described['description'] . ($failed ? ($status === 403 ? ' (denied)' : " (failed, HTTP {$status})") : '');

        $old = [];
        $new = [];
        foreach ($changes as $change) {
            $prefix = count($changes) > 1 ? $change['type'] . ' ' . $change['label'] . ': ' : '';
            foreach ((array) $change['old'] as $field => $value) {
                $old[$prefix . $field] = $value;
            }
            foreach ((array) $change['new'] as $field => $value) {
                $new[$prefix . $field] = $value;
            }
        }
        if ($changes === []) {
            $new['payload'] = $this->sanitizedPayload($request);
        }

        $first = $changes[0] ?? null;
        $auditable = $first && class_exists($first['model']) ? $first['model'] : 'Request';

        AuditLogger::log($failed ? $described['action'] . '.failed' : $described['action'], $auditable, [
            'auditable_id' => $first['id'] ?? null,
            'description' => mb_substr($description, 0, 500),
            'user_email' => $apiKey?->name ? "api-key:{$apiKey->name}" : null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'context' => [
                'function' => 'http-request',
                'service' => $described['module'],
                'automated' => false,
                'method' => $request->method(),
                'path' => $path,
                'route_name' => $route?->getName(),
                'status_code' => $status,
                'reference' => $described['label'] !== '' ? $described['label'] : null,
                'query' => $request->query() ? Redactor::redact($request->query()) : null,
            ],
        ]);
    }

    private function sanitizedPayload(Request $request): array
    {
        // Uploaded files (certificates, PFX, logos) are logged as a descriptor, never their content.
        $input = $request->except(['_token', '_method', '_return']);
        array_walk_recursive($input, function (&$v) {
            if ($v instanceof \Illuminate\Http\UploadedFile) {
                $v = '[file ' . $v->getClientOriginalName() . ', ' . $v->getSize() . ' bytes]';
            } elseif (is_string($v) && mb_strlen($v) > 500) {
                $v = mb_substr($v, 0, 500) . '…';
            }
        });
        $payload = Redactor::redact($input);
        if (app(MonitoringSettings::class)->maskPii()) {
            $payload = Redactor::maskPii($payload);
        }

        return is_array($payload) ? array_slice($payload, 0, 60, true) : [];
    }
}
