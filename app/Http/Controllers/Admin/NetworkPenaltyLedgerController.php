<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Network\NetworkPenaltyJournal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le journal mensuel des pénalités du réseau, et ses trois exports.
 *
 * Ne nomme aucune officine. Toute rétention se décide dans
 * NetworkPenaltyJournal : ce contrôleur n'en prend aucune.
 */
class NetworkPenaltyLedgerController extends Controller
{
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(protected NetworkPenaltyJournal $journal)
    {
        //
    }

    public function index(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request);

        [$from, $to] = $period->bounds();

        return Inertia::render('admin/PenaltyLedger', [
            'penaltyTrend' => Inertia::defer(
                fn () => $this->journal->for($from, $to, $city, $insurerId)->toArray(),
            ),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),
            'insurer' => $insurerId,
            'insurers' => $this->journal->clauseInsurers()
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->values(),
            'downloadUrl' => route('admin.penalty-ledger.download', absolute: false),
        ]);
    }

    /**
     * Un identifiant hors liste retombe sur « tous » : un fichier vide se
     * lirait « rien couru », pas « filtre sans objet ».
     */
    protected function insurerId(Request $request): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->journal->clauseInsurers()->contains('id', $requested) ? $requested : null;
    }

    /**
     * Provisoire : les trois formats arrivent avec les sources de lignes.
     */
    public function download(): never
    {
        abort(404);
    }
}
