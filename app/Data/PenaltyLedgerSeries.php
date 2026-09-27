<?php

namespace App\Data;

/**
 * Les mois d'un assureur — ou du total quand `insurerId` est null.
 */
readonly class PenaltyLedgerSeries
{
    public function __construct(
        public ?int $insurerId,
        public string $name,
        /** @var list<PenaltyLedgerMonth> */
        public array $months,
    ) {
        //
    }

    public function month(string $key): ?PenaltyLedgerMonth
    {
        foreach ($this->months as $month) {
            if ($month->month === $key) {
                return $month;
            }
        }

        return null;
    }

    /**
     * @return array{insurerId: int|null, name: string, months: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'insurerId' => $this->insurerId,
            'name' => $this->name,
            'months' => array_map(fn (PenaltyLedgerMonth $month): array => $month->toArray(), $this->months),
        ];
    }
}
