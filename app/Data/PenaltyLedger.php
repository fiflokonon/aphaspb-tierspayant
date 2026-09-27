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
