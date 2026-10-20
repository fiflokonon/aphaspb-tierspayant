<?php

namespace App\Actions\Declarations;

use App\Models\NilDeclaration;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * « Pas de facture ce mois », for one insurer or for every one still open.
 *
 * Three ways in, one writer: the dedicated button of the round, a zero amount
 * typed in the declaration form, and « aucune facture pour les assureurs
 * restants » (form checkbox or dashboard). An insurer already declared for the
 * month is never touched: the invoice is what counts.
 */
class RecordNilDeclarations
{
    /**
     * @return int How many insurers the month now reports without invoice through this call.
     */
    public function handle(Pharmacy $pharmacy, int $year, int $month, User $author, ?int $insurerId = null): int
    {
        $insurers = $pharmacy->insurers()
            ->when($insurerId !== null, fn ($query) => $query->whereKey($insurerId))
            ->whereDoesntHave('declarations', fn ($query) => $query
                ->where('pharmacy_id', $pharmacy->id)
                ->where('period_year', $year)
                ->where('period_month', $month))
            ->pluck('insurers.id');

        DB::transaction(function () use ($insurers, $pharmacy, $year, $month, $author) {
            foreach ($insurers as $id) {
                NilDeclaration::query()->firstOrCreate(
                    [
                        'pharmacy_id' => $pharmacy->id,
                        'insurer_id' => $id,
                        'period_year' => $year,
                        'period_month' => $month,
                    ],
                    [
                        'user_id' => $author->id,
                        'author_name' => $author->name,
                    ],
                );
            }
        });

        return $insurers->count();
    }
}
