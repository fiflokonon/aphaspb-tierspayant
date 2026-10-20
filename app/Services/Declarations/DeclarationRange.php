<?php

namespace App\Services\Declarations;

use App\Data\Period;
use App\Models\Pharmacy;
use App\Services\Settings\SettingsRepository;

/**
 * Jusqu'où une officine peut déclarer, et à partir de quand on le lui réclame.
 *
 * Deux bornes que l'admin règle, et qu'il ne faut pas confondre :
 *
 * - **le plancher** : le mois le plus ancien que toute officine peut déclarer.
 *   Fixe, il ne glisse pas avec le temps ;
 * - **les mois attendus** : ceux qu'on réclame à une officine, à partir du plus
 *   tardif du plancher et de son mois d'inscription moins N mois. Une officine
 *   arrivée deux ans après le lancement ne se voit pas réclamer deux ans
 *   d'historique ; elle peut toujours les déclarer, jusqu'au plancher.
 *
 * Seule source de ces deux bornes : la validation, le tableau de bord de
 * l'officine et le suivi réseau lisent tous ici, et ne divergent donc pas.
 */
class DeclarationRange
{
    /** Le rattrapage le plus long que l'admin peut exiger d'une nouvelle officine. */
    public const MAX_CATCH_UP_MONTHS = 24;

    public function __construct(protected SettingsRepository $settings)
    {
        //
    }

    public function floor(): Period
    {
        return Period::fromOrdinal($this->settings->declarationFloorOrdinal());
    }

    public function current(): Period
    {
        return new Period(now()->year, now()->month);
    }

    public function catchUpMonths(): int
    {
        return $this->settings->catchUpMonthsBeforeSignup();
    }

    public function isDeclarable(Period $period): bool
    {
        return $period->toOrdinal() >= $this->floor()->toOrdinal()
            && $period->toOrdinal() <= $this->current()->toOrdinal();
    }

    /**
     * Le premier mois réclamé à cette officine.
     */
    public function expectedFrom(Pharmacy $pharmacy): Period
    {
        $signup = $pharmacy->created_at ?? now();
        $fromSignup = $signup->year * 12 + $signup->month - $this->catchUpMonths();

        return Period::fromOrdinal(max($this->floor()->toOrdinal(), $fromSignup));
    }
}
