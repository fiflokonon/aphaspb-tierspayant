<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send an officine that has not finished setting itself up to the onboarding.
 *
 * Declaring requires an officine that knows its city, its titulaire and at
 * least one insurer. Without those the declaration screen has nothing to ask
 * about, so the guard is a redirect rather than a 403.
 */
class EnsureOnboarded
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->needsOnboarding()) {
            return redirect()->route('onboarding.profile');
        }

        return $next($request);
    }
}
