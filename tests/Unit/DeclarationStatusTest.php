<?php

use App\Enums\DeclarationStatus;

/**
 * NetworkStatsService writes these values literally into its SQL CASE
 * expressions. Renaming one would desynchronise the statistics silently, so
 * this test pins them.
 */
test('the stored status values are the ones the aggregate SQL assumes', function () {
    expect(DeclarationStatus::Paid->value)->toBe('paid')
        ->and(DeclarationStatus::Partial->value)->toBe('partial')
        ->and(DeclarationStatus::Unpaid->value)->toBe('unpaid')
        ->and(DeclarationStatus::Rejected->value)->toBe('rejected');
});

test('exactly two statuses carry a payment delay', function () {
    expect(DeclarationStatus::settledValues())->toBe(['paid', 'partial']);
});

test('unpaid and rejected carry no delay', function () {
    expect(DeclarationStatus::Unpaid->isSettled())->toBeFalse()
        ->and(DeclarationStatus::Rejected->isSettled())->toBeFalse();
});

test('every status has a French label', function () {
    foreach (DeclarationStatus::cases() as $status) {
        expect($status->label())->not->toBeEmpty();
    }
});
