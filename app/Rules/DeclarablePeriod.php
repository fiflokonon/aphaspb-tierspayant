<?php

namespace App\Rules;

use App\Models\Declaration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validate a [year, month] pair a pharmacy may still declare.
 *
 * The CDC allows catching up on missed months, but only twelve back: beyond
 * that the recollection is unreliable and the statistics stop being useful.
 */
class DeclarablePeriod implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || count($value) !== 2) {
            $fail('La période doit être un couple année et mois.');

            return;
        }

        [$year, $month] = array_values($value);

        if (! is_numeric($year) || ! is_numeric($month)) {
            $fail('La période doit être numérique.');

            return;
        }

        $year = (int) $year;
        $month = (int) $month;

        if ($month < 1 || $month > 12) {
            $fail('Le mois doit être compris entre 1 et 12.');

            return;
        }

        $declared = $year * 12 + $month;
        $now = now();
        $current = $now->year * 12 + $now->month;

        if ($declared > $current) {
            $fail('Une période future ne peut pas être déclarée.');

            return;
        }

        if ($current - $declared > Declaration::EARLIEST_MONTHS_BACK) {
            $fail('Le rattrapage est limité à 12 mois en arrière.');
        }
    }
}
