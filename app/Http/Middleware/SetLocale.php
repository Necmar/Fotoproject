<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale priority: signed-in user's preference, X-Locale header sent by the
 * React app, then the application default (nl).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('bora.locales', ['nl', 'en']);

        $locale = $request->user()?->locale
            ?? $request->header('X-Locale')
            ?? $request->getPreferredLanguage($supported);

        if (in_array($locale, $supported, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
