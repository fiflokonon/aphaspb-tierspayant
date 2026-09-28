<?php

namespace App\Http\Resources;

use App\Data\InsufficientData;
use App\Data\InsurerIndicators;

/**
 * Serialise one insurer's row for the network screen.
 *
 * A single shape covers both cases, with a `sufficient` flag, so the front end
 * never has to infer the state from a missing field.
 *
 * This resource carries **no amount**: screen 2a is the delays-and-rates view.
 * Aggregated amounts belong to 3c and get their own resource.
 */
class InsurerIndicatorsResource
{
    /**
     * @return array{
     *     insurerId: int,
     *     insurerName: string,
     *     sufficient: bool,
     *     declaringPharmacies: int|null,
     *     required: int|null,
     *     withheldReason: 'too-few'|'city-share'|null,
     *     averageDelayDays: float|null,
     *     standardDelayDays: int|null,
     *     withinThresholdShare: float|null,
     *     recoveredWithinDelayShare: float|null,
     *     instalmentsPerDeclaration: float|null,
     *     multiInstalmentShare: float|null,
     *     averageFirstInstalmentDelayDays: float|null,
     *     rejectionRate: float|null,
     *     unpaidRate: float|null,
     * }
     */
    public static function fromEntry(
        int $insurerId,
        InsurerIndicators|InsufficientData $entry,
        string $insurerName,
    ): array {
        $sufficient = $entry instanceof InsurerIndicators;

        return [
            'insurerId' => $insurerId,
            'insurerName' => $insurerName,
            'sufficient' => $sufficient,
            // Jamais le compte exact sous le seuil : « moins de {required} »
            // est tout ce qui sort (voir InsufficientData).
            'declaringPharmacies' => $sufficient ? $entry->declaringPharmacies : null,
            'required' => $sufficient ? null : $entry->required,
            // « city-share » : l'assureur a assez d'officines, mais non filtré
            // moins les villes publiées rendrait une part qui n'en a pas assez.
            'withheldReason' => $entry instanceof InsufficientData ? ($entry->cityShare ? 'city-share' : 'too-few') : null,
            'averageDelayDays' => $sufficient ? $entry->averageDelayDays : null,
            'standardDelayDays' => $sufficient ? $entry->standardDelayDays : null,
            'withinThresholdShare' => $sufficient ? $entry->withinThresholdShare : null,
            'recoveredWithinDelayShare' => $sufficient ? $entry->recoveredWithinDelayShare : null,
            'instalmentsPerDeclaration' => $sufficient ? $entry->instalmentsPerDeclaration : null,
            'multiInstalmentShare' => $sufficient ? $entry->multiInstalmentShare : null,
            'averageFirstInstalmentDelayDays' => $sufficient ? $entry->averageFirstInstalmentDelayDays : null,
            'rejectionRate' => $sufficient ? $entry->rejectionRate : null,
            'unpaidRate' => $sufficient ? $entry->unpaidRate : null,
        ];
    }
}
