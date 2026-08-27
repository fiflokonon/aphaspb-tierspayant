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
     *     declaringPharmacies: int,
     *     required: int|null,
     *     averageDelayDays: float|null,
     *     withinThresholdShare: float|null,
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
            'declaringPharmacies' => $entry->declaringPharmacies,
            'required' => $sufficient ? null : $entry->required,
            'averageDelayDays' => $sufficient ? $entry->averageDelayDays : null,
            'withinThresholdShare' => $sufficient ? $entry->withinThresholdShare : null,
            'rejectionRate' => $sufficient ? $entry->rejectionRate : null,
            'unpaidRate' => $sufficient ? $entry->unpaidRate : null,
        ];
    }
}
