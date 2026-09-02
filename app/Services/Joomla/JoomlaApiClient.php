<?php

namespace App\Services\Joomla;

use App\Data\JoomlaProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Server-to-server access to Joomla's read-only user endpoint.
 *
 * The shared secret never reaches the browser, and the profile is never
 * carried by the client: a user object coming from the client is forgeable.
 *
 * Joomla's API application speaks JSON:API and negotiates on the Accept
 * header, so the answer arrives wrapped in data.attributes and a plain
 * application/json request would be turned away with a 406.
 */
class JoomlaApiClient
{
    public function profile(int $joomlaUserId): ?JoomlaProfile
    {
        try {
            $response = Http::withHeaders([
                'X-Joomla-Secret' => (string) config('joomla.m2m_secret'),
                'Accept' => 'application/vnd.api+json',
            ])
                ->timeout(5)
                ->retry(2, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false)
                ->get(rtrim((string) config('joomla.api_url'), '/').'/me', [
                    'id' => $joomlaUserId,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $attributes = data_get($response->json(), 'data.attributes');

        if (! is_array($attributes)) {
            return null;
        }

        foreach (['id', 'name', 'email'] as $required) {
            if (! array_key_exists($required, $attributes)) {
                return null;
            }
        }

        return new JoomlaProfile(
            joomlaUserId: (int) $attributes['id'],
            name: (string) $attributes['name'],
            email: (string) $attributes['email'],
            isVerified: (bool) ($attributes['verified'] ?? false),
            tokenVersion: (int) ($attributes['token_version'] ?? 0),
        );
    }
}
