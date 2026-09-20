<?php

use Illuminate\Support\Facades\File;

/**
 * La couleur a une seule source, et ce test est ce qui l'y maintient.
 *
 * Sept pages ont porté chacune une copie d'une palette turquoise concurrente
 * du thème. Les copies n'avaient pas encore divergé — c'est un coup de chance,
 * pas une garantie, et la huitième copie serait aussi facile à écrire que les
 * sept premières.
 *
 * Le test lit les sources plutôt que le rendu : aucun test de ce projet ne
 * monte de composant, et l'invariant visé est structurel, pas visuel.
 */
test('no Vue file redefines the palette that app.css owns', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        // Une *déclaration* commence la ligne ; `var(--apha-primary)` est un
        // usage et reste parfaitement légitime.
        if (preg_match('/^\s*--apha-[a-z-]+\s*:/m', $file->getContents()) === 1) {
            $offenders[] = str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

test('no Vue file shadows a theme colour token either', function () {
    // Découvert en cours de lot : quatre fichiers redéfinissaient la même
    // palette turquoise sous les noms du thème — `--primary`, `--ink`,
    // `--gold`, `--border`. Plus grave que les copies `--apha-*`, puisque ces
    // noms-là servent tout le système de design.
    //
    // `--muted` et `--light` sont volontairement absents de cette liste : les
    // pages les emploient comme couleurs de texte, alors que le thème réserve
    // `--muted` à une surface. Le conflit de nom est réel et se règle par un
    // renommage aux lots 3 et 4, pas par une suppression qui rendrait le
    // texte secondaire presque blanc.
    $owned = [
        'primary', 'primary-dark', 'primary-soft',
        'gold', 'gold-soft', 'gold-mid', 'gold-dark',
        'terracotta', 'terracotta-soft', 'terracotta-dark',
        'officine', 'officine-dark', 'officine-soft',
        'ink', 'border', 'background', 'cream',
    ];

    $pattern = '/^\s*--('.implode('|', array_map('preg_quote', $owned)).')\s*:/m';
    $offenders = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        if (preg_match($pattern, $file->getContents()) === 1) {
            $offenders[] = str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

test('app.css is the file that does define the palette', function () {
    // Le premier test passerait aussi si les alias disparaissaient de partout,
    // y compris de leur source — les 338 usages tomberaient alors sans
    // couleur, et rien ne rougirait.
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain('--apha-primary: var(--officine)')
        ->and($css)->toContain('--officine: #14764c');
});
