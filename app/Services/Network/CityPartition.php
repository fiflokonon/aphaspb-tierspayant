<?php

namespace App\Services\Network;

use App\Data\PenaltySplitPharmacies;

/**
 * La règle de partition par ville, pour les agrégats réseau non filtrés.
 *
 * Les villes partitionnent le réseau : une officine appartient à une seule
 * ville (ou à aucune). Un chiffre non filtré, moins les chiffres publiés de
 * chaque ville, rend donc la **part cachée** : les villes dont le chiffre est
 * retenu (1 à seuil − 1 officines) et les officines sans ville, qu'aucun
 * filtre ne publie jamais. Si cette part repose sur 1 à seuil − 1 officines,
 * le chiffre non filtré est retenu à son tour (revue du 28/09/2026).
 *
 * Une ville retenue pour une autre raison mais qui compte au moins le seuil
 * n'entre pas dans la somme : la part qu'elle cacherait repose déjà sur
 * assez d'officines. Les comptes s'additionnent parce qu'une officine n'a
 * qu'une ville.
 *
 * Pure : les comptes viennent des lecteurs, le seuil de l'appelant qui
 * détient SettingsRepository.
 */
class CityPartition
{
    /**
     * La clé des officines sans ville (null ou vide : aucun filtre ne l'atteint).
     */
    public const NO_CITY = '';

    /**
     * Les officines de la part cachée.
     *
     * @param  array<string, int>  $perCity  officines distinctes par ville, NO_CITY compris
     */
    public static function hiddenShare(array $perCity, int $minimum): int
    {
        $hidden = 0;

        foreach ($perCity as $city => $pharmacies) {
            if ((string) $city === self::NO_CITY || ($pharmacies > 0 && $pharmacies < $minimum)) {
                $hidden += $pharmacies;
            }
        }

        return $hidden;
    }

    /**
     * Si le chiffre non filtré doit être retenu.
     *
     * @param  array<string, int>  $perCity
     */
    public static function withholds(array $perCity, int $minimum): bool
    {
        $hidden = self::hiddenShare($perCity, $minimum);

        return $hidden > 0 && $hidden < $minimum;
    }

    /**
     * La même règle pour un découpage due / payée / annulée.
     *
     * Une ville cache son découpage dès qu'une de ses parts repose sur 1 à
     * seuil − 1 officines (règle de partition des statuts) ; ses trois parts
     * entrent alors dans la part cachée, comme celles des officines sans
     * ville. Le découpage non filtré est retenu si une part cachée repose
     * sur 1 à seuil − 1 officines.
     *
     * @param  array<string, PenaltySplitPharmacies>  $perCity
     */
    public static function withholdsSplit(array $perCity, int $minimum): bool
    {
        $due = $paid = $waived = 0;

        foreach ($perCity as $city => $split) {
            if ((string) $city === self::NO_CITY || $split->restsOnFewerThan($minimum)) {
                $due += $split->due;
                $paid += $split->paid;
                $waived += $split->waived;
            }
        }

        return (new PenaltySplitPharmacies($due, $paid, $waived))->restsOnFewerThan($minimum);
    }

    /**
     * La clé de ville d'une ligne lue en base.
     */
    public static function key(mixed $city): string
    {
        return $city === null ? self::NO_CITY : (string) $city;
    }
}
