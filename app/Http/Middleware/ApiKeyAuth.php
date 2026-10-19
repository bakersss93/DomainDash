<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ...$scopes): Response
    {
        $raw = (string) $request->header('x-api-key', '');
        if ($raw === '') {
            return $this->reject($request, null, 'API key required', 401);
        }

        // Brute-force guard: failed attempts per IP (checked before any key lookup).
        $failKey = 'api-auth-fail:' . $request->ip();
        if (RateLimiter::tooManyAttempts($failKey, 20)) {
            return $this->reject($request, null, 'Too many failed attempts', 429, RateLimiter::availableIn($failKey));
        }

        $key = ApiKey::findByPlain($raw);
        if (! $key || $key->isExpired()) {
            RateLimiter::hit($failKey, 60);

            return $this->reject($request, null, $key ? 'API key expired' : 'Invalid API key', 401);
        }

        if (! $this->ipAllowed($key->allowed_ips, (string) $request->ip())) {
            return $this->reject($request, $key, 'IP not allowed', 403);
        }

        foreach ($scopes as $s) {
            if (! $key->allowsScope($s)) {
                return $this->reject($request, $key, 'Scope denied', 403);
            }
        }

        // Per-key hourly quota (not per IP: a leaked key cannot multiply its budget).
        $limiterKey = 'api-key:' . $key->id . ':' . date('Y-m-d-H');
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, (int) $key->rate_limit_per_hour))) {
            return $this->reject($request, $key, 'Rate limit exceeded', 429, RateLimiter::availableIn($limiterKey));
        }
        RateLimiter::hit($limiterKey, 3600);

        $request->attributes->set('api_key', $key);

        // Avoid a write per request: refresh last_used_at at most once a minute.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $status = 500;
        try {
            $resp = $next($request);
            $status = $resp->getStatusCode();

            return $resp;
        } catch (\Throwable $e) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            throw $e;
        } finally {
            $this->log($request, $key, $status);
        }
    }

    protected function reject(Request $request, ?ApiKey $key, string $message, int $status, ?int $retryAfter = null): Response
    {
        $this->log($request, $key, $status);
        $headers = $retryAfter !== null ? ['Retry-After' => (string) max(1, $retryAfter)] : [];

        return response()->json(['message' => $message], $status, $headers);
    }

    protected function log(Request $request, ?ApiKey $key, int $status): void
    {
        try {
            DB::table('api_access_logs')->insert([
                'api_key_id' => $key?->id,
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => substr($request->path(), 0, 255),
                'status' => $status,
                'user_agent' => substr($request->userAgent() ?? '', 0, 255),
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function ipAllowed(?string $allowlist, string $ip): bool
    {
        if (! $allowlist || trim($allowlist) === '*') {
            return true;
        }

        foreach (array_filter(array_map('trim', explode(',', $allowlist))) as $item) {
            // IpUtils handles IPv4/IPv6 and validates CIDR masks (invalid entries never match).
            if ($item !== '*' && IpUtils::checkIp($ip, $item)) {
                return true;
            }
        }

        return false;
    }
}
