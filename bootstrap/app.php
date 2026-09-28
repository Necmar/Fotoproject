<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCompanyOwner;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The JSON API shares the web session (cookie auth + CSRF). The SPA
        // catch-all in routes/web.php excludes /api/*, so order does not matter.
        then: function () {
            Route::middleware('web')->prefix('api')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);

        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
            'company' => EnsureCompanyOwner::class,
            'account.active' => EnsureAccountIsActive::class,
        ]);

        // Guests hitting protected API routes get a 401 JSON instead of a redirect.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->redirectUsersTo('/');

        // Needed behind Plesk's nginx proxy for correct scheme/IP detection.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => __('messages.errors.unauthenticated'), 'code' => 'unauthenticated'], 401);
            }
        });

        // Friendly, translated messages for API errors; never leak internals in production.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() !== '' ? $e->getMessage() : match ($status) {
                    403 => __('messages.errors.forbidden'),
                    404 => __('messages.errors.not_found'),
                    419 => __('messages.errors.session_expired'),
                    429 => __('messages.errors.too_many_requests'),
                    default => __('messages.errors.generic'),
                };

                return response()->json(['message' => $message], $status, $e->getHeaders());
            }

            if ($e instanceof ValidationException
                || $e instanceof ModelNotFoundException
                || $e instanceof AuthorizationException) {
                return null;
            }

            return response()->json(['message' => __('messages.errors.generic')], 500);
        });
    })->create();
