<?php

use Illuminate\Support\Facades\Route;

/**
 * The safety property is the one worth asserting: outside a local environment
 * the side door must not exist at all. The happy path is exercised by actually
 * running the application, which is the whole purpose of the route.
 */
test('the local login route does not exist outside a local environment', function () {
    expect(app()->isLocal())->toBeFalse()
        ->and(Route::has('dev.login'))->toBeFalse();
});

test('no route whatsoever is registered under the dev prefix', function () {
    $dev = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'dev/'));

    expect($dev)->toBeEmpty();
});
