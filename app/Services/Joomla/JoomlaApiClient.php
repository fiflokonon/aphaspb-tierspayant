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
 */
class JoomlaApiClient
{
    public function profile(int $joomlaUserId): ?JoomlaProfile
    {
        try {
            $response = Http::withHeaders([
                'X-Joomla-Secret' => (string) config('joomla.m2m_secret'),
                'Accept' => 'application/json',
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

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        foreach (['id', 'name', 'email'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return null;
            }
        }

        return new JoomlaProfile(
            joomlaUserId: (int) $payload['id'],
            name: (string) $payload['name'],
            email: (string) $payload['email'],
            isVerified: (bool) ($payload['verified'] ?? false),
            tokenVersion: (int) ($payload['token_version'] ?? 0),
        );
    }
}
