<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Pharmacies\SyncTitulaireName;
use App\Data\JoomlaClaims;
use App\Data\JoomlaProfile;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Joomla\JoomlaApiClient;
use App\Services\Joomla\JoomlaHandoffState;
use App\Services\Joomla\JoomlaTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Trade a single-use Joomla ticket for a Laravel session.
 *
 * Inertia is stateful and does not work with an Authorization header, so every
 * page of this application rides the session-backed "web" guard. The JWT is
 * spent here and never again.
 *
 * The route is exempt from CSRF verification — Joomla posts to it from another
 * origin and holds no token of ours. What stands in its place is the handoff
 * state, which is stricter: a forged post carries no matching cookie, and a
 * replayed ticket is refused a second time regardless.
 */
class JoomlaCallbackController extends Controller
{
    public function __construct(
        protected JoomlaTicket $ticket,
        protected JoomlaApiClient $joomla,
        protected JoomlaHandoffState $state,
        protected SyncTitulaireName $syncTitulaireName,
    ) {
        //
    }

    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($this->state->matches($request), 401);

        $this->state->forget();

        $claims = $this->ticket->consume((string) $request->input('token', ''));

        abort_if($claims === null, 401);

        if (! $this->mayEnter($claims)) {
            return redirect()->route('auth.denied');
        }

        $profile = $this->joomla->profile($claims->joomlaUserId);

        abort_if($profile === null, 401);

        if ($this->emailBelongsToAnotherAccount($profile, $claims)) {
            return redirect()->route('auth.email-conflict');
        }

        $user = $this->synchronise($claims, $profile);

        // Joomla has just told us how this person spells their name; the
        // officines they hold are the last place still carrying an older
        // answer.
        $this->syncTitulaireName->handle($user);

        Auth::login($user, remember: false);

        $request->session()->regenerate();
        $request->session()->put('joomla.token_version_checked_at', now()->getTimestamp());

        return redirect()->intended($user->landingRoute());
    }

    /**
     * Determine whether this Joomla account has any business here at all.
     *
     * Joomla authenticates the whole association's site; only a fraction of its
     * members hold an officine or a seat on the network. Their groups are read
     * from the signed ticket, so the answer is known before Joomla is called
     * again and before anything is written: someone turned away here leaves no
     * shadow user and no session behind.
     */
    protected function mayEnter(JoomlaClaims $claims): bool
    {
        $applicant = new User(['joomla_groups' => $claims->groups]);

        return Gate::forUser($applicant)->allows('access-tierspayant');
    }

    /**
     * Determine whether this Joomla account's email is already someone else's.
     *
     * The shadow table holds one row per Joomla account and a unique email,
     * which Joomla itself is not obliged to guarantee: "Require Unique Email"
     * is a switch in its user options, and an account deleted then recreated
     * comes back under a new id carrying the same address. Writing anyway
     * either crashes on users_email_unique or, if the row were adopted, would
     * hand the newcomer another officine's declarations and private notes.
     *
     * So the handoff stops here, before any write and before any session: the
     * two accounts have to be reconciled by a human who can tell whether they
     * are the same person.
     */
    protected function emailBelongsToAnotherAccount(JoomlaProfile $profile, JoomlaClaims $claims): bool
    {
        $holder = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($profile->email)])
            ->where('joomla_user_id', '!=', $claims->joomlaUserId)
            ->first();

        if ($holder === null) {
            return false;
        }

        // Volontairement sans l'adresse : la trace doit suffire à rapprocher
        // les deux comptes sans recopier une donnée personnelle dans les logs.
        Log::warning('Handoff Joomla refusé : email déjà rattaché à un autre compte.', [
            'incoming_joomla_user_id' => $claims->joomlaUserId,
            'existing_user_id' => $holder->id,
            'existing_joomla_user_id' => $holder->joomla_user_id,
        ]);

        return true;
    }

    /**
     * Mirror the Joomla account locally, hydrating the profile server-side.
     */
    protected function synchronise(JoomlaClaims $claims, JoomlaProfile $profile): User
    {
        $user = User::query()->firstOrNew(['joomla_user_id' => $claims->joomlaUserId]);

        $user->forceFill([
            'name' => $profile->name,
            'email' => $profile->email,
            'email_verified_at' => $profile->isVerified ? ($user->email_verified_at ?? now()) : null,
            'joomla_groups' => $claims->groups,
            'token_version' => $claims->tokenVersion,
        ])->save();

        return $user;
    }
}
