<?php

use App\Actions\Declarations\RecordDeclarationRevision;
use App\Enums\DeclarationStatus;
use App\Enums\PharmacyRole;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 8, 15));
});

/**
 * An officine working with $count named insurers, in a known order.
 *
 * @return array{0: User, 1: Collection<int, Insurer>}
 */
function officineWith(int $count): array
{
    $user = User::factory()->notOnboarded()->create();

    $pharmacy = Pharmacy::factory()->create();
    $pharmacy->members()->attach($user, ['role' => PharmacyRole::Owner->value]);
    $user->switchPharmacy($pharmacy);

    // Names are unique across the table, so they are scoped by pharmacy id.
    // Within one officine they stay alphabetical, which is the wizard's order.
    $insurers = collect(range(1, $count))->map(
        fn (int $i) => Insurer::factory()->create([
            'name' => sprintf('Officine %d · Assureur %02d', $pharmacy->id, $i),
        ]),
    );

    $pharmacy->insurers()->attach($insurers->pluck('id'));

    return [$user->fresh(), $insurers];
}

/** @return array<string, mixed> */
function declarationPayload(Insurer $insurer, array $overrides = []): array
{
    return [
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 1_240_000,
        'invoice_deposited_on' => '2026-08-01',
        // Le montant reçu ne se poste plus : il est la somme des versements.
        'payments' => [
            ['amount' => 860_000, 'paid_on' => '2026-08-12'],
        ],
        ...$overrides,
    ];
}

test('the screen shows the first insurer not yet declared this month', function () {
    [$user, $insurers] = officineWith(3);

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/Declare')
            ->where('insurer.id', $insurers[0]->id)
            ->where('progress.current', 1)
            ->where('progress.total', 3)
            ->where('period.year', 2026)
            ->where('period.month', 8),
        );
});

test('the progress counts ticked insurers, not every insurer in the table', function () {
    [$user] = officineWith(2);
    Insurer::factory()->count(5)->create();

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('progress.total', 2));
});

test('saving advances to the next insurer', function () {
    [$user, $insurers] = officineWith(3);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]))
        ->assertRedirect(route('pharmacy.declare'));

    $this->actingAs($user->fresh())
        ->get(route('pharmacy.declare'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer.id', $insurers[1]->id)
            ->where('progress.current', 2),
        );
});

test('an officine coming back later resumes where it stopped', function () {
    [$user, $insurers] = officineWith(3);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 8,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('insurer.id', $insurers[1]->id));
});

test('the done screen appears once every insurer is declared', function () {
    [$user, $insurers] = officineWith(2);

    foreach ($insurers as $insurer) {
        Declaration::factory()->paid()->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => $insurer->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    $this->actingAs($user)
        ->get(route('pharmacy.declare'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('pharmacy/DeclareDone')
            ->where('declared', 2),
        );
});

test('an insurer can be revisited to be corrected', function () {
    [$user, $insurers] = officineWith(3);

    Declaration::factory()->paid()->create([
        'pharmacy_id' => $user->currentPharmacy->id,
        'insurer_id' => $insurers[0]->id,
        'period_year' => 2026,
        'period_month' => 8,
        'amount_invoiced' => 500_000,
        'amount_received' => 500_000,
    ]);

    $this->actingAs($user)
        ->get(route('pharmacy.declare', ['insurer' => $insurers[0]->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('insurer.id', $insurers[0]->id)
            ->where('declaration.amount_invoiced', 500_000),
        );
});

test('two amounts are enough and the status is derived', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    $declaration = Declaration::query()->sole();

    expect($declaration->status)->toBe(DeclarationStatus::Partial)
        ->and($declaration->is_status_manual)->toBeFalse()
        ->and($declaration->amount_received)->toBe(860_000)
        ->and($declaration->amount_outstanding)->toBe(380_000);
});

test('receiving more than was invoiced is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'payments' => [
                ['amount' => 800_000, 'paid_on' => '2026-08-05'],
                ['amount' => 1_200_000, 'paid_on' => '2026-08-12'],
            ],
        ]))
        ->assertSessionHasErrors('payments');
});

test('a negative or non numeric amount is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_invoiced' => -5,
        ]))
        ->assertSessionHasErrors('amount_invoiced');

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_invoiced' => 'beaucoup',
        ]))
        ->assertSessionHasErrors('amount_invoiced');
});

test('the deposit date is required, and so is the date of every instalment', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'invoice_deposited_on' => null,
            'payments' => [
                ['amount' => 860_000, 'paid_on' => null],
            ],
        ]))
        ->assertSessionHasErrors(['invoice_deposited_on', 'payments.0.paid_on']);
});

