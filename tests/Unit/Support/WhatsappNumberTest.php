<?php

use App\Support\WhatsappNumber;

test('a number is normalised to E.164, Benin by default', function (string $input, ?string $expected) {
    expect(WhatsappNumber::normalize($input))->toBe($expected);
})->with([
    'local, spaced' => ['97 00 00 00', '+22997000000'],
    'new 10-digit plan' => ['01 97 00 00 00', '+2290197000000'],
    'international 00' => ['0022997000000', '+22997000000'],
    'already E.164, spaced' => ['+229 97 00 00 00', '+22997000000'],
    'foreign' => ['+33 6 12 34 56 78', '+33612345678'],
    'dots and dashes' => ['97.00-00.00', '+22997000000'],
    'letters' => ['abc', null],
    'too short' => ['123', null],
    'too long' => ['+1234567890123456', null],
]);

test('the link opens a chat with the message encoded', function () {
    expect(WhatsappNumber::link('+22997000000', "Bonjour L'Espérance & co"))
        ->toBe('https://wa.me/22997000000?text=Bonjour%20L%27Esp%C3%A9rance%20%26%20co');
});
