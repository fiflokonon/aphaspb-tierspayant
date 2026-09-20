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
    $source = File::get(resource_path('js/lib/navIcons.ts'));

    // Ne lire que le corps de l'objet, commentaires retirés. Sur le fichier
    // entier, une entrée mise en commentaire pendant un débogage — ou une
    // phrase de docblock comme « download : réservé aux exports » — suffisait
    // à faire passer le test pour une clé que la table ne connaît plus.
    expect(preg_match('/const ICONS[^=]*=\s*\{(.*?)\n\};/s', $source, $matches))->toBe(1);

    $map = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $matches[1]);

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