test('a versement without its date is refused, whichever line it is', function () {
    [$user, $insurers] = officineWith(1);

    // Un montant encaissé implique une date. La règle vaut ligne par ligne :
    // c'est ce qui empêche un second versement de rejoindre la déclaration en
    // amputant le délai de sa seconde borne.
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'payments' => [
                ['amount' => 400_000, 'paid_on' => '2026-08-05'],
                ['amount' => 460_000, 'paid_on' => null],
            ],
        ]))
        ->assertSessionHasErrors('payments.1.paid_on');

    expect(Declaration::query()->count())->toBe(0);
});

test('the delay is computed from the two dates, never submitted', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'invoice_deposited_on' => '2026-08-02',
        'payments' => [
            ['amount' => 860_000, 'paid_on' => '2026-08-13'],
        ],
        // Smuggled in: the client has no say over the delay any more.
        'delay_days' => 3,
        'amount_received' => 3,
    ]));

    $declaration = Declaration::query()->sole();

    expect($declaration->delay_days)->toBe(11)
        ->and($declaration->amount_received)->toBe(860_000);
});

test('a payment date before the deposit date is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'invoice_deposited_on' => '2026-08-10',
            'payments' => [
                ['amount' => 860_000, 'paid_on' => '2026-08-02'],
            ],
        ]))
        ->assertSessionHasErrors('payments.0.paid_on');
});

test('a date in the future is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'payments' => [
                ['amount' => 860_000, 'paid_on' => '2026-08-16'],
            ],
        ]))
        ->assertSessionHasErrors('payments.0.paid_on');
});

test('a deposit date before the declared month is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'invoice_deposited_on' => '2026-07-31',
            'payments' => [
                ['amount' => 860_000, 'paid_on' => '2026-08-05'],
            ],
        ]))
        ->assertSessionHasErrors('invoice_deposited_on');
});

test('a versement is refused on an invoice declared rejected', function () {
    [$user, $insurers] = officineWith(1);

    // Rejetée veut dire que l'assureur a refusé la facture : il n'a donc rien
    // viré, et une ligne de versement contredirait le statut choisi à la main.
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'status' => 'rejected',
        ]))
        ->assertSessionHasErrors('payments');
});

test('choosing rejected explicitly is kept and survives a resave', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'payments' => [],
        'status' => 'rejected',
    ]));

    $declaration = Declaration::query()->sole();

    expect($declaration->status)->toBe(DeclarationStatus::Rejected)
        ->and($declaration->is_status_manual)->toBeTrue();

    $declaration->update(['amount_received' => 1_240_000]);

    expect($declaration->fresh()->status)->toBe(DeclarationStatus::Rejected);
});

test('a month settled in two transfers keeps both, and totals them', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-08-01',
            'payments' => [
                ['amount' => 400_000, 'paid_on' => '2026-08-06'],
                ['amount' => 600_000, 'paid_on' => '2026-08-13'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $declaration = Declaration::query()->sole();

    expect($declaration->payments)->toHaveCount(2)
        ->and($declaration->amount_received)->toBe(1_000_000)
        ->and($declaration->status)->toBe(DeclarationStatus::Paid)
        ->and($declaration->amount_outstanding)->toBe(0);
});

test('the delay of a month paid in two goes is counted to the last transfer', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-08-01',
        'payments' => [
            ['amount' => 400_000, 'paid_on' => '2026-08-06'],
            ['amount' => 600_000, 'paid_on' => '2026-08-13'],
        ],
    ]));

    $declaration = Declaration::query()->sole();

    // Le mois est jugé sur la date à laquelle il a fini d'être réglé, pas sur
    // celle où l'assureur a commencé à payer.
    expect($declaration->paid_on->toDateString())->toBe('2026-08-13')
        ->and($declaration->delay_days)->toBe(12);
});

test('each transfer carries its own delay, whatever order it was typed in', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-08-01',
        // Saisis à l'envers : c'est la date qui ordonne, pas le formulaire.
        'payments' => [
            ['amount' => 600_000, 'paid_on' => '2026-08-13'],
            ['amount' => 400_000, 'paid_on' => '2026-08-06'],
        ],
    ]));

    expect(Declaration::query()->sole()->payments->map(
        fn ($payment): array => [$payment->amount, $payment->delay_days],
    )->all())->toBe([[400_000, 5], [600_000, 12]]);
});

