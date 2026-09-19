<?php

namespace App\Http\Controllers\Pharmacy;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Pharmacy\InsurerRelationshipReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Une officine face à un assureur : ce qu'il lui doit, et depuis quand.
 *
 * Contrôleur à part de PharmacyInsurersController, dont le métier est la case
 * à cocher « je travaille avec cet assureur ». Celui-ci n'écrit rien et ne
 * fait que lire — ce sont deux écrans, donc deux contrôleurs, comme partout
 * dans cet espace.
 */
class InsurerRelationshipController extends Controller
{
    /** La fenêtre que l'écran ouvre, tant que l'officine n'en choisit pas d'autre. */
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected InsurerRelationshipReport $report)
    {
        //
    }

    public function __invoke(Request $request, Insurer $insurer): Response
    {
        $pharmacy = $request->user()->currentPharmacy;

        abort_unless($this->isKnownTo($pharmacy, $insurer), 404);

        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        [$from, $to] = $period->bounds();

        $built = $this->report->build($pharmacy, $insurer, $from, $to);

        return Inertia::render('pharmacy/Insurer', [
            'relationship' => $built['summary'],
            'months' => $built['months'],
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'exportUrl' => $this->exportUrl($pharmacy, $insurer, $period),
        ]);
    }

    /**
     * Le lien d'export, seulement quand il filtrerait vraiment.
     *
     * PharmacyExportController::insurerId() ne retient le filtre que pour un
     * assureur auquel l'officine a **déclaré**. Sur un assureur coché mais
     * vierge — cas que cet écran ouvre volontiers — le bouton rendrait le
     * fichier de toute l'officine sans le dire. Mieux vaut pas de bouton qu'un
     * bouton qui exporte autre chose que ce qu'on regarde.
     */
    protected function exportUrl(Pharmacy $pharmacy, Insurer $insurer, StatsPeriod $period): ?string
    {
        if (! $pharmacy->declarations()->where('insurer_id', $insurer->id)->exists()) {
            return null;
        }

        return route('pharmacy.data-exports.download', [
            'insurer' => $insurer->id,
            'period' => $period->value,
        ], absolute: false);
    }

    /**
     * Si cet assureur regarde cette officine, d'une manière ou d'une autre.
     *
     * Déclaré **ou** coché : une officine qui a cessé de travailler avec un
     * assureur garde son passé, et une qui vient de le cocher n'a encore rien
     * déclaré. Un 404 plutôt qu'une page vide, qui se lirait « rien déclaré »
     * et non « pas votre assureur ».
     */
    protected function isKnownTo(Pharmacy $pharmacy, Insurer $insurer): bool
    {
        if ($pharmacy->insurers()->whereKey($insurer->id)->exists()) {
            return true;
        }

        return $pharmacy->declarations()->where('insurer_id', $insurer->id)->exists();
    }
}
