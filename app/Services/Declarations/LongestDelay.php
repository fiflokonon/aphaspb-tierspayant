<?php

namespace App\Services\Declarations;

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use Carbon\CarbonImmutable;

/**
 * Le pire retard constaté avec un assureur, soldé ou non.
 *
 * Deux natures de retard dans un seul chiffre, délibérément : le plus long
 * délai d'un mois réglé, et l'âge de la plus vieille facture encore due. Ne
 * garder que le premier ferait disparaître une facture ouverte depuis quatre
 * cents jours — exactement le cas le plus grave, et celui qui pèse le plus en
 * négociation.
 *
 * Une classe à part plutôt qu'une méthode : la synthèse du PDF officine et
 * l'écran par assureur affichent tous deux ce chiffre, et deux
 * implémentations divergeraient.
 */
class LongestDelay
{
    /**
     * @param  iterable<Declaration>  $declarations
     */
    public function for(iterable $declarations): ?int
    {
        $today = CarbonImmutable::now()->startOfDay();
        $longest = null;

        foreach ($declarations as $declaration) {
            $candidate = $declaration->delay_days ?? $this->openAge($declaration, $today);

            if ($candidate === null) {
                continue;
            }

            $longest = $longest === null ? $candidate : max($longest, $candidate);
        }

        return $longest;
    }

    /**
     * L'âge d'une facture encore due, comptée depuis le dépôt.
     *
     * Même horloge que `OverdueLine::ageDays` et que `delay_days` — surtout
     * pas depuis la fin du mois déclaré, qui est celle des tranches
     * d'ancienneté et donnerait un second chiffre pour la même facture.
     */
    protected function openAge(Declaration $declaration, CarbonImmutable $today): ?int
    {
        if ($declaration->status === DeclarationStatus::Rejected) {
            return null;
        }

        if ($declaration->amount_invoiced <= $declaration->amount_received) {
            return null;
        }

        if ($declaration->invoice_deposited_on === null) {
            return null;
        }

        return (int) $declaration->invoice_deposited_on->startOfDay()->diffInDays($today);
    }
}
