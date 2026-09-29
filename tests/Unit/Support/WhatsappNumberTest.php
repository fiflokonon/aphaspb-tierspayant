<?php

use App\Support\WhatsappNumber;

test('a number is normalised to E.164, Benin by default', function (string $input, ?string $expected) {
    expect(WhatsappNumber::normalize($input))->toBe($expected);
})->with([
    // Depuis fin 2024, un mobile béninois compte 10 chiffres et commence par 01.
    'local, 10 digits' => ['01 97 00 00 00', '+2290197000000'],
    'legacy 8 digits gets its 01' => ['97 00 00 00', '+2290197000000'],
    'country code without +' => ['229 01 97 00 00 00', '+2290197000000'],
    'legacy with country code without +' => ['22997000000', '+2290197000000'],
    'international 00' => ['00229 01 97 00 00 00', '+2290197000000'],
    'already E.164, spaced' => ['+229 01 97 00 00 00', '+2290197000000'],
    'legacy E.164 gets its 01' => ['+229 97 00 00 00', '+2290197000000'],
    'foreign' => ['+33 6 12 34 56 78', '+33612345678'],
    'dots and dashes' => ['01.97-00.00.00', '+2290197000000'],
    'letters' => ['abc', null],
    'too short' => ['123', null],
    'seven local digits' => ['1234567', null],
    'ten local digits not starting with 01' => ['9700000000', null],
    'too long' => ['+1234567890123456', null],
]);

test('the link opens a chat with the message encoded', function () {
    expect(WhatsappNumber::link('+22997000000', "Bonjour L'Espérance & co"))
        ->toBe('https://wa.me/22997000000?text=Bonjour%20L%27Esp%C3%A9rance%20%26%20co');
});

test('a number is displayed in pairs when Beninese, as stored otherwise', function (string $e164, string $expected) {
    expect(WhatsappNumber::display($e164))->toBe($expected);
})->with([
    'benin' => ['+2290197000000', '+229 01 97 00 00 00'],
    'foreign' => ['+33612345678', '+33612345678'],
]);
