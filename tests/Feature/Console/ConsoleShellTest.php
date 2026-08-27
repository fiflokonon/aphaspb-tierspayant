<?php

use App\Models\Insurer;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

test('an admin gets the admin shell with its space and both notices', function () {
    // Asserted on a screen that will stay a screen: pointing this at whatever
    // happens to be a placeholder makes it break each time one is filled in.
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.space', 'ESPACE ADMIN')
            ->has('console.nav', 6)
            ->has('console.notices', 2)
            ->where('console.notices.0.title', 'Vue anonymisée')
            ->where('console.notices.1.title', "Seuil d'affichage"),
        );
});

test('the admin shell marks the current entry active and only that one', function () {
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.insurers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $nav = collect($page->toArray()['props']['console']['nav']);

            expect($nav->where('active', true)->pluck('label')->all())
                ->toBe(['Gestion des assureurs']);
        });
});

test('the anonymity notice states the threshold and the masked count', function () {
    Insurer::factory()->create();

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.notices.1.body', '5 pharmacies minimum · 0 assureur masqué ce trimestre.'),
        );
});

test('a pharmacy gets the pharmacy shell, without space or notice', function () {
    // Asserted on the dashboard rather than on a page that happens to be a
    // placeholder today: this test is about the shell, and must not break each
    // time a waiting page is filled in.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.space', null)
            ->has('console.nav', 5)
            ->has('console.notices', 0),
        );
});

test('a pharmacy cannot reach the admin space and an admin cannot declare', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.network'))
        ->assertForbidden();

    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('pharmacy.declare'))
        ->assertForbidden();
});

test('every navigation entry of both shells resolves to a real page', function () {
    $admin = User::factory()->networkAdmin()->create();

    foreach (['admin.network', 'admin.pharmacies', 'admin.insurers', 'admin.csv-exports'] as $name) {
        $this->actingAs($admin)->get(route($name))->assertOk();
    }

    $pharmacy = User::factory()->create();

    foreach (['pharmacy.declare', 'pharmacy.history', 'pharmacy.insurers'] as $name) {
        $this->actingAs($pharmacy)->get(route($name))->assertOk();
    }
});

test('a guest is sent to Joomla to log in', function () {
    $this->get(route('admin.network'))->assertRedirect(route('login'));
});
