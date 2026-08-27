<?php

namespace App\Http\Middleware;

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePharmacyMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $pharmacy] = [$request->user(), $this->pharmacy($request)];

        abort_if(! $user || ! $pharmacy || ! $user->belongsToPharmacy($pharmacy), 403);

        $this->ensurePharmacyMemberHasRequiredRole($user, $pharmacy, $minimumRole);

        if ($request->route('current_pharmacy') && ! $user->isCurrentPharmacy($pharmacy)) {
            $user->switchPharmacy($pharmacy);
        }

        return $next($request);
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensurePharmacyMemberHasRequiredRole(User $user, Pharmacy $pharmacy, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->pharmacyRole($pharmacy);

        $requiredRole = PharmacyRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    /**
     * Get the pharmacy associated with the request.
     */
    protected function pharmacy(Request $request): ?Pharmacy
    {
        $pharmacy = $request->route('current_pharmacy') ?? $request->route('pharmacy');

        if (is_string($pharmacy)) {
            $pharmacy = Pharmacy::where('slug', $pharmacy)->first();
        }

        return $pharmacy;
    }
}
