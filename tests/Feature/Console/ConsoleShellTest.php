<?php

use App\Enums\PharmacyRole;
use App\Models\Insurer;
use App\Models\Pharmacy;
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
            ->has('console.account')
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

test('the shell carries the account name and the logout route', function () {
    $user = User::factory()->create(['name' => 'Awa Hounkpatin']);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.name', 'Awa Hounkpatin')
            ->where('console.account.logoutHref', '/auth/logout'),
        );
});

test('a user still onboarding can reach the logout route too', function () {
    // The step where being stuck costs the most: no officine, so no console
    // navigation, and until now no way out of the session either.
    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('onboarding.profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.logoutHref', '/auth/logout'),
        );
});

test('the account lists the officines to switch between, current one flagged', function () {
    $user = User::factory()->create();
    $second = Pharmacy::factory()->create(['name' => 'Pharmacie Zenith']);

    $second->members()->attach($user, ['role' => PharmacyRole::Member->value]);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertInertia(function (AssertableInertia $page) use ($user, $second) {
            $pharmacies = collect($page->toArray()['props']['console']['account']['pharmacies']);

            expect($pharmacies)->toHaveCount(2)
                ->and($pharmacies->firstWhere('slug', $second->slug)['switchHref'])
                ->toBe(route('pharmacies.switch', ['pharmacy' => $second->slug], absolute: false))
                ->and($pharmacies->where('current', true)->pluck('slug')->all())
                ->toBe([$user->currentPharmacy->slug]);
        });
});

test('a single officine offers nothing to switch between', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('console.account.pharmacies', 0),
        );
});

test('a user in no mapped group still gets a shell with a way out', function () {
    // Neither manage-network nor declare-payments: until now this user got no
    // console descriptor at all, and once the starter kit goes away that would
    // leave them with no logout either.
    $this->actingAs(User::factory()->create(['joomla_groups' => []]))
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('console.nav', 0)
            ->has('console.notices', 0)
            ->where('console.account.logoutHref', '/auth/logout'),
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
