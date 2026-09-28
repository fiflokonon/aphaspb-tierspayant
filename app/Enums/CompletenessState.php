<?php

namespace App\Enums;

/**
 * Où en est la déclaration d'une officine pour un mois, sans dire pour qui.
 */
enum CompletenessState: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Complète',
            self::Partial => 'Partielle',
            self::None => 'Sans déclaration',
        };
    }
}
