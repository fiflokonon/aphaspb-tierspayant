<?php

use App\Models\User;
use App\Support\ConsoleNavigation;
use Illuminate\Support\Facades\File;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * Le PHP choisit la clé, le Vue la traduit : ce test est la seule chose qui
 * relie les deux listes.
 *
 * Il lit la source plutôt que de monter un composant — Vitest tourne ici en
 * environnement node et ne monte rien. Une clé émise sans entrée dans la
 * table ne rendrait aucune icône, en silence, sur un écran de production.
 */
test('every icon key the server can emit exists in navIcons.ts', function () {
    $map = File::get(resource_path('js/lib/navIcons.ts'));

    $emitted = [];

    foreach ([
        User::factory()->networkAdmin()->notOnboarded()->create(),
        User::factory()->create(),
    ] as $user) {
        foreach (app(ConsoleNavigation::class)->forUser($user, '/')['nav'] as $item) {
            $emitted[] = $item['icon'];
        }
    }

    $emitted = array_values(array_unique($emitted));

    expect($emitted)->not->toBeEmpty();

    $missing = array_values(array_filter(
        $emitted,
        // Prettier retire les guillemets des clés qui sont des identifiants
        // valides : `history:` et `'building-2':` coexistent légitimement.
        fn (string $key): bool => preg_match(
            "/(^|\s|\{)'?".preg_quote($key, '/')."'?\s*:/m",
            $map,
        ) !== 1,
    ));

    expect($missing)->toBe([]);
});
