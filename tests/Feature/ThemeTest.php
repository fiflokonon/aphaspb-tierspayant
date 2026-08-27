<?php

use Symfony\Component\Routing\Exception\RouteNotFoundException;

test('the appearance switcher is gone', function () {
    expect(fn () => route('appearance.edit'))
        ->toThrow(RouteNotFoundException::class);
});

test('the appearance middleware is gone', function () {
    expect(file_exists(app_path('Http/Middleware/HandleAppearance.php')))->toBeFalse();
});

test('the root view no longer switches on a dark class', function () {
    $blade = file_get_contents(resource_path('views/app.blade.php'));

    expect($blade)->not->toContain('dark')
        ->and($blade)->not->toContain('prefers-color-scheme');
});

test('the logo is published', function () {
    expect(file_exists(public_path('logo-aphaspb.webp')))->toBeTrue();
});
