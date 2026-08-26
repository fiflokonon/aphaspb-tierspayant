<?php

namespace App\Http\Controllers\Auth;

use App\Data\JoomlaClaims;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Joomla\JoomlaApiClient;
use App\Services\Joomla\JoomlaTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Trade a single-use Joomla ticket for a Laravel session.
 *
 * Inertia is stateful and does not work with an Authorization header, so every
 * page of this application rides the session-backed "web" guard. The JWT is
 * spent here and never again.
 */
class JoomlaCallbackController extends Controller
{
    public function __construct(
        protected JoomlaTicket $ticket,
        protected JoomlaApiClient $joomla,
    ) {
        //
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $claims = $this->ticket->consume((string) $request->input('token', ''));

        abort_if($claims === null, 401);

        $user = $this->synchronise($claims);

        abort_if($user === null, 401);

        Auth::login($user, remember: false);

        $request->session()->regenerate();
        $request->session()->put('joomla.token_version_checked_at', now()->timestamp);

        return redirect()->intended($this->landingFor($user));
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
            return '/';
        }

        return $user->currentTeam
            ? route('dashboard', ['current_team' => $user->currentTeam->slug])
            : '/';
    }
}
