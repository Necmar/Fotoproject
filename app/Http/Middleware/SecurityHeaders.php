<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every web and API response: no framing
 * (clickjacking), no MIME sniffing, a strict referrer, HSTS on HTTPS and a
 * Content Security Policy. The CSP is skipped while the Vite dev server runs.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), payment=(), usb=()', false);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        if (config('bora.csp') && ! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // wasm-unsafe-eval: the HEIC converter (libheif) is WebAssembly.
                "script-src 'self' 'wasm-unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                // blob:/data: for local previews of selected photos.
                "img-src 'self' blob: data:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "worker-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]), false);
        }

        return $response;
    }
}
