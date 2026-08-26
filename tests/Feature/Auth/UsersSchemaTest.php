<?php

use Illuminate\Support\Facades\Schema;

test('the users table carries the Joomla identity', function () {
    expect(Schema::hasColumns('users', [
        'joomla_user_id',
        'joomla_groups',
        'token_version',
    ]))->toBeTrue();
});

test('the users table holds no credential of its own', function () {
    expect(Schema::hasColumn('users', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'remember_token'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse()
        ->and(Schema::hasTable('passkeys'))->toBeFalse();
});
