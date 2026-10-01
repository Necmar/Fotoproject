<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as BaseMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "guest" middleware. A signed-in user calling a guest-only API route (login,
 * register, forgot password) gets a JSON 409 instead of a redirect: axios
 * would follow the redirect and the SPA would mistake it for success.
 */
class RedirectIfAuthenticated extends BaseMiddleware
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            foreach (empty($guards) ? [null] : $guards as $guard) {
                if (Auth::guard($guard)->check()) {
                    return response()->json([
                        'message' => __('messages.errors.already_authenticated'),
                        'code' => 'already_authenticated',
                    ], 409);
                }
            }
        }

        return parent::handle($request, $next, ...$guards);
    }
}
