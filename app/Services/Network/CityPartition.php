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
     * Les officines distinctes des cellules cachées des assureurs retenus par
     * la partition (« city-share »).
     *
     * Un tel assureur reste masqué dans les totaux publiés (synthèse, total
     * du journal). Total − lignes publiées − ses lignes de ville publiées rend
     * ses cellules cachées : les villes où il repose sur 1 à seuil − 1
     * officines, et ses officines sans ville. Le total non filtré est retenu
     * quand leur union repose sur 1 à seuil − 1 officines (revue, 3e tour).
     *
     * Les assureurs retenus faute d'officines (moins du seuil au total) n'y
     * entrent pas : aucune de leurs lignes de ville n'est publiée, leur part
     * relève du risque accepté « synthèse − assureurs publiés ».
     *
     * @param  array<int, array<string, array<int, true>>>  $cells  assureur → ville → officines
     */
    public static function hiddenCellsOfCityShareInsurers(array $cells, int $minimum): int
    {
        $hidden = [];

        foreach ($cells as $cities) {
            $counts = array_map('count', $cities);

            if (array_sum($counts) < $minimum || ! self::withholds($counts, $minimum)) {
                continue;
            }

            foreach ($cities as $city => $pharmacies) {
                if ((string) $city === self::NO_CITY || count($pharmacies) < $minimum) {
                    $hidden += $pharmacies;
                }
            }
        }

        return count($hidden);
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
     * La clé canonique de la ville d'une officine, calculée par la base.
     *
     * Le filtre ville compare en SQL, donc selon la collation de la colonne :
     * sous MySQL `_ai_ci` (production), « Bohicon », « bohicon » et
     * « Bohicón » sont une seule ville filtrable. Un compartiment PHP formé
     * sur la chaîne brute en ferait trois, et la part cachée serait fausse.
     * La clé est donc le plus petit représentant, selon la même collation,
     * des villes égales à celle-ci : toutes les graphies d'une même ville
     * reçoivent la même clé, sur n'importe quel pilote, sans réécrire les
     * données. NULL reste NULL (sans ville).
     *
     * `$pharmacies` est le nom ou l'alias de la table `pharmacies` de la
     * requête appelante. Sous-requête corrélée : pas une requête de plus.
     * Littéral seulement : ce nom entre tel quel dans le SQL.
     *
     * @param  literal-string  $pharmacies
     * @return literal-string
     */
    public static function canonicalCitySql(string $pharmacies): string
    {
        return "(SELECT MIN(canonical_city.city) FROM pharmacies AS canonical_city WHERE canonical_city.city = {$pharmacies}.city)";
    }

    /**
     * La clé de ville d'une ligne lue en base.
     */
    public static function key(mixed $city): string
    {
        return $city === null ? self::NO_CITY : (string) $city;
    }
}
