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
        'admin.csv-exports' => [
            'component' => 'admin/ComingSoon',
            'title' => 'Exports CSV',
            'body' => 'Export des statistiques agrégées par assureur, pour les notes de plaidoyer. Jamais de données individuelles.',
            'increment' => 'PRÉVU EN V1.1',
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
