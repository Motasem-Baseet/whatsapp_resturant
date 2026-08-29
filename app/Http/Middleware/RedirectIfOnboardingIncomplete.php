<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an owner whose restaurant has not completed onboarding (Phase
 * 26) to the onboarding flow instead of wherever this middleware is
 * applied.
 *
 * Deliberately applied to the `dashboard` route only, not globally -
 * the dashboard is the natural post-login/post-registration landing
 * page, so gating it there satisfies "redirect to onboarding when
 * incomplete" without blocking every other route in the application.
 * Settings, menu, orders, customers, employees, reports, etc. all
 * remain fully reachable regardless of onboarding state, which is what
 * keeps this both loop-free (onboarding.show itself carries no such
 * middleware) and incapable of trapping an owner who wants to look at
 * something else first.
 *
 * Only ever applies to an authenticated owner with a restaurant - a
 * cashier, kitchen, roleless user, or guest is untouched, so incomplete
 * onboarding by the owner never blocks staff from doing their jobs.
 */
class RedirectIfOnboardingIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasRole('owner') && $user->restaurant && $user->restaurant->onboarding_completed_at === null) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
