<?php

namespace App\Services\Declarations;

use App\Data\Period;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use Illuminate\Support\Collection;

/**
 * The state of one officine's declaration round for one month.
 *
 * The wizard carries no step index and no form session: what remains to declare
 * is derived from what is already stored. That is what makes « reprendre plus
 * tard » free — closing the browser loses nothing.
 */
class MonthlyDeclarationRun
{
    /** @var Collection<int, Insurer> */
    protected Collection $insurers;

    /** @var Collection<int, Declaration> */
    protected Collection $declarations;

    public function __construct(
        protected Pharmacy $pharmacy,
        protected Period $period,
    ) {
        $this->insurers = $pharmacy->insurers()
            ->orderBy('insurers.name')
            ->get();

        $this->declarations = $pharmacy->declarations()
            ->forPeriod($period->year, $period->month)
            ->get()
            ->keyBy('insurer_id');
    }

    /**
     * The first ticked insurer with nothing recorded for the period.
     */
    public function nextInsurer(): ?Insurer
    {
        return $this->insurers->first(
            fn (Insurer $insurer) => ! $this->declarations->has($insurer->id),
        );
    }

    public function isComplete(): bool
    {
        return $this->insurers->isNotEmpty() && $this->nextInsurer() === null;
    }

    public function insurer(int $insurerId): ?Insurer
    {
        return $this->insurers->firstWhere('id', $insurerId);
    }

    public function declarationFor(Insurer $insurer): ?Declaration
    {
        return $this->declarations->get($insurer->id);
    }

    public function declaredCount(): int
    {
        return $this->declarations->count();
    }

    public function total(): int
    {
        return $this->insurers->count();
    }

    /**
     * Where the given insurer sits in the round, one-based.
     *
     * @return array{current: int, total: int}
     */
    public function progressFor(Insurer $insurer): array
    {
        $position = $this->insurers->search(
            fn (Insurer $candidate) => $candidate->id === $insurer->id,
        );

        return [
            'current' => $position === false ? 1 : $position + 1,
            'total' => $this->total(),
        ];
    }
}
