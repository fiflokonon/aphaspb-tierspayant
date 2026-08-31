<?php

namespace App\Data;

/**
 * Une ligne du centre de notifications, quelle qu'en soit la source.
 *
 * Les charges utiles diffèrent d'un type de notification à l'autre, et les
 * invitations n'en sont même pas — elles vivent dans leur propre table parce
 * qu'elles visent une adresse e-mail, pas un compte. Cette forme unique évite
 * au front de connaître le moindre nom de classe PHP.
 */
readonly class PresentedNotification
{
    public function __construct(
        public string $id,
        /** « notification » ou « invitation » : seule la première se marque lue. */
        public string $kind,
        public string $title,
        public string $body,
        public ?string $href,
        public string $tone,
        public string $createdAt,
        public ?string $readAt,
    ) {
        //
    }
}
