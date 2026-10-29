<?php

namespace App\Http\Middleware;

use App\Services\Https\PlatformHttps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects http -> https when an administrator has enabled "Force HTTPS".
 * HSTS is emitted by SecurityHeaders for every secure response.
 * Redirects go to the APP_URL host (never the raw Host header: no open redirect).
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure() || $request->is('up') || ! PlatformHttps::forceEnabled()) {
            return $next($request);
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost();
        $target = 'https://' . $host . $request->getRequestUri();

        return redirect()->to($target, in_array($request->method(), ['GET', 'HEAD'], true) ? 301 : 308);
    }
}
