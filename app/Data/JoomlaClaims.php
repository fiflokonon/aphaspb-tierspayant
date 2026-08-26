<?php

namespace App\Data;

readonly class JoomlaClaims
{
    /**
     * @param  list<int>  $groups
     */
    public function __construct(
        public int $joomlaUserId,
        public array $groups,
        public int $tokenVersion,
        public string $jti,
        public int $expiresAt,
    ) {
        //
    }
}
