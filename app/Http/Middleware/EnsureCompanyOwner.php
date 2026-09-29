<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company routes: the user must be a company owner. The Super Admin is not a
 * company account and is intentionally rejected here.
 */
class EnsureCompanyOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isCompanyOwner()) {
            abort(403, __('messages.errors.company_only'));
        }

        return $next($request);
    }
}
