<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The application has no public front page.
 *
 * Everything here sits behind a Joomla account, so whoever reaches the root
 * URL is one of two people: someone holding a session, sent on to the screen
 * their role starts on, or a stranger, handed to Joomla to authenticate. A
 * welcome page served to the second told anyone who guessed the address that
 * the tiers-payant exists, who it is for, and what it holds.
 */
class HomeRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        return redirect()->to($user->landingRoute());
    }
}
