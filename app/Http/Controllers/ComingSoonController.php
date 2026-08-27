<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Render an explained waiting page for a navigation entry not yet built.
 *
 * The canvas treats a missing feature the way it treats an insurer below the
 * anonymity threshold: a calm, explained state, never an error or a dead link.
 * The copy is keyed by route name so the routes stay cacheable.
 */
class ComingSoonController extends Controller
{
    /**
     * @var array<string, array{component: string, title: string, body: string, increment: string}>
     */
    protected const COPY = [
        'admin.pharmacies' => [
            'component' => 'admin/ComingSoon',
            'title' => 'Pharmacies inscrites',
            'body' => "La liste des officines inscrites — nom, ville, date d'inscription — sans aucun accès à leurs déclarations ni à leurs montants.",
            'increment' => 'PRÉVU APRÈS LE PILOTE',
        ],
        'admin.insurers' => [
            'component' => 'admin/ComingSoon',
            'title' => 'Gestion des assureurs',
            'body' => "L'ajout, la modification et la désactivation des assureurs et courtiers, ainsi que le réglage du seuil de paiement de référence.",
            'increment' => 'PRÉVU APRÈS LE PILOTE',
        ],
        'admin.csv-exports' => [
            'component' => 'admin/ComingSoon',
            'title' => 'Exports CSV',
            'body' => 'Export des statistiques agrégées par assureur, pour les notes de plaidoyer. Jamais de données individuelles.',
            'increment' => 'PRÉVU EN V1.1',
        ],
        'pharmacy.declare' => [
            'component' => 'pharmacy/ComingSoon',
            'title' => 'Déclarer ce mois',
            'body' => 'Pour chaque assureur coché : combien vous avez facturé, combien vous avez reçu. Le reste dû et le statut se déduisent tout seuls.',
            'increment' => 'INCRÉMENT SUIVANT',
        ],
        'pharmacy.history' => [
            'component' => 'pharmacy/ComingSoon',
            'title' => 'Historique',
            'body' => 'Vos déclarations mois par mois, filtrables par assureur et par année, avec vos montants et vos notes privées.',
            'increment' => 'INCRÉMENT SUIVANT',
        ],
        'pharmacy.insurers' => [
            'component' => 'pharmacy/ComingSoon',
            'title' => 'Mes assureurs',
            'body' => 'Les assureurs avec lesquels votre officine travaille. Modifiable à tout moment.',
            'increment' => 'INCRÉMENT SUIVANT',
        ],
    ];

    public function __invoke(Request $request): Response
    {
        $copy = self::COPY[$request->route()?->getName()] ?? null;

        abort_if($copy === null, 404);

        return Inertia::render($copy['component'], [
            'title' => $copy['title'],
            'body' => $copy['body'],
            'increment' => $copy['increment'],
        ]);
    }
}
