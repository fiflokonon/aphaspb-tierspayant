<?php

namespace App\Http\Middleware;

use App\Services\Joomla\JoomlaApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-read the Joomla token version so a blocked account loses its session.
 *
 * A JWT cannot be revoked, so the session is the thing revoked instead. The
 * check is throttled per session rather than skipped: dropping it would leave
 * blocked accounts authenticated for the whole session lifetime.
 *
 * An unreachable Joomla leaves the session in place: a CMS outage must not log
 * the whole network out.
 */
class VerifyJoomlaTokenVersion
{
    protected const CHECKED_AT = 'joomla.token_version_checked_at';

    public function __construct(protected JoomlaApiClient $joomla)
    {
        //
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->isDue($request)) {
            return $next($request);
        }

        $profile = $this->joomla->profile($user->joomla_user_id);

        if ($profile === null) {
            return $next($request);
        }

        if ($profile->tokenVersion !== $user->token_version) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        $request->session()->put(self::CHECKED_AT, now()->getTimestamp());

        return $next($request);
    }

    protected function isDue(Request $request): bool
    {
        $checkedAt = (int) $request->session()->get(self::CHECKED_AT, 0);

        return now()->getTimestamp() - $checkedAt
            >= (int) config('joomla.token_version_recheck_seconds');
    }
}
