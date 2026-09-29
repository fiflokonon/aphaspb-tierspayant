<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->travelTo(CarbonImmutable::create(2026, 9, 28));
    $this->admin = User::factory()->networkAdmin()->create();
    $this->insurer = Insurer::factory()->create(['name' => 'Assureur Secret']);
});

function followedPharmacy(string $name, ?string $phone = null, string $city = 'Cotonou'): Pharmacy
{
    $pharmacy = Pharmacy::factory()->create(['name' => $name, 'city' => $city, 'whatsapp_phone' => $phone, 'created_at' => '2026-01-10']);
    $pharmacy->insurers()->attach(test()->insurer);

    return $pharmacy;
}

test('an officine cannot open the follow-up', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.declarations-followup'))->assertForbidden();
});

test('the screen lists the officines to chase for last month, with a summary', function () {
    $done = followedPharmacy('Pharmacie Faite');
    Declaration::factory()->create(['pharmacy_id' => $done->id, 'insurer_id' => $this->insurer->id, 'period_year' => 2026, 'period_month' => 8]);
    followedPharmacy("Pharmacie L'Espérance", '+2290197000000');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/DeclarationFollowUp')
            ->where('month', '2026-08')
            ->where('state', 'to-chase')
            ->where('summary', ['complete' => 1, 'partial' => 0, 'none' => 1, 'total' => 2])
            ->has('pharmacies.data', 1)
            ->where('pharmacies.data.0.name', "Pharmacie L'Espérance")
            ->where('pharmacies.data.0.state', 'none')
            ->where('pharmacies.data.0.stateLabel', 'Sans déclaration'));
});

test('the WhatsApp link carries the message for the right month', function () {
    followedPharmacy("Pharmacie L'Espérance", '+2290197000000');

    $url = $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->viewData('page')['props']['pharmacies']['data'][0]['whatsappUrl'];

    $message = "Bonjour Pharmacie L'Espérance, votre déclaration de août 2026 sur la plateforme APhaSPB est à faire. "
        .'Vous pouvez la compléter ici : '.route('pharmacy.declare', ['year' => 2026, 'month' => 8]).'. Merci !';

    expect($url)->toBe('https://wa.me/2290197000000?text='.rawurlencode($message));
});

test('without number, no link', function () {
    followedPharmacy('Sans Numéro');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pharmacies.data.0.whatsappUrl', null));
});

test('a forged or current month falls back to last month', function (string $month) {
    followedPharmacy('Une');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup', ['month' => $month]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('month', '2026-08'));
})->with(['abc', '2020-01', '2026-09', '2026-13']);

test('the month list offers the twelve finished months only', function () {
    $this->actingAs($this->admin)->get(route('admin.declarations-followup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('months', 12)
            ->where('months.0.value', '2026-08')
            ->where('months.11.value', '2025-09'));
});

test('state and city filters', function () {
    followedPharmacy('Cotonou Rien');
    followedPharmacy('Parakou Rien', city: 'Parakou');

    $this->actingAs($this->admin)->get(route('admin.declarations-followup', ['city' => 'Parakou', 'state' => 'all']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('pharmacies.data', 1)->where('pharmacies.data.0.name', 'Parakou Rien'));
});

test('the screen never carries an insurer, an amount or a private note', function () {
    $pharmacy = followedPharmacy('Pharmacie Discrète', '+2290197000000');
    Declaration::factory()->create([
        'pharmacy_id' => $pharmacy->id, 'insurer_id' => $this->insurer->id,
        'period_year' => 2026, 'period_month' => 8,
        'amount_invoiced' => 7_654_321, 'private_note' => 'note très privée',
    ]);

    $response = $this->actingAs($this->admin)->get(route('admin.declarations-followup', ['state' => 'all']));
    $json = inertiaPropsJson($response);
    // La coquille nomme la page « Gestion des assureurs » : le mot « insurer »
    // se cherche donc dans les seules props de l'écran, pas dans la navigation.
    $own = json_encode(array_intersect_key(
        $response->viewData('page')['props'],
        array_flip(['month', 'months', 'city', 'cities', 'state', 'summary', 'pharmacies']),
    ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($json)->not->toContain('Assureur Secret')
        ->and($json)->not->toContain('7654321')
        ->and($json)->not->toContain('note très privée')
        ->and($own)->not->toContain('insurer')
        ->and($own)->not->toContain('amount');
});
