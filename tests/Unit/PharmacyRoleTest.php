<?php

use App\Enums\PharmacyRole;

test('each role carries its French label', function () {
    expect(PharmacyRole::Owner->label())->toBe('Titulaire')
        ->and(PharmacyRole::Admin->label())->toBe('Gestionnaire')
        ->and(PharmacyRole::Member->label())->toBe('Membre');
});

test('the assignable roles exclude the titulaire and carry the same labels', function () {
    expect(PharmacyRole::assignable())->toBe([
        ['value' => 'admin', 'label' => 'Gestionnaire'],
        ['value' => 'member', 'label' => 'Membre'],
    ]);
});
