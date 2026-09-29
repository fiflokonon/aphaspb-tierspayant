<?php

namespace App\Data;

use App\Enums\CompletenessState;

/**
 * Une officine et l'état de sa déclaration pour un mois : rien de plus.
 *
 * Aucune propriété ne dit à quel assureur ni combien : c'est la borne de
 * l'exception au CDC (spec suivi des déclarations, §3).
 */
readonly class PharmacyCompleteness
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $city,
        public ?string $whatsappPhone,
        public CompletenessState $state,
    ) {
        //
    }
}
