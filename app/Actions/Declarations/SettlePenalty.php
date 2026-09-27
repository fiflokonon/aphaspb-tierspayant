<?php

namespace App\Actions\Declarations;

use App\Enums\PenaltySettlement;
use App\Models\Declaration;
use App\Models\User;
use App\Services\Declarations\PenaltyCalculator;
use App\Support\MonthLabel;

/**
 * Clore une pénalité (payée, annulée) ou la remettre en dû.
 *
 * N'écrit pas de révision : ses deux appelants le font après coup, comme
 * DeclarationController::store() le fait après les versements.
 *
 * Porte aussi les messages des deux gestes, pour que l'écran assureur et le
 * formulaire du mois disent la même chose dans les mêmes mots.
 *
 * @phpstan-type Toast array{type: string, message: string}
 */
class SettlePenalty
{
    public function __construct(protected PenaltyCalculator $penalties)
    {
        //
    }

    /**
     * Pourquoi le geste est refusé, ou null s'il est possible (spec §5.1).
     */
    public function refusal(Declaration $declaration): ?string
    {
        $declaration->loadMissing(['insurer', 'payments']);

        return $this->refusalGiven($declaration, $this->penalties->for($declaration));
    }

    /**
     * Le même refus, à partir d'une courue déjà calculée par
     * PenaltyCalculator::for() — `insurer` préchargé.
     */
    public function refusalGiven(Declaration $declaration, ?int $accrued): ?string
    {
        if (! $declaration->insurer->hasPenaltyClause()) {
            return "Cet assureur n'a pas de clause de pénalité.";
        }

        if (! $declaration->isFullyCovered()) {
            return 'Le mois doit être entièrement réglé avant de clore sa pénalité.';
        }

        if (($accrued ?? 0) === 0) {
            return "Aucune pénalité n'a couru sur ce mois.";
        }

        return null;
    }

    public function settle(Declaration $declaration, PenaltySettlement $outcome, User $by): bool
    {
        $declaration->loadMissing(['insurer', 'payments']);
        $accrued = $this->penalties->for($declaration);

        if ($this->refusalGiven($declaration, $accrued) !== null) {
            return false;
        }

        // Un double clic ne doit pas réécrire la date du geste.
        if ($declaration->penalty_settlement === $outcome) {
            return false;
        }

        $declaration->settlePenalty($outcome, (int) $accrued, $by);

        return true;
    }

    public function reopen(Declaration $declaration): bool
    {
        if (! $declaration->isPenaltySettled()) {
            return false;
        }

        $declaration->clearPenaltySettlement();

        return true;
    }

    /**
     * @return Toast
     */
    public function settledNotice(Declaration $declaration, PenaltySettlement $outcome): array
    {
        return ['type' => 'success', 'message' => sprintf(
            'Pénalité de %s marquée %s.',
            $this->month($declaration),
            mb_strtolower($outcome->label()),
        )];
    }

    /**
     * Le geste n'a rien changé : la clôture demandée était déjà là.
     *
     * @return Toast
     */
    public function alreadySettledNotice(Declaration $declaration, PenaltySettlement $outcome): array
    {
        return ['type' => 'info', 'message' => sprintf(
            'La pénalité de %s était déjà marquée %s.',
            $this->month($declaration),
            mb_strtolower($outcome->label()),
        )];
    }

    /**
     * Le choix du formulaire du mois, écarté parce que le geste est refusé.
     *
     * @return Toast
     */
    public function ignoredChoiceNotice(string $refusal): array
    {
        return ['type' => 'info', 'message' => 'Pénalité non close : '.lcfirst($refusal)];
    }

    /**
     * @return Toast
     */
    public function reopenedNotice(Declaration $declaration): array
    {
        return ['type' => 'success', 'message' => sprintf('Pénalité de %s remise en dû.', $this->month($declaration))];
    }

    /**
     * Le geste n'a rien changé : il n'y avait pas de clôture à lever.
     *
     * @return Toast
     */
    public function notSettledNotice(Declaration $declaration): array
    {
        return ['type' => 'info', 'message' => sprintf("La pénalité de %s n'était pas close.", $this->month($declaration))];
    }

    protected function month(Declaration $declaration): string
    {
        return MonthLabel::short($declaration->period_month, $declaration->period_year);
    }
}
