<?php

namespace App\Support;

use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkStatsService;
use App\Services\Pharmacy\PharmacyStatsService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Gate;

/**
 * Build the console shell's navigation and sidebar notices.
 *
 * Computed server-side rather than in the layout: the server owns the route
 * names and knows which entry is current, so the front end never duplicates
 * that knowledge. The entries come from artboards 1c and 2a of the canvas.
 *
 * @phpstan-type NavItem array{label: string, href: string, active: bool}
 * @phpstan-type Notice array{tone: string, title: string, body: string}
 */
class ConsoleNavigation
{
    public function __construct(
        protected SettingsRepository $settings,
        protected NetworkStatsService $stats,
        protected PharmacyStatsService $pharmacyStats,
    ) {
        //
    }

    /**
     * The shell descriptor for whichever profile the user belongs to.
     *
     * @return array{space: string|null, nav: list<NavItem>, notices: list<Notice>}|null
     */
    public function forUser(?User $user, string $currentPath): ?array
    {
        if ($user === null) {
            return null;
        }

        if (Gate::forUser($user)->allows('manage-network')) {
            return $this->admin($currentPath);
        }

        if (Gate::forUser($user)->allows('declare-payments')) {
            return $this->pharmacy($user, $currentPath);
        }

        return null;
    }

    /**
     * @return array{space: string|null, nav: list<NavItem>, notices: list<Notice>}
     */
    protected function admin(string $currentPath): array
    {
        return [
            'space' => 'ESPACE ADMIN',
            'nav' => $this->items($currentPath, [
                ['Statistiques réseau', 'admin.network'],
                ['Pharmacies inscrites', 'admin.pharmacies'],
                ['Gestion des assureurs', 'admin.insurers'],
                ['Exports CSV', 'admin.csv-exports'],
                ['Profil & réglages', 'profile.edit'],
            ]),
            'notices' => [
                [
                    'tone' => 'neutral',
                    'title' => 'Vue anonymisée',
                    'body' => "Aucun montant, aucune note privée, aucune déclaration individuelle n'est accessible depuis cet espace.",
                ],
                [
                    'tone' => 'gold',
                    'title' => "Seuil d'affichage",
                    'body' => $this->anonymityNotice(),
                ],
            ],
        ];
    }

    /**
     * @return array{space: string|null, nav: list<NavItem>, notices: list<Notice>}
     */
    protected function pharmacy(User $user, string $currentPath): array
    {
        $pharmacy = $user->currentPharmacy;
        $definitions = [];

        // A titulaire between officines has no dashboard to point at.
        if ($pharmacy !== null) {
            $definitions[] = ['Tableau de bord', 'dashboard', ['current_pharmacy' => $pharmacy->slug]];
        }

        $definitions[] = ['Déclarer ce mois', 'pharmacy.declare'];
        $definitions[] = ['Historique', 'pharmacy.history'];
        $definitions[] = ['Mes assureurs', 'pharmacy.insurers'];
        $definitions[] = ['Profil & réglages', 'profile.edit'];

        return [
            'space' => null,
            'nav' => $this->items($currentPath, $definitions),
            'notices' => $pharmacy === null ? [] : $this->chaseNotice($pharmacy),
        ];
    }

    /**
     * The outstanding balance worth chasing, or nothing when all is settled.
     *
     * @return list<Notice>
     */
    protected function chaseNotice(Pharmacy $pharmacy): array
    {
        $outstanding = $this->pharmacyStats->summary($pharmacy, 12)['outstanding'];

        if ($outstanding === 0) {
            return [];
        }

        $old = $this->pharmacyStats->outstandingBeyond($pharmacy, 60);

        return [[
            'tone' => 'gold',
            'title' => 'Encours à relancer',
            'body' => number_format($outstanding, 0, ',', ' ').' FCFA'
                .($old > 0 ? ', dont '.number_format($old, 0, ',', ' ').' au-delà de 60 jours' : ''),
        ]];
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: array<string, mixed>}>  $definitions
     * @return list<NavItem>
     */
    protected function items(string $currentPath, array $definitions): array
    {
        $items = [];

        foreach ($definitions as $definition) {
            $href = route($definition[1], $definition[2] ?? [], absolute: false);

            $items[] = [
                'label' => $definition[0],
                'href' => $href,
                'active' => $currentPath === $href || str_starts_with($currentPath, $href.'/'),
            ];
        }

        return $items;
    }

    /**
     * Restate the anonymity threshold and how many insurers it currently hides.
     */
    protected function anonymityNotice(): string
    {
        $minimum = $this->settings->anonymityMinPharmacies();
        $masked = $this->stats->maskedInsurerCount();

        // Zéro prend le singulier en français, contrairement à l'anglais.
        $plural = $masked > 1 ? 'assureurs masqués' : 'assureur masqué';

        return "{$minimum} pharmacies minimum · {$masked} {$plural} ce trimestre.";
    }
}
