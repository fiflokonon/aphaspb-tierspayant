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
            'exportUrls' => $this->exportUrls($pharmacy, $insurer, $period),
        ]);
    }

    /**
     * Les trois formats de l'écran d'export, filtrés sur cet assureur.
     *
     * Les mêmes que `pharmacy/Exports` : le relevé PDF, le classeur XLSX et le
     * fichier CSV brut, servis par le même contrôleur. Un écran qui n'en
     * offrirait qu'un obligerait à repasser par l'écran d'export pour changer
     * de format, en y refaisant à la main le filtre qu'on a déjà sous les yeux.
     *
     * Les URL sont construites ici : le serveur possède les noms de route, et
     * le format est un paramètre de la même route, pas une route par format.
     *
     * Rendu null — donc aucun bouton — quand le filtre ne filtrerait pas.
     * `PharmacyExportController::insurerId()` ne le retient que pour un
     * assureur auquel l'officine a **déclaré** ; sur un assureur coché mais
     * vierge — cas que cet écran ouvre volontiers — les liens rendraient le
     * fichier de toute l'officine sans le dire.
     *
     * @return array{pdf: string, xlsx: string, csv: string}|null
     */
    protected function exportUrls(Pharmacy $pharmacy, Insurer $insurer, StatsPeriod $period): ?array
    {
        if (! $pharmacy->declarations()->where('insurer_id', $insurer->id)->exists()) {
            return null;
        }

        $link = fn (string $format): string => route('pharmacy.data-exports.download', [
            'insurer' => $insurer->id,
            'period' => $period->value,
            'format' => $format,
        ], absolute: false);

        return [
            'pdf' => $link('pdf'),
            'xlsx' => $link('xlsx'),
            'csv' => $link('csv'),
        ];
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
