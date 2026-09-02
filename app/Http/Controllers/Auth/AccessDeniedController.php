<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Explain a refused handoff.
 *
 * Reached when Joomla authenticated someone whose groups give no access to the
 * tiers-payant. Nothing was written and no session was opened, so the page is
 * public by construction: it knows nothing about the visitor and says nothing
 * about them either — only what to do next.
 */
class AccessDeniedController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('auth/AccessDenied', [
            'siteUrl' => (string) config('joomla.site_url'),
        ]);
    }
}
