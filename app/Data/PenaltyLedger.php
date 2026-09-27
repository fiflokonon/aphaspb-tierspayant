<?php

namespace App\Data;

/**
 * Le journal des pénalités d'une période : une série par assureur publié, et
 * le total.
 *
 * Le total peut sommer plus que les séries publiées — côté réseau, il couvre
 * aussi les assureurs masqués (décision du 27/09/2026) — : ne jamais le
 * recalculer en additionnant les séries.
 */
readonly class PenaltyLedger
{
    public function __construct(
        /** @var list<PenaltyLedgerSeries> */
        public array $insurers,
        public PenaltyLedgerSeries $total,
        public int $maskedInsurers = 0,
    ) {
        //
    }

    /**
     * Les assureurs dont la série a au moins un mois passé ou courant retenu,
     * en entier ou dans son seul découpage par statut.
     *
     * Un export qui publie la due, la recouvrée et l'abandonnée de la période
     * pour ces assureurs rendrait ce mois par différence avec les mois publiés
     * du journal : il les retient.
     *
     * @return list<int>
     */
    public function insurersWithWithheldMonths(): array
    {
        $insurerIds = [];

        foreach ($this->insurers as $series) {
            foreach ($series->months as $month) {
                if (! $month->future && ($month->withheld || $month->splitWithheld) && $series->insurerId !== null) {
                    $insurerIds[] = $series->insurerId;

                    break;
                }
            }
        }

        return $insurerIds;
    }

    /**
     * @return array{insurers: list<array<string, mixed>>, total: array<string, mixed>, maskedInsurers: int}
     */
    public function toArray(): array
    {
        return [
            'insurers' => array_map(fn (PenaltyLedgerSeries $series): array => $series->toArray(), $this->insurers),
            'total' => $this->total->toArray(),
            'maskedInsurers' => $this->maskedInsurers,
        ];
    }
}
