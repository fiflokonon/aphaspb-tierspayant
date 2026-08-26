<?php

namespace App\Services\Joomla;

use App\Data\JoomlaClaims;
use Illuminate\Support\Facades\Cache;

/**
 * Single-use wrapper around a Joomla-issued JWT.
 *
 * The jti is remembered until the token would have expired anyway, which is
 * the shortest window that still blocks a replay.
 */
class JoomlaTicket
{
    public function __construct(protected JoomlaTokenDecoder $decoder)
    {
        //
    }

    public function consume(string $token): ?JoomlaClaims
    {
        $claims = $this->decoder->decode($token);

        if ($claims === null) {
            return null;
        }

        $ttl = $claims->expiresAt - time();

        if ($ttl <= 0) {
            return null;
        }

        $firstUse = Cache::add($this->key($claims->jti), true, $ttl);

        return $firstUse ? $claims : null;
    }

    protected function key(string $jti): string
    {
        return 'joomla:ticket:'.hash('sha256', $jti);
    }
}
