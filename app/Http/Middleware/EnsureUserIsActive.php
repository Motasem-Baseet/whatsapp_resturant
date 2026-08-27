<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out and invalidates the session of a deactivated user.
 *
 * Login itself already rejects inactive users (see the login component's
 * `is_active` credential constraint), so this middleware exists purely to
 * catch the case where a user's session was already active *before* an
 * owner deactivated them: the very next request they make ends the
 * session instead of continuing to serve authenticated pages.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
