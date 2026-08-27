<?php

use App\Enums\DeclarationStatus;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

test('receiving nothing derives an unpaid status', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 0,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Unpaid);
});

test('receiving the full amount derives a paid status', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 1_240_000,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Paid);
});

test('receiving part of the amount derives a partial status', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 860_000,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Partial)
        ->and($declaration->amount_outstanding)->toBe(380_000);
});

test('a rejected status is never derived and survives a resave', function () {
    $declaration = Declaration::factory()->rejected()->create([
        'amount_invoiced' => 1_240_000,
        'amount_received' => 0,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Rejected)
        ->and($declaration->is_status_manual)->toBeTrue();

    $declaration->update(['amount_received' => 1_240_000]);

    expect($declaration->fresh()->status)->toBe(DeclarationStatus::Rejected);
});

test('a manual correction is not overwritten by the derivation', function () {
    $declaration = Declaration::factory()->create([
        'amount_invoiced' => 1_000_000,
        'amount_received' => 400_000,
    ]);

    expect($declaration->status)->toBe(DeclarationStatus::Partial);

    $declaration->update([
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
    ]);

    $declaration->update(['amount_received' => 500_000]);

    expect($declaration->fresh()->status)->toBe(DeclarationStatus::Unpaid);
});

test('the outstanding amount is derived, never stored', function () {
    expect(Schema::hasColumn('declarations', 'amount_outstanding'))->toBeFalse();
});

test('one declaration per insurer per month', function () {
    $pharmacy = Pharmacy::factory()->create();
    $insurer = Insurer::factory()->create();

    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    expect(fn () => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('the same month for two insurers is allowed', function () {
    $pharmacy = Pharmacy::factory()->create();

    Declaration::factory()->count(2)->sequence(
        ['insurer_id' => Insurer::factory()],
        ['insurer_id' => Insurer::factory()],
    )->create([
        'pharmacy_id' => $pharmacy->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    expect($pharmacy->declarations()->count())->toBe(2);
});

test('the settled scope keeps only the statuses that carry a delay', function () {
    Declaration::factory()->paid()->count(2)->create();
    Declaration::factory()->partial()->count(3)->create();
    Declaration::factory()->unpaid()->count(4)->create();
    Declaration::factory()->rejected()->count(5)->create();

    expect(Declaration::query()->settled()->count())->toBe(5);
});

test('the period scope filters on year and month together', function () {
    Declaration::factory()->create(['period_year' => 2026, 'period_month' => 8]);
    Declaration::factory()->create(['period_year' => 2025, 'period_month' => 8]);
    Declaration::factory()->create(['period_year' => 2026, 'period_month' => 7]);

    expect(Declaration::query()->forPeriod(2026, 8)->count())->toBe(1);
});

test('a private note is capped at 150 characters', function () {
    $declaration = Declaration::factory()->create([
        'private_note' => str_repeat('a', 150),
    ]);

    expect(strlen($declaration->private_note))->toBe(150);
});
