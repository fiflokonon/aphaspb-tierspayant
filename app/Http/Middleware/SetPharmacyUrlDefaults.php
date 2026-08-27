<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetPharmacyUrlDefaults
{
    /**
     * Set the default URL parameters for pharmacy-based routes.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($currentPharmacy = $request->user()?->currentPharmacy) {
            URL::defaults([
                'current_pharmacy' => $currentPharmacy->slug,
                'pharmacy' => $currentPharmacy->slug,
            ]);
        }

        return $next($request);
    }
}
