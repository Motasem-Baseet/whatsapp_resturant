<?php

namespace App\Http\Middleware;

use App\Facades\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant strictly from the authenticated user's own
 * restaurant relationship. Never from request input, so a tenant cannot be
 * chosen or spoofed via query strings, form fields, or headers.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->restaurant_id) {
            Tenant::set($user->restaurant);
        }

        return $next($request);
    }
}
