<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Send a visitor to Joomla, which owns the login form.
 *
 * This application has no login page of its own: Joomla authenticates, then
 * posts a single-use ticket back to /auth/callback. The route keeps the name
 * "login" because the framework's auth middleware and the invitation mail both
 * resolve it by name.
 */
class LoginRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($invitation = $request->query('invitation')) {
            $request->session()->put('invitation', $invitation);
        }

        return redirect()->away((string) config('joomla.login_url'));
    }
}
