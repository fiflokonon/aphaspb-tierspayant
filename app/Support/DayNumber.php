<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Une date réduite à son numéro de jour, compté depuis le 1er janvier 1970.
 *
 * Le calcul de pénalité compare et incrémente des dates des dizaines de
 * milliers de fois par export réseau. Mesuré sur 40 000 déclarations : 4 323 ms
 * et 273 Mo avec des objets CarbonImmutable, 42 ms et 34 Mo avec des entiers.
 * Le goulot n'est pas l'arithmétique, c'est l'allocation d'objets.
 *
 * Deux dates converties ici se soustraient exactement comme diffInDays() les
 * compare — c'est la seule propriété dont le reste dépend, et un test la
 * vérifie sur quatre années jour par jour.
 */
class DayNumber
{
    /** Secondes dans une journée. */
    protected const SECONDS_PER_DAY = 86400;

    /**
     * Le numéro de jour d'une date au format `Y-m-d`.
     *
     * Ancrée sur UTC explicitement : sans ce suffixe, la conversion suivrait le
     * fuseau du processus et deux dates identiques rendraient deux numéros
     * différents selon la machine.
     */
    public static function fromDate(string $date): int
    {
        $timestamp = strtotime($date.' UTC');

        if ($timestamp === false) {
            throw new InvalidArgumentException("Date illisible : {$date}");
        }

        // floor() et non intdiv() par précaution, pas par nécessité : sur une
        // date seule, minuit UTC tombe toujours sur un multiple exact de 86 400
        // et les deux rendent le même entier. Ils ne divergent que sur un
        // horodatage antérieur à 1970 portant une heure — ce que cette méthode
        // ne promet pas d'accepter. Aucun test ne peut donc les distinguer, et
        // il ne faut pas en écrire un qui prétendrait le faire.
        return (int) floor($timestamp / self::SECONDS_PER_DAY);
    }

    /**
     * Le numéro de jour d'un instant Carbon.
     *
     * Passe par la date formatée et non par l'horodatage : `app.timezone` vaut
     * UTC aujourd'hui, mais s'il changeait, un `getTimestamp() / 86400`
     * décalerait toutes les dates d'un jour une partie de l'année, en silence.
     */
    public static function fromCarbon(CarbonInterface $moment): int
    {
        return self::fromDate($moment->format('Y-m-d'));
    }

    /**
     * Aujourd'hui.
     *
     * Dérivé de CarbonImmutable::now() et non de time(), sans quoi travelTo()
     * cesserait de piloter les tests et toute la suite « la pénalité croît avec
     * le temps » deviendrait fausse sans rougir.
     */
    public static function today(): int
    {
        return self::fromCarbon(CarbonImmutable::now());
    }
}
