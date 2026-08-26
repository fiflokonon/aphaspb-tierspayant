<?php

namespace App\Data;

readonly class JoomlaProfile
{
    public function __construct(
        public int $joomlaUserId,
        public string $name,
        public string $email,
        public bool $isVerified,
        public int $tokenVersion,
    ) {
        //
    }
}
