<?php

namespace App\Http\Middleware;

use App\Models\PharmacyInvitation;
use App\Support\ConsoleNavigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'console' => fn () => app(ConsoleNavigation::class)->forUser($user, $request->getPathInfo()),
            // La pastille de la cloche vit sur tous les écrans du shell, donc
            // ici plutôt que dans chaque contrôleur. Fermeture paresseuse : les
            // deux comptages ne sont payés que lorsque la prop est réellement
            // sérialisée.
            'notificationCount' => fn () => $user === null
                ? 0
                : $user->unreadNotifications()->count() + PharmacyInvitation::pendingForEmail($user->email)->count(),
            'currentPharmacy' => fn () => $user?->currentPharmacy ? $user->toUserPharmacy($user->currentPharmacy) : null,
            'pharmacies' => fn () => $user?->toUserPharmacies(includeCurrent: true) ?? [],
        ];
    }
}
