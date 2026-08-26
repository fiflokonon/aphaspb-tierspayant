<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

test('the test helper forges a JWT that verifies against the configured public key', function () {
    useJoomlaTestKeys();

    $token = joomlaToken(['sub' => '4242']);

    $claims = (array) JWT::decode(
        $token,
        new Key(file_get_contents(config('joomla.public_key_path')), 'RS256'),
    );

    expect($claims['sub'])->toBe('4242')
        ->and($claims['aud'])->toBe('laravel-api')
        ->and($claims['iss'])->toBe('https://joomla.test');
});

test('a token forged with a foreign key does not verify', function () {
    useJoomlaTestKeys();

    $foreign = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($foreign, $foreignPrivateKey);

    $token = joomlaToken([], $foreignPrivateKey);

    expect(fn () => JWT::decode(
        $token,
        new Key(file_get_contents(config('joomla.public_key_path')), 'RS256'),
    ))->toThrow(SignatureInvalidException::class);
});
