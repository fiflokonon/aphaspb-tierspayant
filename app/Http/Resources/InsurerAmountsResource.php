<?php

namespace App\Http\Resources;

use App\Data\InsufficientData;
use App\Data\InsurerAmounts;

/**
 * Serialise one insurer's aggregated amounts for screen 3c.
 *
 * One shape for both cases, with a `sufficient` flag, so the front end never
 * infers the state from a missing field. Volumes travel in FCFA alongside their
 * share, as the APhaSPB decided: never one without the other.
 */
class InsurerAmountsResource
{
    /**
     * @return array{
     *     insurerId: int,
     *     insurerName: string,
     *     sufficient: bool,
     *     declaringPharmacies: int,
     *     required: int|null,
     *     invoiced: int|null,
     *     received: int|null,
     *     outstanding: int|null,
     *     recoveryRate: float|null,
     * }
     */
    public static function fromEntry(
        int $insurerId,
        InsurerAmounts|InsufficientData $entry,
        string $insurerName,
    ): array {
        $sufficient = $entry instanceof InsurerAmounts;

        return [
            'insurerId' => $insurerId,
            'insurerName' => $insurerName,
            'sufficient' => $sufficient,
            'declaringPharmacies' => $entry->declaringPharmacies,
            'required' => $sufficient ? null : $entry->required,
            'invoiced' => $sufficient ? $entry->invoiced : null,
            'received' => $sufficient ? $entry->received : null,
            'outstanding' => $sufficient ? $entry->outstanding : null,
            'recoveryRate' => $sufficient ? $entry->recoveryRate : null,
        ];
    }
}
