<?php

use App\Enums\DeclarationStatus;
use App\Enums\PenaltySettlement;
use App\Enums\StatsPeriod;
use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use App\Services\Network\NetworkPenaltyClaims;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 19));
});

/**
 * Autant d'officines, chacune avec une facture de mars d'un million jamais
 * réglée : à 60 jours et 2 %, 4 tranches de 20 000 au 19/09.
 *
 * @return list<Declaration>
 */
function claimsDeclare(Insurer $insurer, int $count): array
{
    return Pharmacy::factory()->count($count)->create()->map(fn (Pharmacy $pharmacy) => Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'insurer_id' => $insurer->id,
        'period_year' => 2026,
        'period_month' => 3,
        'amount_invoiced' => 1_000_000,
        'amount_received' => 0,
        'status' => DeclarationStatus::Unpaid,
        'is_status_manual' => true,
        'invoice_deposited_on' => '2026-03-31',
        'paid_on' => null,
    ]))->all();
}

/**
 * @return array<string, mixed>
 */
function claimsFor(?string $city = null): array
{
    [$from, $to] = StatsPeriod::LastTwelveMonths->bounds();

    return app(NetworkPenaltyClaims::class)->for($from, $to, $city);
}

test('the network admin reads what each insurer still owes today', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    claimsDeclare($insurer, 5);

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.penalty-ledger'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('penaltyClaims')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('penaltyClaims.publishedDue', 400_000)
                ->where('penaltyClaims.insurers.0', [
                    'id' => $insurer->id,
                    'name' => 'NSIA',
                    'triggerDays' => 60,
                    'ratePercent' => 2,
                    'withheld' => false,
                    'note' => null,
                    'due' => 400_000,
                    'recovered' => 0,
                    'waived' => 0,
                    // Déposée le 31/03, toujours due au 19/09.
                    'longestDelayDays' => 172,
                ])));
});

test('an insurer under the threshold is listed, withheld, and left out of the total', function () {
    $published = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'NSIA']);
    $hidden = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => 'SUNU']);
    claimsDeclare($published, 5);
    claimsDeclare($hidden, 2);

    $claims = claimsFor();
    $sunu = collect($claims['insurers'])->firstWhere('name', 'SUNU');

    expect($claims['publishedDue'])->toBe(400_000)
        ->and($sunu)->toMatchArray(['withheld' => true, 'due' => null, 'recovered' => null, 'waived' => null, 'longestDelayDays' => null])
        ->and($sunu['note'])->toContain('moins de 5 officines')
        ->and(array_column($claims['insurers'], 'name'))->toBe(['NSIA', 'SUNU']);
});

test('the due is published even when a single officine closed its penalty', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    [$closed] = claimsDeclare($insurer, 6);
    $closed->settlePenalty(PenaltySettlement::Waived, 80_000, User::factory()->create());

    $claim = claimsFor()['insurers'][0];

    // Décision du 11/10/2026 : la due (cinq officines) se publie seule ;
    // l'abandonnée, sur une officine, nommerait son montant exact.
    expect($claim)->toMatchArray(['withheld' => false, 'due' => 400_000, 'recovered' => null, 'waived' => null])
        ->and($claim['note'])->toStartWith('Déjà payée et abandonnée retenues')
        ->and(claimsFor()['publishedDue'])->toBe(400_000);
});

test('a due resting on too few officines stays withheld', function () {
    $insurer = Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create();
    [$closed] = claimsDeclare($insurer, 5);
    $closed->settlePenalty(PenaltySettlement::Waived, 80_000, User::factory()->create());

    $claim = claimsFor()['insurers'][0];

    expect($claim)->toMatchArray(['withheld' => false, 'due' => null])
        ->and($claim['note'])->toStartWith('Pénalité exigible retenue')
        ->and(claimsFor()['publishedDue'])->toBe(0);
});

test('an insurer without a penalty clause has no line', function () {
    claimsDeclare(Insurer::factory()->create(), 5);

    expect(claimsFor()['insurers'])->toBe([]);
});
