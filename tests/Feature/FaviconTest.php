<?php

test('pages point to the APhaSPB icons, not the starter kit ones', function () {
    $this->get(route('auth.denied'))
        ->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', escape: false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', escape: false)
        ->assertDontSee('favicon.svg');

    // The starter kit shipped Laravel's mark in both files; browsers prefer
    // the SVG whenever it exists, and the hash is the kit's .ico.
    expect(public_path('favicon.svg'))->not->toBeFile()
        ->and(md5_file(public_path('favicon.ico')))->not->toBe('08242d646ff63d0fa7f43b1f34868700');
});