test('correcting the deposit date recomputes every transfer delay', function () {
    [$user, $insurers] = officineWith(1);

    $payload = declarationPayload($insurers[0], [
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-08-01',
        'payments' => [
            ['amount' => 400_000, 'paid_on' => '2026-08-06'],
            ['amount' => 600_000, 'paid_on' => '2026-08-13'],
        ],
    ]);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), $payload);

    // La facture avait en fait été déposée trois jours plus tard : sans
    // recalcul, les lignes garderaient un délai mesuré depuis une date morte.
    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), [
        ...$payload,
        'invoice_deposited_on' => '2026-08-04',
    ]);

    expect(Declaration::query()->sole()->payments->pluck('delay_days')->all())
        ->toBe([2, 9]);
});

test('removing every transfer empties the totals instead of leaving a stale one', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'payments' => [],
    ]));

    $declaration = Declaration::query()->sole();

    expect($declaration->payments)->toHaveCount(0)
        ->and($declaration->amount_received)->toBe(0)
        ->and($declaration->paid_on)->toBeNull()
        ->and($declaration->delay_days)->toBeNull()
        ->and($declaration->status)->toBe(DeclarationStatus::Unpaid);
});

test('the screen hands back the transfers so a month can be corrected line by line', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-08-01',
        'payments' => [
            ['amount' => 400_000, 'paid_on' => '2026-08-06'],
            ['amount' => 600_000, 'paid_on' => '2026-08-13'],
        ],
    ]));

    $this->actingAs($user->fresh())
        ->get(route('pharmacy.declare', ['insurer' => $insurers[0]->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('declaration.payments', 2)
            ->where('declaration.payments.0.amount', 400_000)
            ->where('declaration.payments.0.paid_on', '2026-08-06')
            ->where('declaration.payments.1.amount', 600_000)
            ->where('declaration.payments.1.paid_on', '2026-08-13'),
        );
});

test('a malformed list of transfers is refused, never crashed on', function () {
    [$user, $insurers] = officineWith(1);

    // Le total des versements est calculé par un after() qui s'exécute même
    // quand les règles par ligne ont déjà échoué : il est donc atteint avec ce
    // que le client a bien voulu envoyer.
    foreach ([['payments' => 'beaucoup'], ['payments' => [1, 2]], ['payments' => [['amount' => 400_000]]]] as $malformed) {
        $this->actingAs($user->fresh())
            ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], $malformed))
            ->assertSessionHasErrors();
    }

    expect(Declaration::query()->count())->toBe(0);
});

test('each save that changes something leaves a revision, and names its author', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'payments' => [
            ['amount' => 860_000, 'paid_on' => '2026-08-12'],
            ['amount' => 380_000, 'paid_on' => '2026-08-14'],
        ],
    ]));

    $revisions = Declaration::query()->sole()->revisions;

    expect($revisions)->toHaveCount(2)
        ->and($revisions[0]->amount_received)->toBe(860_000)
        ->and($revisions[0]->payments)->toHaveCount(1)
        // Le détail des versements survit à leur réécriture : c'est ce que la
        // suppression-recréation faisait disparaître.
        ->and($revisions[1]->amount_received)->toBe(1_240_000)
        ->and($revisions[1]->payments)->toBe([
            ['amount' => 860_000, 'paid_on' => '2026-08-12', 'delay_days' => 11],
            ['amount' => 380_000, 'paid_on' => '2026-08-14', 'delay_days' => 13],
        ])
        ->and($revisions[1]->author_name)->toBe($user->name)
        ->and($revisions[1]->user_id)->toBe($user->id);
});

test('re-saving an untouched month adds no revision', function () {
    [$user, $insurers] = officineWith(1);

    // Rouvrir une déclaration pour vérifier un chiffre puis la renvoyer telle
    // quelle est le geste le plus courant : le compter ferait de « modifiée
    // 4 fois » un compteur de visites.
    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));
    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    expect(Declaration::query()->sole()->revisions)->toHaveCount(1);
});

test('the trace keeps no copy of the private note', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'private_note' => 'Relancer le service comptable de l\'assureur.',
    ]));

    $revision = Declaration::query()->sole()->revisions->sole();

    expect($revision->getAttributes())->not->toHaveKey('private_note')
        ->and(json_encode($revision->getAttributes()))->not->toContain('comptable');
});

test('two transfers on the same day do not invent a correction on re-save', function () {
    [$user, $insurers] = officineWith(1);

    $payload = declarationPayload($insurers[0], [
        'amount_invoiced' => 1_000_000,
        'invoice_deposited_on' => '2026-08-01',
        // Même date, deux virements : sans départage stable, leur ordre varie
        // d'une lecture à l'autre et la comparaison de révisions voit un
        // changement là où rien n'a bougé.
        'payments' => [
            ['amount' => 400_000, 'paid_on' => '2026-08-10'],
            ['amount' => 600_000, 'paid_on' => '2026-08-10'],
        ],
    ]);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), $payload);
    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), $payload);
    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), $payload);

    expect(Declaration::query()->sole()->revisions)->toHaveCount(1);
});

