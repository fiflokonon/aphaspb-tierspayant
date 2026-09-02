<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Joomla\JoomlaHandoffState;
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
    public function __construct(protected JoomlaHandoffState $state)
    {
        //
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if ($invitation = $request->query('invitation')) {
            $request->session()->put('invitation', $invitation);
        }

        return redirect()->away($this->joomlaUrl($this->state->issue()));
    }

    /**
     * Joomla's entry point, carrying the state it must hand back untouched.
     */
    protected function joomlaUrl(string $state): string
    {
        $url = (string) config('joomla.login_url');

        return $url.(str_contains($url, '?') ? '&' : '?').'state='.urlencode($state);
    }
}
