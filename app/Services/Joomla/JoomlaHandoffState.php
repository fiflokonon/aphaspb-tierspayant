<?php

namespace App\Services\Joomla;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Binds a handoff to the browser that asked for it.
 *
 * Without it the callback accepts any valid ticket from anyone: an attacker
 * can make a visitor's browser post a ticket of their own and sign that
 * visitor into the attacker's account without either of them noticing. The
 * signature on the ticket proves who Joomla authenticated, never who asked.
 *
 * The value rides in a cookie of its own rather than in the session because
 * Joomla returns the ticket through a cross-site POST, and a SameSite=Lax
 * cookie — which the session cookie is — is not sent on those. SameSite=None
 * therefore requires Secure; browsers make an exception for http://localhost,
 * so local development still works.
 */
class JoomlaHandoffState
{
    public const COOKIE = 'joomla_handoff_state';

    /**
     * How long a minted state stays usable.
     *
     * It has to cover the whole human leg on Joomla's side: reaching the login
     * form, digging out a password, and clearing the MFA captive page with a
     * phone in hand. Five minutes did not, and an expired handoff is refused
     * with the same opaque 401 as a forged one — nothing the user or the
     * support desk can act on. The replay window is bounded elsewhere, by the
     * 120-second ticket and its single-use jti, so this only has to be
     * generous enough for a person.
     */
    protected const LIFETIME_MINUTES = 15;

    /**
     * Mint a state, queue it on the response, and hand it to the caller for
     * the trip to Joomla.
     */
    public function issue(): string
    {
        $state = Str::random(40);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $state,
            minutes: self::LIFETIME_MINUTES,
            secure: true,
            httpOnly: true,
            sameSite: 'none',
        ));

        return $state;
    }

    public function matches(Request $request): bool
    {
        $expected = $request->cookie(self::COOKIE);
        $provided = $request->input('state');

        if (! is_string($expected) || ! is_string($provided)) {
            return false;
        }

        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
