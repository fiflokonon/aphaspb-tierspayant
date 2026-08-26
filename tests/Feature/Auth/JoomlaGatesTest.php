<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => useJoomlaTestKeys());

test('a network admin may read the aggregated network and manage insurers', function () {
    $admin = User::factory()->networkAdmin()->create();

    expect(Gate::forUser($admin)->allows('manage-network'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manage-insurers'))->toBeTrue();
});

test('a network admin may not declare payments', function () {
    $admin = User::factory()->networkAdmin()->create();

    expect(Gate::forUser($admin)->allows('declare-payments'))->toBeFalse();
});

test('a pharmacy may declare payments', function () {
    $pharmacy = User::factory()->create();

    expect(Gate::forUser($pharmacy)->allows('declare-payments'))->toBeTrue();
});

test('a pharmacy may not read the network or manage insurers', function () {
    $pharmacy = User::factory()->create();

    expect(Gate::forUser($pharmacy)->allows('manage-network'))->toBeFalse()
        ->and(Gate::forUser($pharmacy)->allows('manage-insurers'))->toBeFalse();
});

test('a user in no configured group is granted nothing', function () {
    $stranger = User::factory()->create(['joomla_groups' => [999]]);

    expect(Gate::forUser($stranger)->allows('manage-network'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('manage-insurers'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('declare-payments'))->toBeFalse();
});

test('a user with no groups at all is granted nothing', function () {
    $stranger = User::factory()->create(['joomla_groups' => null]);

    expect(Gate::forUser($stranger)->allows('manage-network'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('declare-payments'))->toBeFalse();
});
