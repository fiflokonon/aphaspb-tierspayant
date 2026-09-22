<?php

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;
use App\Support\ConsoleNavigation;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

test('an admin gets the admin shell with its space', function () {
    // Asserted on a screen that will stay a screen: pointing this at whatever
    // happens to be a placeholder makes it break each time one is filled in.
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.space', 'ESPACE ADMIN')
            ->has('console.nav', 5)
            ->has('console.account'),
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

test('a pharmacy gets the pharmacy shell, without space', function () {
    // Asserted on the dashboard rather than on a page that happens to be a
    // placeholder today: this test is about the shell, and must not break each
    // time a waiting page is filled in.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.space', null)
            ->has('console.nav', 5),
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

test('the shell carries the officine the session is on', function () {
    $user = User::factory()->create();
    $user->currentPharmacy->update(['name' => 'Pharmacie Le Bon Secours', 'city' => 'Cotonou']);

    // Portée par le shell et non par chaque contrôleur : l'en-tête de tous les
    // écrans l'affiche, et la faire voyager en prop obligerait six contrôleurs
    // à la répéter.
    $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.pharmacy.name', 'Pharmacie Le Bon Secours')
            ->where('console.account.pharmacy.city', 'Cotonou'),
        );
});

test('an admin has no officine in the shell', function () {
    // Un compte réseau porte pourtant une officine courante en base : c'est
    // l'espace qui décide, pas la relation. Sinon l'en-tête admin afficherait
    // le nom d'une officine au-dessus de chiffres agrégés.
    $admin = User::factory()->networkAdmin()->create();

    $this->actingAs($admin)
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.pharmacy', null),
        );
});

test('the shell says which space the session is in', function () {
    // L'en-tête annonce « Vous êtes dans l'espace… », et il lui faut de quoi
    // distinguer les deux espaces. Le libellé `console.space` ne convient pas :
    // c'est une étiquette d'affichage de la barre latérale.
    $this->actingAs(User::factory()->networkAdmin()->create())
        ->get(route('admin.network'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.administrator', true),
        );

    $pharmacist = User::factory()->create();

    $this->actingAs($pharmacist)
        ->get(route('dashboard', ['current_pharmacy' => $pharmacist->currentPharmacy->slug]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.administrator', false),
        );
});

test('an officine still onboarding has no officine in the shell either', function () {
    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('onboarding.profile'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('console.account.pharmacy', null),
        );
});

test('every navigation entry carries a non-empty icon key', function () {
    // L'icône vient du serveur pour que le front n'ait pas à deviner d'après
    // le libellé : les libellés de ce projet ont déjà été reformulés.
    $users = [
        User::factory()->networkAdmin()->notOnboarded()->create(),
        User::factory()->create(),
    ];

    foreach ($users as $user) {
        $nav = app(ConsoleNavigation::class)->forUser($user, '/')['nav'];

        expect($nav)->not->toBeEmpty();

        foreach ($nav as $item) {
            expect($item)->toHaveKey('icon')
                ->and($item['icon'])->toBeString()
                ->and($item['icon'])->not->toBe('');
        }
    }
});

test('a named entry carries the icon it is supposed to', function () {
    // Épingle une paire précise : sans cela, renommer un libellé pourrait
    // déplacer silencieusement une icône sur une autre entrée.
    $nav = app(ConsoleNavigation::class)
        ->forUser(User::factory()->networkAdmin()->notOnboarded()->create(), '/')['nav'];

    $byLabel = collect($nav)->keyBy('label');

    expect($byLabel['Statistiques réseau']['icon'])->toBe('chart-column')
        ->and($byLabel['Exports CSV']['icon'])->toBe('download');
});
