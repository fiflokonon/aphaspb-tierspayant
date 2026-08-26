<?php

namespace App\Services\Joomla;

use App\Data\JoomlaClaims;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class JoomlaTokenDecoder
{
    /**
     * Verify a Joomla-issued JWT and return its claims.
     *
     * Returns null on every failure — bad signature, foreign audience or
     * issuer, expiry, malformed payload — so no caller can leak which one
     * occurred. RS256 only: the private key never leaves Joomla.
     */
    public function decode(string $token): ?JoomlaClaims
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = (array) JWT::decode($token, new Key($this->publicKey(), 'RS256'));
        } catch (Throwable) {
            return null;
        }

        if (($payload['aud'] ?? null) !== config('joomla.audience')) {
            return null;
        }

        if (($payload['iss'] ?? null) !== config('joomla.issuer')) {
            return null;
        }

        foreach (['sub', 'jti', 'exp', 'tv'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return null;
            }
        }

        return new JoomlaClaims(
            joomlaUserId: (int) $payload['sub'],
            groups: array_values(array_map('intval', (array) ($payload['groups'] ?? []))),
            tokenVersion: (int) $payload['tv'],
            jti: (string) $payload['jti'],
            expiresAt: (int) $payload['exp'],
        );
    }

    protected function publicKey(): string
    {
        $path = (string) config('joomla.public_key_path');

        if (! is_readable($path)) {
            throw new JoomlaConfigurationException(
                "La clé publique Joomla est introuvable ou illisible : {$path}",
            );
        }

        return (string) file_get_contents($path);
    }
}
