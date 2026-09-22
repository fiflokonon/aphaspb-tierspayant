<?php

namespace App\Support;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Build the console shell's navigation.
 *
 * Computed server-side rather than in the layout: the server owns the route
 * names and knows which entry is current, so the front end never duplicates
 * that knowledge. The entries come from artboards 1c and 2a of the canvas.
 *
 * @phpstan-type NavItem array{label: string, href: string, active: bool, icon: string}
 * @phpstan-type Account array{name: string, administrator: bool, logoutHref: string, pharmacy: CurrentPharmacy|null, pharmacies: list<SwitchablePharmacy>}
 * @phpstan-type CurrentPharmacy array{name: string, city: string|null}
 * @phpstan-type SwitchablePharmacy array{name: string, slug: string, switchHref: string, current: bool}
 */
class ConsoleNavigation
{
    /**
     * The shell descriptor for whichever profile the user belongs to.
     *
     * @return array{space: string|null, nav: list<NavItem>, account: Account}|null
     */
    public function forUser(?User $user, string $currentPath): ?array
    {
        if ($user === null) {
            return null;
        }

        $onNetworkSpace = Gate::forUser($user)->allows('manage-network');

        $onPharmacySpace = ! $onNetworkSpace
            && Gate::forUser($user)->allows('declare-payments');

        $shell = match (true) {
            $onNetworkSpace => $this->admin($currentPath),
            Gate::forUser($user)->allows('declare-payments') => $this->pharmacy($user, $currentPath),
            // Ni l'un ni l'autre : une coquille nue, sans navigation. Le
            // compte, lui, est attaché plus bas dans tous les cas — il reste
            // une session à quitter.
            default => ['space' => null, 'nav' => []],
        };

        // Attached here rather than in each shell: the way out of a session
        // does not depend on which space the user landed in, and onboarding —
        // which renders no navigation at all — needs it just as much.
        return [...$shell, 'account' => $this->account($user, $onPharmacySpace, $onNetworkSpace)];
    }

    /**
     * The signed-in identity, the officines it can move between, and its way out.
     *
     * Server-built like the navigation, for the same reason: the shell renders
     * what it is handed and never resolves a route name itself.
     *
     * @return Account
     */
    protected function account(User $user, bool $onPharmacySpace, bool $onNetworkSpace): array
    {
        return [
            'name' => $user->name,
            // L'en-tête annonce l'espace où l'on se trouve, et c'est l'espace
            // qui le dit — pas le libellé `space`, qui est une étiquette
            // d'affichage et changerait au premier remaniement de la barre.
            'administrator' => $onNetworkSpace,
            'logoutHref' => route('auth.logout', absolute: false),
            'pharmacy' => $this->currentPharmacy($user, $onPharmacySpace),
            'pharmacies' => $this->switchablePharmacies($user),
        ];
    }

    /**
     * L'officine que l'en-tête de chaque écran annonce, ou null.
     *
     * Portée par le shell plutôt que par chaque contrôleur : cinq écrans
     * l'affichent, et la faire voyager en prop obligerait autant de
     * contrôleurs à la répéter.
     *
     * C'est **l'espace** qui décide, pas la relation : un compte réseau porte
     * une officine courante en base, et l'afficher au-dessus de chiffres
     * agrégés de tout le Bénin serait un contresens.
     *
     * @return CurrentPharmacy|null
     */
    protected function currentPharmacy(User $user, bool $onPharmacySpace): ?array
    {
        if (! $onPharmacySpace) {
            return null;
        }

        $pharmacy = $user->currentPharmacy;

        if ($pharmacy === null) {
            return null;
        }

        return ['name' => $pharmacy->name, 'city' => $pharmacy->city];
    }

    /**
     * The officines this user may move between, the current one included.
     *
     * Empty below two: a titulaire with a single officine has nothing to
     * choose, and an empty list is what tells the rail to omit the block.
     *
     * @return list<SwitchablePharmacy>
     */
    protected function switchablePharmacies(User $user): array
    {
        $pharmacies = $user->pharmacies()
            ->orderBy('pharmacies.name')
            ->get(['pharmacies.id', 'pharmacies.name', 'pharmacies.slug']);

        if ($pharmacies->count() < 2) {
            return [];
        }

        return array_values($pharmacies->map(fn (Pharmacy $pharmacy) => [
            'name' => $pharmacy->name,
            'slug' => $pharmacy->slug,
            'switchHref' => route('pharmacies.switch', ['pharmacy' => $pharmacy->slug], absolute: false),
            'current' => $user->isCurrentPharmacy($pharmacy),
        ])->all());
    }

    /**
     * @return array{space: string|null, nav: list<NavItem>}
     */
    protected function admin(string $currentPath): array
    {
        return [
            'space' => 'ESPACE ADMIN',
            'nav' => $this->items($currentPath, [
                ['Statistiques réseau', 'admin.network', [], 'chart-column'],
                ['Évolution', 'admin.trends', [], 'trending-up'],
                ['Pharmacies inscrites', 'admin.pharmacies', [], 'store'],
                ['Gestion des assureurs', 'admin.insurers', [], 'building-2'],
                ['Exports CSV', 'admin.csv-exports', [], 'download'],
                // Retirée de la navigation le 31/08/2026. L'écran et sa route
                // existent toujours : seule l'entrée est masquée.
                // ['Profil & réglages', 'profile.edit'],
            ]),
        ];
    }

    /**
     * @return array{space: string|null, nav: list<NavItem>}
     */
    protected function pharmacy(User $user, string $currentPath): array
    {
        $pharmacy = $user->currentPharmacy;
        $definitions = [];

        // A titulaire between officines has no dashboard to point at.
        if ($pharmacy !== null) {
            $definitions[] = ['Tableau de bord', 'dashboard', ['current_pharmacy' => $pharmacy->slug], 'layout-dashboard'];
        }

        $definitions[] = ['Déclarer ce mois', 'pharmacy.declare', [], 'file-plus-2'];
        $definitions[] = ['Historique', 'pharmacy.history', [], 'history'];
        $definitions[] = ['Mes assureurs', 'pharmacy.insurers', [], 'building-2'];
        $definitions[] = ['Exporter mes données', 'pharmacy.data-exports', [], 'download'];
        // Retirée de la navigation le 31/08/2026. L'écran et sa route existent
        // toujours : seule l'entrée est masquée.
        // $definitions[] = ['Profil & réglages', 'profile.edit'];

        return [
            'space' => null,
            'nav' => $this->items($currentPath, $definitions),
        ];
    }

    /**
     * Les définitions sont des tuples : libellé, nom de route, paramètres de
     * route, clé d'icône. Les paramètres restent obligatoires — et souvent
     * vides — pour que l'icône garde une position fixe : une définition à
     * arité variable mettrait l'icône tantôt en 3e, tantôt en 4e place.
     *
     * @param  list<array{0: string, 1: string, 2: array<string, mixed>, 3: string}>  $definitions
     * @return list<NavItem>
     */
    protected function items(string $currentPath, array $definitions): array
    {
        $items = [];

        foreach ($definitions as $definition) {
            $href = route($definition[1], $definition[2], absolute: false);

            $items[] = [
                'label' => $definition[0],
                'href' => $href,
                'active' => $currentPath === $href || str_starts_with($currentPath, $href.'/'),
                // Pas de repli : une entrée sans icône est une erreur de
                // définition, et un `?? ''` la rendrait invisible au lieu de
                // faire rougir NavIconCoverageTest.
                'icon' => $definition[3],
            ];
        }

        return $items;
    }
}
