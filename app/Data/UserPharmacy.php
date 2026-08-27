<?php

namespace App\Data;

readonly class UserPharmacy
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $role,
        public ?string $roleLabel,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
