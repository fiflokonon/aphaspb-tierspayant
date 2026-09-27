<?php

namespace App\Http\Controllers\Pharmacy;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Pharmacy\PharmacyPenaltyLedger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le journal mensuel des pénalités d'une officine, et ses trois exports.
 *
 * Distinct de NetworkPenaltyLedgerController pour la même raison que les deux
 * exports : celui-ci nomme une officine et ne retient rien, l'autre ne nomme
 * personne et retient sous le seuil.
 *
 * L'officine vient toujours de la session, jamais de la requête.
 */
class PharmacyPenaltyLedgerController extends Controller
{
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected PharmacyPenaltyLedger $ledger)
    {
        //
    }

    public function index(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request, $pharmacy);

        [$from, $to] = $period->bounds();

        return Inertia::render('pharmacy/PenaltyLedger', [
            'penaltyTrend' => Inertia::defer(
                fn () => $this->ledger->for($pharmacy, $from, $to, $insurerId)->toArray(),
            ),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'insurer' => $insurerId,
            'insurers' => $this->ledger->clauseInsurers($pharmacy)
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->values(),
            'downloadUrl' => route('pharmacy.penalty-ledger.download', absolute: false),
            'pharmacyName' => $pharmacy->name,
        ]);
    }

    /**
     * L'assureur demandé, seulement s'il est sous convention **et** déclaré par
     * cette officine : un identifiant forgé retombe sur « tous », jamais sur
     * les chiffres d'un autre.
     */
    protected function insurerId(Request $request, Pharmacy $pharmacy): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->ledger->clauseInsurers($pharmacy)->contains('id', $requested) ? $requested : null;
    }

    /**
     * Provisoire : les trois formats arrivent avec les sources de lignes.
     */
    public function download(): never
    {
        abort(404);
    }
}
