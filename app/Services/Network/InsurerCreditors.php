<?php

namespace App\Services\Network;

use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The officines an insurer still owes money to, and how much in total.
 *
 * Second bounded exception to the CDC's « no officine name next to a
 * declaration » (decision of 15/10/2026, after DeclarationCompleteness): the
 * association needs to know whom an insurer owes, not how much it owes each.
 * So the officines are named, the amount is never split per officine, and the
 * total is published only when it rests on at least the anonymity threshold
 * of creditor officines — counted at that granularity, the one the names are
 * listed at, not on the declarants.
 *
 * Owed means a declaration of this insurer whose amount received is below the
 * amount invoiced, every month together: the screen answers « to whom, today »,
 * not « over which period ».
 *
 * Under the threshold the total is withheld and the exact count of creditors
 * still shows — it is the length of the named list, which the screen prints
 * anyway.
 */
class InsurerCreditors
{
    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    /**
     * @return array{creditors: Collection<int, Pharmacy>, outstanding: array{withheld: bool, withheldReason: 'too-few'|'city-share'|null, required: int, amount: int|null}}
     */
    public function for(Insurer $insurer): array
    {
        $minimum = $this->settings->anonymityMinPharmacies();

        $rows = DB::table('declarations')
            ->join('pharmacies', 'pharmacies.id', '=', 'declarations.pharmacy_id')
            ->where('declarations.insurer_id', $insurer->id)
            ->whereColumn('declarations.amount_invoiced', '>', 'declarations.amount_received')
            ->groupBy('declarations.pharmacy_id', 'pharmacies.city')
            ->select('declarations.pharmacy_id')
            ->selectRaw(CityPartition::canonicalCitySql('pharmacies').' as city')
            ->selectRaw('SUM(declarations.amount_invoiced - declarations.amount_received) as owed')
            ->get();

        $perCity = [];

        foreach ($rows as $row) {
            $key = CityPartition::key($row->city);
            $perCity[$key] = ($perCity[$key] ?? 0) + 1;
        }

        $reason = match (true) {
            $rows->isNotEmpty() && $rows->count() < $minimum => 'too-few',
            // Le total moins les chiffres publiés de chaque ville rendrait les
            // créanciers des villes retenues : même règle que le réseau.
            CityPartition::withholds($perCity, $minimum) => 'city-share',
            default => null,
        };

        return [
            // Supprimées exclues de la liste, pas du total : la dette d'un
            // assureur envers une officine partie reste une dette.
            'creditors' => Pharmacy::query()
                ->whereIn('id', $rows->pluck('pharmacy_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'city']),
            'outstanding' => [
                'withheld' => $reason !== null,
                'withheldReason' => $reason,
                'required' => $minimum,
                'amount' => $reason === null ? (int) $rows->sum('owed') : null,
            ],
        ];
    }
}
