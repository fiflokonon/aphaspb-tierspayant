<?php

namespace App\Data;

/**
 * Les officines derrière chaque part d'une pénalité découpée par statut :
 * due, payée (recouvrée), annulée (abandonnée).
 *
 * Ne décide rien : c'est l'appelant qui détient le seuil d'anonymat qui
 * retient. Une officine n'est comptée dans une part que si elle y apporte un
 * montant : une part vide ne repose sur personne, donc ne désigne personne.
 */
readonly class PenaltySplitPharmacies
{
    public function __construct(
        public int $due = 0,
        public int $paid = 0,
        public int $waived = 0,
    ) {
        //
    }

    /**
     * Si une part non vide repose sur moins de `$minimum` officines.
     *
     * Les trois parts tombent alors ensemble : une part publiée, avec la
     * pénalité courue ou une autre part, rendrait la part cachée par
     * différence.
     */
    public function restsOnFewerThan(int $minimum): bool
    {
        foreach ([$this->due, $this->paid, $this->waived] as $pharmacies) {
            if ($pharmacies > 0 && $pharmacies < $minimum) {
                return true;
            }
        }

        return false;
    }
}
