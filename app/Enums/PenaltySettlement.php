<?php

namespace App\Enums;

/**
 * Comment une pénalité a été close par l'officine.
 *
 * Deux issues et non une : « payée » est de l'argent recouvré, « annulée » de
 * l'argent abandonné. Toutes deux sortent de la pénalité due ; le réseau les
 * compte à part.
 */
enum PenaltySettlement: string
{
    case Paid = 'paid';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payée',
            self::Waived => 'Annulée',
        };
    }
}
