<?php

namespace App\Rules;

use App\Data\Period;
use App\Services\Declarations\DeclarationRange;
use App\Support\MonthLabel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validate a [year, month] pair a pharmacy may still declare.
 *
 * The CDC allows catching up on missed months, down to a floor the admin
 * sets (DeclarationRange): beyond it the recollection is unreliable and the
 * statistics stop being useful.
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

        $range = app(DeclarationRange::class);
        $declared = (new Period($year, $month))->toOrdinal();

        if ($declared > $range->current()->toOrdinal()) {
            $fail('Une période future ne peut pas être déclarée.');

            return;
        }

        $floor = $range->floor();

        if ($declared < $floor->toOrdinal()) {
            $fail('Les déclarations ne remontent pas avant '.mb_strtolower(MonthLabel::long($floor->month, $floor->year)).'.');
        }
    }
}
