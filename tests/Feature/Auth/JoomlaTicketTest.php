<?php

use App\Services\Joomla\JoomlaTicket;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->ticket = app(JoomlaTicket::class);
});

test('a ticket is accepted once', function () {
    $claims = $this->ticket->consume(joomlaToken(['sub' => '5150']));

    expect($claims)->not->toBeNull()
        ->and($claims->joomlaUserId)->toBe(5150);
});

test('replaying the same ticket is refused', function () {
    $token = joomlaToken(['jti' => 'ticket-once']);

    expect($this->ticket->consume($token))->not->toBeNull()
        ->and($this->ticket->consume($token))->toBeNull();
});

test('two distinct tickets for the same user are both accepted', function () {
    expect($this->ticket->consume(joomlaToken(['jti' => 'ticket-a'])))->not->toBeNull()
        ->and($this->ticket->consume(joomlaToken(['jti' => 'ticket-b'])))->not->toBeNull();
});

test('an invalid token is refused without being remembered', function () {
    expect($this->ticket->consume('not-a-token'))->toBeNull();
});
