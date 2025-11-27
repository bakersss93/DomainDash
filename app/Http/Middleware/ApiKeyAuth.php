<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\ApiKey;
use App\Models\Setting;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ...$scopes): Response
    {
        $raw = $request->header('x-api-key');
        if(!$raw){ return response()->json(['message'=>'API key required'], 401); }

        $key = ApiKey::where('active', true)->get()->first(function($k) use ($raw){
            return password_verify($raw, $k->key_hash);
        });
        if(!$key){ return response()->json(['message'=>'Invalid API key'], 401); }

        // IP allowlist
        if(!$this->ipAllowed($key->allowed_ips, $request->ip())){
            return response()->json(['message'=>'IP not allowed'], 403);
        }

        // Scope check
        foreach ($scopes as $s){
            if(!$key->allowsScope($s)){
                return response()->json(['message'=>'Scope denied'], 403);
            }
        }

        // Rate limit per hour
        $limiterKey = 'api-key:'.$key->id.':'.date('Y-m-d-H').':'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, (int)$key->rate_limit_per_hour))) {
            return response()->json(['message'=>'Rate limit exceeded'], 429);
        }
        RateLimiter::hit($limiterKey, 3600);

        // attach to request
        $request->attributes->set('api_key', $key);

        $resp = $next($request);

        // Log
        \DB::table('api_access_logs')->insert([
            'api_key_id' => $key->id,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $resp->getStatusCode(),
            'user_agent' => substr($request->userAgent() ?? '',0,255),
            'requested_at' => now(),
        ]);

        return $resp;
    }

    protected function ipAllowed(?string $allowlist, string $ip): bool
    {
        if(!$allowlist || trim($allowlist) === '*') return true;
        $items = array_filter(array_map('trim', explode(',', $allowlist)));
        foreach ($items as $item){
            if ($item === $ip) return true;
            if ($this->cidrMatch($ip, $item)) return true;
        }
        return false;
    }

    protected function cidrMatch(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) return false;
        list($subnet, $mask) = explode('/', $cidr);
        return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) == ip2long($subnet);
    }
}
