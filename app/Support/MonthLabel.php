<?php

namespace App\Support;

/**
 * Les noms de mois français, écrits une fois.
 *
 * Deux formes coexistent dans les maquettes : l'en-tête de l'écran de
 * déclaration crie « AOÛT 2026 », le tableau de l'historique abrège en
 * « Août 26 ». Les deux vivent ici plutôt que dans le contrôleur qui les
 * affiche, parce que l'e-mail de relance en voulait une troisième copie.
 */
class MonthLabel
{
    /** @var array<int, string> */
    protected const SHORT = [
        1 => 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin',
        'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.',
    ];

    /** @var array<int, string> */
    protected const LONG = [
        1 => 'JANVIER', 'FÉVRIER', 'MARS', 'AVRIL', 'MAI', 'JUIN',
        'JUILLET', 'AOÛT', 'SEPTEMBRE', 'OCTOBRE', 'NOVEMBRE', 'DÉCEMBRE',
    ];

    /** « Août 26 » */
    public static function short(int $month, int $year): string
    {
        return self::SHORT[$month].' '.substr((string) $year, 2);
    }

    /** « AOÛT 2026 » */
    public static function long(int $month, int $year): string
    {
        return self::LONG[$month].' '.$year;
    }
}
