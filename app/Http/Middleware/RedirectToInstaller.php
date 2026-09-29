<?php

namespace App\Http\Middleware;

use App\Services\System\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the app is installed (.env exists), every request goes to the setup page. */
class RedirectToInstaller
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->isInstalled() || $request->is('install', 'up')) {
            return $next($request);
        }

        return $request->is('api/*')
            ? response()->json(['message' => 'Not installed', 'code' => 'not_installed'], 503)
            : redirect('/install');
    }
}
