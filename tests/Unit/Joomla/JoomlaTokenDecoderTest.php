<?php

use App\Services\Joomla\JoomlaTokenDecoder;
use Firebase\JWT\JWT;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->decoder = new JoomlaTokenDecoder;
});

test('it decodes a well formed token into claims', function () {
    $claims = $this->decoder->decode(joomlaToken([
        'sub' => '77',
        'groups' => [2, 6],
        'tv' => 3,
        'jti' => 'ticket-abc',
    ]));

    expect($claims)->not->toBeNull()
        ->and($claims->joomlaUserId)->toBe(77)
        ->and($claims->groups)->toBe([2, 6])
        ->and($claims->tokenVersion)->toBe(3)
        ->and($claims->jti)->toBe('ticket-abc');
});

test('it refuses a token signed by a foreign key', function () {
    $foreign = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($foreign, $foreignPrivateKey);

    expect($this->decoder->decode(joomlaToken([], $foreignPrivateKey)))->toBeNull();
});

test('it refuses a token minted for another audience', function () {
    expect($this->decoder->decode(joomlaToken(['aud' => 'someone-else'])))->toBeNull();
});

test('it refuses a token from another issuer', function () {
    expect($this->decoder->decode(joomlaToken(['iss' => 'https://attacker.test'])))->toBeNull();
});

test('it refuses an expired token', function () {
    expect($this->decoder->decode(joomlaToken([
        'iat' => time() - 3600,
        'exp' => time() - 60,
    ])))->toBeNull();
});

test('it refuses a token missing the claims the application relies on', function () {
    $incomplete = JWT::encode([
        'iss' => config('joomla.issuer'),
        'aud' => config('joomla.audience'),
        'exp' => time() + 900,
    ], joomlaTestKeys()['private'], 'RS256');

    expect($this->decoder->decode($incomplete))->toBeNull();
});

test('it refuses garbage', function () {
    expect($this->decoder->decode('not-a-token'))->toBeNull()
        ->and($this->decoder->decode(''))->toBeNull();
});
