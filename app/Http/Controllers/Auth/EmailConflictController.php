<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Explain a handoff stopped by a duplicate email address.
 *
 * Reached when Joomla authenticated an account whose email is already carried
 * by another shadow user. Nothing was written and no session was opened, so
 * the page is public by construction — like the refusal page, it knows nothing
 * about the visitor and says nothing about the account it collided with.
 */
class EmailConflictController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('auth/EmailConflict', [
            'siteUrl' => (string) config('joomla.site_url'),
        ]);
    }
}
