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
            // Une facture refusée n'est pas une dette, et son acompte éventuel
            // n'est pas un délai à opposer à l'assureur. Même exclusion que
            // PenaltyCalculator::for() : les deux classes prétendent partager
            // une définition, elles doivent s'accorder.
            if ($declaration->status === DeclarationStatus::Rejected) {
                continue;
            }

            // L'encours d'abord, le délai ensuite — et surtout pas l'inverse.
            // syncFromInstalments() pose `paid_on` dès le **premier** versement,
            // donc un mois entamé puis abandonné porte un `delay_days` court
            // pendant que sa dette vieillit. Lire `delay_days` en premier
            // rendrait 9 jours pour une facture impayée depuis 261.
            $candidate = $this->openAge($declaration, $today) ?? $declaration->delay_days;

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
     *
     * Rend null pour un mois soldé : c'est alors `delay_days` qui parle. Un
     * mois seulement entamé reste, lui, une dette ouverte.
     */
    protected function openAge(Declaration $declaration, CarbonImmutable $today): ?int
    {
        if ($declaration->amount_invoiced <= $declaration->amount_received) {
            return null;
        }

        if ($declaration->invoice_deposited_on === null) {
            return null;
        }

        return (int) $declaration->invoice_deposited_on->startOfDay()->diffInDays($today);
    }
}
