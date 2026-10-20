<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('the scheduler drains the queue every minute, since the shared host runs no worker', function () {
    $drain = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));

    expect($drain)->not->toBeNull()
        ->and($drain->command)->toContain('--stop-when-empty')
        ->and($drain->expression)->toBe('* * * * *')
        ->and($drain->withoutOverlapping)->toBeTrue();
});