test('an absurd number of transfers is refused rather than written', function () {
    [$user, $insurers] = officineWith(1);

    // Chaque ligne devient une ligne en base et rien côté client ne borne ce
    // que la requête transporte.
    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_invoiced' => 1_000_000,
            'payments' => array_fill(0, 25, ['amount' => 1_000, 'paid_on' => '2026-08-10']),
        ]))
        ->assertSessionHasErrors('payments');

    expect(Declaration::query()->count())->toBe(0);
});

test('a settled status posted without a single transfer is refused', function () {
    [$user, $insurers] = officineWith(1);

    // `status` est un champ ouvert sur une route publique. Sans ce garde-fou,
    // un POST forgé posait une déclaration « payée » sans un franc ni une date,
    // que les agrégats réseau comptaient comme réglée tout en la privant de
    // délai — moyenne et part dans les clous sous-estimées, en silence.
    foreach (['paid', 'partial'] as $status) {
        $this->actingAs($user->fresh())
            ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
                'status' => $status,
                'payments' => [],
            ]))
            ->assertSessionHasErrors('payments');
    }

    expect(Declaration::query()->count())->toBe(0);
});

test('a save whose revision cannot be written leaves nothing behind', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    // La trace échoue au second enregistrement. Sans transaction commune, la
    // déclaration et ses versements réécrits restaient committés : la trace
    // perdait cet enregistrement, et la sauvegarde suivante se comparait à une
    // révision périmée.
    $this->mock(RecordDeclarationRevision::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('trace indisponible'));

    try {
        $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'payments' => [
                ['amount' => 100_000, 'paid_on' => '2026-08-12'],
            ],
        ]));
    } catch (RuntimeException) {
        // Attendue : c'est l'échec que la transaction doit rattraper.
    }

    $declaration = Declaration::query()->sole();

    expect($declaration->amount_received)->toBe(860_000)
        ->and($declaration->payments)->toHaveCount(1)
        ->and($declaration->payments->first()->amount)->toBe(860_000);
});

test('a private note longer than 150 characters is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'private_note' => str_repeat('a', 151),
        ]))
        ->assertSessionHasErrors('private_note');
});

test('declaring the same insurer twice updates instead of duplicating', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));
    $this->actingAs($user->fresh())->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'payments' => [
            ['amount' => 1_240_000, 'paid_on' => '2026-08-14'],
        ],
    ]));

    $declaration = Declaration::query()->sole();

    expect(Declaration::query()->count())->toBe(1)
        ->and($declaration->status)->toBe(DeclarationStatus::Paid)
        // Les versements sont réécrits en entier : le premier ne survit pas à
        // la correction, sans quoi le total doublerait.
        ->and($declaration->payments)->toHaveCount(1)
        ->and($declaration->amount_received)->toBe(1_240_000);
});

test('a period beyond twelve months back, or in the future, is refused', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'period_year' => 2025, 'period_month' => 7,
        ]))
        ->assertSessionHasErrors('period');

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'period_year' => 2026, 'period_month' => 9,
        ]))
        ->assertSessionHasErrors('period');
});

test('an officine cannot declare for an insurer it has not ticked', function () {
    [$user] = officineWith(1);
    $stranger = Insurer::factory()->create();

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($stranger))
        ->assertSessionHasErrors('insurer_id');
});

test('an officine cannot declare for another officine', function () {
    [$user, $insurers] = officineWith(1);
    [$other] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0]));

    expect(Declaration::query()->sole()->pharmacy_id)->toBe($user->currentPharmacy->id)
        ->and(Declaration::query()->sole()->pharmacy_id)->not->toBe($other->currentPharmacy->id);
});

test('an admin account cannot reach the declaration screen', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('pharmacy.declare'))
        ->assertForbidden();
});

test('a private note recorded here never reaches the admin space', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
        'private_note' => 'motif absence ordonnance accentuée',
    ]));

    $props = inertiaPropsJson(
        $this->actingAs(User::factory()->networkAdmin()->create())
            ->get(route('admin.network')),
    );

    expect($props)->not->toContain('motif absence ordonnance accentuée')
        ->and($props)->not->toContain('private_note')
        ->and($props)->not->toContain('privateNote');
});

test('a declaration without a deposit date is refused, even unpaid', function () {
    [$user, $insurers] = officineWith(1);

    $this->actingAs($user)
        ->post(route('pharmacy.declare.store'), declarationPayload($insurers[0], [
            'amount_received' => 0,
            'paid_on' => null,
            'invoice_deposited_on' => null,
        ]))
        ->assertSessionHasErrors('invoice_deposited_on');
});
