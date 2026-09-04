<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Close the Laravel session and hand the visitor back to Joomla.
 *
 * The destination is the Joomla site, never this application's own login
 * route: that route is the handoff, and the Joomla session outlives the one
 * being closed here — bouncing through it would sign the user straight back
 * in, so the button would read as broken. Joomla holds the credentials and is
 * the only place able to end its own session, which it has no endpoint for
 * today; until it does, leaving the application is as far as logging out goes.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away((string) config('joomla.site_url'));
    }
}
