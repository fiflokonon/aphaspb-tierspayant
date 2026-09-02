<?php

namespace App\Http\Controllers\Auth;

use App\Data\JoomlaClaims;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Joomla\JoomlaApiClient;
use App\Services\Joomla\JoomlaHandoffState;
use App\Services\Joomla\JoomlaTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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

        $user = $this->synchronise($claims);

        abort_if($user === null, 401);

        Auth::login($user, remember: false);

        $request->session()->regenerate();
        $request->session()->put('joomla.token_version_checked_at', now()->getTimestamp());

        return redirect()->intended($this->landingFor($user));
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
     * Mirror the Joomla account locally, hydrating the profile server-side.
     */
    protected function synchronise(JoomlaClaims $claims): ?User
    {
        $profile = $this->joomla->profile($claims->joomlaUserId);

        if ($profile === null) {
            return null;
        }

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

    /**
     * Where a freshly authenticated user lands.
     *
     * The admin console arrives in a later increment; until then the network
     * admin is dropped on the home page.
     */
    protected function landingFor(User $user): string
    {
        if ($user->hasAnyJoomlaGroup(config('joomla.groups.admin'))) {
            return route('admin.network');
        }

        if ($user->needsOnboarding()) {
            return route('onboarding.profile');
        }

        return route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]);
    }
}
