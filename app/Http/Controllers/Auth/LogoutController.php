<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Close the Laravel session and hand the visitor back to Joomla.
 *
 * The destination is the Joomla site, never this application's own login
 * route: that route is the handoff, and the Joomla session outlives the one
 * being closed here — bouncing through it would sign the user straight back
 * in, so the button would read as broken. Joomla holds the credentials and is
 * the only place able to end its own session, which it has no endpoint for
 * today; until it does, leaving the application is as far as logging out goes.
 *
 * The answer goes out through Inertia::location() and not a plain redirect:
 * the button posts over XHR, and an XHR cannot follow a 302 towards another
 * origin — the browser blocks it on CORS and the session ends with the user
 * still looking at the console. Inertia::location() answers 409 instead, which
 * the client turns into a real page visit; a non-Inertia caller still gets the
 * ordinary redirect.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location((string) config('joomla.site_url'));
    }
}
