<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Data\PharmacyCompleteness;
use App\Enums\CompletenessState;
use App\Models\Pharmacy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quelles officines ont déclaré un mois, en entier, en partie ou pas du tout.
 *
 * **Seul point du réseau où un nom d'officine côtoie une donnée de
 * déclaration** — exception au CDC décidée le 28/09/2026 (spec suivi des
 * déclarations, §3). Ce lecteur ne lit que des comptes : jamais une ligne de
 * déclaration hydratée, jamais un montant, jamais un assureur nommé. Il ne
 * doit pas grandir au-delà.
 *
 * Même règle que DeclarationCalendar côté officine : complet quand chaque
 * assureur coché a sa déclaration du mois. Deux requêtes quel que soit le
 * nombre d'officines.
 */
class DeclarationCompleteness
{
    /**
     * @return list<PharmacyCompleteness>
     */
    public function forMonth(Period $month, ?string $city = null): array
    {
        $dayAfter = CarbonImmutable::create($month->year, $month->month, 1)->addMonth();

        $pharmacies = Pharmacy::query()
            ->select(['id', 'name', 'city', 'whatsapp_phone'])
            ->withCount('insurers')
            ->whereHas('insurers')
            ->where('created_at', '<', $dayAfter)
            ->when($city, fn ($query, string $filtered) => $query->where('city', $filtered))
            ->orderBy('name')
            ->get();

        // Comptés sur les assureurs encore cochés : une déclaration pour un
        // assureur retiré depuis ne rend pas un mois complet. Le DISTINCT est
        // une précaution, pas une règle : l'index unique
        // `decl_pharmacy_insurer_period_unique` interdit déjà deux lignes pour
        // un même assureur et un même mois, et aucun test ne peut l'en
        // distinguer — ne pas en écrire un qui prétendrait le faire.
        $declared = DB::table('declarations')
            ->join('insurer_pharmacy', function ($join) {
                $join->on('insurer_pharmacy.pharmacy_id', '=', 'declarations.pharmacy_id')
                    ->on('insurer_pharmacy.insurer_id', '=', 'declarations.insurer_id');
            })
            ->where('declarations.period_year', $month->year)
            ->where('declarations.period_month', $month->month)
            ->groupBy('declarations.pharmacy_id')
            ->selectRaw('declarations.pharmacy_id, COUNT(DISTINCT declarations.insurer_id) as declared')
            ->pluck('declared', 'pharmacy_id');

        return array_values($pharmacies->map(function (Pharmacy $pharmacy) use ($declared): PharmacyCompleteness {
            $count = (int) ($declared[$pharmacy->id] ?? 0);

            return new PharmacyCompleteness(
                id: $pharmacy->id,
                name: $pharmacy->name,
                city: $pharmacy->city,
                whatsappPhone: $pharmacy->whatsapp_phone,
                state: match (true) {
                    $count === 0 => CompletenessState::None,
                    $count >= (int) $pharmacy->insurers_count => CompletenessState::Complete,
                    default => CompletenessState::Partial,
                },
            );
        })->all());
    }
}
