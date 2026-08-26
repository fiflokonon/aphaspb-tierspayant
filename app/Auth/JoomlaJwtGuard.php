<?php

namespace App\Auth;

use App\Data\JoomlaClaims;
use App\Models\User;
use App\Services\Joomla\JoomlaTokenDecoder;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Stateless guard for external API clients.
 *
 * Reserved for external consumers: Inertia pages are stateful and go through
 * the session-backed "web" guard. This guard never creates a user — only
 * /auth/callback does, because only it hydrates the profile from Joomla.
 */
class JoomlaJwtGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        protected Request $request,
        protected JoomlaTokenDecoder $decoder,
    ) {
        //
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request->bearerToken();

        if ($token === null) {
            return null;
        }

        $claims = $this->decoder->decode($token);

        if ($claims === null) {
            return null;
        }

        return $this->user = $this->resolve($claims);
    }

    /**
     * This guard authenticates by signed token only, never by credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    protected function resolve(JoomlaClaims $claims): ?User
    {
        $user = User::query()->firstWhere('joomla_user_id', $claims->joomlaUserId);

        if ($user === null || $claims->tokenVersion !== $user->token_version) {
            return null;
        }

        if ($user->joomla_groups !== $claims->groups) {
            $user->forceFill(['joomla_groups' => $claims->groups])->save();
        }

        return $user;
    }
}
