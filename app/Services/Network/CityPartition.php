<?php

namespace App\Services\Network;

use App\Data\PenaltySplitPharmacies;
use App\Data\RegionScope;

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
 *
 * **Filtre par régions (15/10/2026).** Une région regroupe des villes : son
 * chiffre moins ses villes publiées rendrait ses villes retenues, et le total
 * moins les régions publiées rendrait les villes hors région. D'où la règle
 * de partition régionale (`*In()` avec un RegionScope) : un chiffre filtré
 * par régions n'est publié que si **toute** la partition de l'entité est
 * propre — la part cachée de chaque région, et celle du reste (villes sans
 * région, officines sans ville), vaut 0 ou au moins le seuil. Indépendante
 * de la sélection, donc monotone : une entité dont la partition n'est pas
 * propre n'a aucun chiffre régional publié, et les règles non filtrées
 * restent inchangées. Les comptes passés couvrent **tout le réseau**, pas
 * seulement les régions choisies.
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
     * @param  array<int, array<string, array<int, bool>>>  $cells  assureur → ville → officines
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
     * La même règle, pour la seule part due.
     *
     * Une ville cache sa due quand celle-ci repose sur 1 à seuil − 1
     * officines ; les officines sans ville sont toujours cachées. La due non
     * filtrée est retenue si cette part cachée repose sur 1 à seuil − 1
     * officines : la due non filtrée moins les dues publiées par ville la
     * rendrait.
     *
     * @param  array<string, PenaltySplitPharmacies>  $perCity
     */
    public static function withholdsDue(array $perCity, int $minimum): bool
    {
        $hidden = 0;

        foreach ($perCity as $city => $split) {
            if ((string) $city === self::NO_CITY || $split->dueRestsOnFewerThan($minimum)) {
                $hidden += $split->due;
            }
        }

        return $hidden > 0 && $hidden < $minimum;
    }

    /**
     * withholds(), selon le filtre : non filtré, une ville, des régions.
     *
     * @param  array<string, int>  $perCity  tout le réseau, NO_CITY compris
     */
    public static function withholdsIn(string|RegionScope|null $scope, array $perCity, int $minimum): bool
    {
        return match (true) {
            $scope === null => self::withholds($perCity, $minimum),
            is_string($scope) => false,
            default => self::regionPartitionWithholds(
                $perCity,
                $scope,
                fn (string $city, int $pharmacies): bool => $city === self::NO_CITY || ($pharmacies > 0 && $pharmacies < $minimum),
                fn (array $hidden): bool => array_sum($hidden) > 0 && array_sum($hidden) < $minimum,
            ),
        };
    }

    /**
     * withholdsSplit(), selon le filtre.
     *
     * @param  array<string, PenaltySplitPharmacies>  $perCity  tout le réseau
     */
    public static function withholdsSplitIn(string|RegionScope|null $scope, array $perCity, int $minimum): bool
    {
        return match (true) {
            $scope === null => self::withholdsSplit($perCity, $minimum),
            is_string($scope) => false,
            default => self::regionPartitionWithholds(
                $perCity,
                $scope,
                fn (string $city, PenaltySplitPharmacies $split): bool => $city === self::NO_CITY || $split->restsOnFewerThan($minimum),
                fn (array $hidden): bool => self::sumSplits($hidden)->restsOnFewerThan($minimum),
            ),
        };
    }

    /**
     * withholdsDue(), selon le filtre.
     *
     * @param  array<string, PenaltySplitPharmacies>  $perCity  tout le réseau
     */
    public static function withholdsDueIn(string|RegionScope|null $scope, array $perCity, int $minimum): bool
    {
        return match (true) {
            $scope === null => self::withholdsDue($perCity, $minimum),
            is_string($scope) => false,
            default => self::regionPartitionWithholds(
                $perCity,
                $scope,
                fn (string $city, PenaltySplitPharmacies $split): bool => $city === self::NO_CITY || $split->dueRestsOnFewerThan($minimum),
                fn (array $hidden): bool => self::sumSplits($hidden)->due > 0 && self::sumSplits($hidden)->due < $minimum,
            ),
        };
    }

    /**
     * hiddenCellsOfCityShareInsurers(), selon le filtre.
     *
     * Sous filtre régions, les assureurs concernés sont ceux dont la
     * partition régionale n'est pas propre ; leurs lignes de ville publiées
     * se soustraient du chiffre des régions choisies, qui rend alors leurs
     * cellules sous le seuil **dans ces régions**. Leur union, en officines
     * distinctes, ne doit pas reposer sur 1 à seuil − 1 officines.
     *
     * @param  array<int, array<string, array<int, bool>>>  $cells  assureur → ville → officines, tout le réseau
     */
    public static function hiddenCellsOfCityShareInsurersIn(string|RegionScope|null $scope, array $cells, int $minimum): int
    {
        if ($scope === null) {
            return self::hiddenCellsOfCityShareInsurers($cells, $minimum);
        }

        if (is_string($scope)) {
            return 0;
        }

        $hidden = [];

        foreach ($cells as $cities) {
            $inScope = array_filter($cities, fn (string|int $city): bool => $scope->contains((string) $city), ARRAY_FILTER_USE_KEY);

            if (array_sum(array_map('count', $inScope)) < $minimum
                || ! self::withholdsIn($scope, array_map('count', $cities), $minimum)) {
                continue;
            }

            foreach ($inScope as $pharmacies) {
                if (count($pharmacies) < $minimum) {
                    $hidden += $pharmacies;
                }
            }
        }

        return count($hidden);
    }

    /**
     * Le cœur de la règle régionale : la part cachée de chaque bloc.
     *
     * Les blocs sont les régions, plus « le reste » (villes sans région et
     * officines sans ville). Une ville dont la valeur est publiable n'entre
     * dans aucun bloc.
     *
     * @template TValue
     *
     * @param  array<string, TValue>  $perCity
     * @param  callable(string, TValue): bool  $isHidden
     * @param  callable(list<TValue>): bool  $unsafe
     */
    protected static function regionPartitionWithholds(array $perCity, RegionScope $scope, callable $isHidden, callable $unsafe): bool
    {
        $blocks = [];

        foreach ($perCity as $city => $value) {
            $city = (string) $city;

            if (! $isHidden($city, $value)) {
                continue;
            }

            $blocks[$scope->regionOf[$city] ?? 'rest'][] = $value;
        }

        foreach ($blocks as $hidden) {
            if ($unsafe($hidden)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PenaltySplitPharmacies>  $splits
     */
    protected static function sumSplits(array $splits): PenaltySplitPharmacies
    {
        return new PenaltySplitPharmacies(
            array_sum(array_map(fn (PenaltySplitPharmacies $split): int => $split->due, $splits)),
            array_sum(array_map(fn (PenaltySplitPharmacies $split): int => $split->paid, $splits)),
            array_sum(array_map(fn (PenaltySplitPharmacies $split): int => $split->waived, $splits)),
        );
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
