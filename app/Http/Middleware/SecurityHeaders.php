<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('domaindash.security_headers.enabled', true)) {
            return $response;
        }

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()', false);

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        $headers->set(
            config('domaindash.security_headers.csp_enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            $this->policy(),
            false
        );

        return $response;
    }

    /**
     * Policy compatible with the app as built: inline scripts/styles (Blade pages,
     * Alpine/Livewire) and the Bunny fonts stylesheet. Tightening 'unsafe-inline'
     * requires a nonce rollout. Vite dev-server origins are only allowed when
     * running locally.
     */
    private function policy(): string
    {
        $dev = app()->environment('local') ? ' http://localhost:5173 http://127.0.0.1:5173 http://[::1]:5173 ws://localhost:5173' : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'{$dev}",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$dev}",
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data: https:",
            "connect-src 'self'{$dev}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        if ($uri = config('domaindash.security_headers.csp_report_uri')) {
            $directives[] = 'report-uri ' . $uri;
        }

        return implode('; ', $directives);
    }
}
