<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditRequestActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), ['HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        $route = $request->route();
        $actionName = $route?->getActionName();
        $requestPayload = $this->sanitizedPayload($request);
        $apiKey = $request->attributes->get('api_key');

        AuditLogger::logSystem(
            'http.' . strtolower($request->method()),
            sprintf('Manual HTTP action %s %s', $request->method(), '/' . ltrim($request->path(), '/')),
            [
                'function' => 'http-request',
                'service' => 'http',
                'automated' => false,
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'route_name' => $route?->getName(),
                'controller_action' => $actionName,
                'status_code' => $response->getStatusCode(),
            ],
            [
                'user_email' => $apiKey?->name ? "api-key:{$apiKey->name}" : null,
                'new_values' => [
                    'query' => $request->query(),
                    'payload' => $requestPayload,
                ],
            ]
        );

        return $response;
    }

    private function sanitizedPayload(Request $request): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'api_key',
            'secret',
            'two_factor_code',
            'two_factor_recovery_code',
        ];

        return collect($request->except($sensitiveKeys))
            ->map(function ($value, string $key) use ($sensitiveKeys) {
                if (in_array($key, $sensitiveKeys, true)) {
                    return '[REDACTED]';
                }

                return $value;
            })
            ->all();
    }
}
