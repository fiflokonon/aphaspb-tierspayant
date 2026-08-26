<?php

use App\Services\Joomla\JoomlaApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->client = app(JoomlaApiClient::class);
});

test('it fetches a profile and sends the shared secret', function () {
    Http::fake([
        'joomla.test/api/me*' => Http::response([
            'id' => 5150,
            'name' => 'Pharmacie Le Bon Secours',
            'email' => 'titulaire@officine.bj',
            'verified' => true,
            'token_version' => 3,
        ]),
    ]);

    $profile = $this->client->profile(5150);

    expect($profile)->not->toBeNull()
        ->and($profile->joomlaUserId)->toBe(5150)
        ->and($profile->name)->toBe('Pharmacie Le Bon Secours')
        ->and($profile->email)->toBe('titulaire@officine.bj')
        ->and($profile->isVerified)->toBeTrue()
        ->and($profile->tokenVersion)->toBe(3);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Joomla-Secret', 'test-secret'));
});

test('it returns null when Joomla refuses the call', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(status: 403)]);

    expect($this->client->profile(5150))->toBeNull();
});

test('it returns null when Joomla is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect($this->client->profile(5150))->toBeNull();
});

test('it returns null when the payload lacks the fields the application needs', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(['id' => 5150])]);

    expect($this->client->profile(5150))->toBeNull();
});
