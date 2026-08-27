<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Open a session without Joomla, for local development only.
 *
 * This bypasses the whole identity chain, so it must never exist outside a
 * developer's machine. The guarantee is structural rather than defensive: the
 * route is only registered when the application is local, so in production it
 * is absent from the router entirely — unreachable, and invisible to
 * `route:list`. A test asserts that absence.
 *
 * The real Joomla flow is untouched: this is a side door, not a replacement.
 */
class LocalLoginController extends Controller
{
    public function __invoke(Request $request, string $profile): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        $user = match ($profile) {
            'admin' => $this->admin(),
            'officine' => $this->officine(),
            default => abort(404),
        };

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->put('joomla.token_version_checked_at', now()->getTimestamp());

        if ($profile === 'admin') {
            return redirect()->route('admin.network');
        }

        return $user->needsOnboarding()
            ? redirect()->route('onboarding.profile')
            : redirect()->route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]);
    }

    /**
     * The APhaSPB network administrator seeded by DemoSeeder.
     */
    protected function admin(): User
    {
        return User::query()
            ->whereJsonContains('joomla_groups', (int) (config('joomla.groups.admin')[0] ?? 8))
            ->firstOr(fn () => User::factory()->networkAdmin()->create([
                'name' => 'Admin APhaSPB',
                'email' => 'admin@aphaspb.local',
            ]));
    }

    /**
     * The demo officine, or any officine if the demo one is absent.
     */
    protected function officine(): User
    {
        return User::query()
            ->where('email', 'titulaire@bonsecours.local')
            ->firstOr(fn () => User::query()
                ->whereJsonContains('joomla_groups', (int) (config('joomla.groups.pharmacy')[0] ?? 2))
                ->firstOr(fn () => User::factory()->create()));
    }
}
