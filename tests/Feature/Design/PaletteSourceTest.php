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
        if (preg_match('/(?:^|[{;])\s*--apha-[a-z-]+\s*:/m', $file->getContents()) === 1) {
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

    $pattern = '/(?:^|[{;])\s*--('.implode('|', array_map('preg_quote', $owned)).')\s*:/m';
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

test('every custom property a Vue file reads is actually defined somewhere', function () {
    // La garde que ce lot appelait, et qu'il n'avait pas : son invariant est
    // « une page dépend désormais de :root au lieu d'elle-même », et rien
    // n'affirmait que cette dépendance se résout.
    //
    // Deux tokens y avaient échappé — `--apha-card` et `--apha-gold-dark`,
    // supprimés de History.vue sans équivalent dans :root. Une variable non
    // résolue rend la déclaration invalide : le fond tombe à `transparent`,
    // la couleur de texte revient à celle du parent. Rien ne rougit, et ça
    // ne se voit qu'à l'écran.
    $css = File::get(resource_path('css/app.css'));
    preg_match_all('/(?:^|[{;])\s*(--[a-z0-9-]+)\s*:/mi', $css, $m);
    $global = array_flip($m[1]);

    $unresolved = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        $body = $file->getContents();

        // Ce que le fichier déclare pour lui-même compte comme défini.
        preg_match_all('/(?:^|[{;])\s*(--[a-z0-9-]+)\s*:/mi', $body, $own);
        $known = $global + array_flip($own[1]);

        // `var(--x, repli)` porte sa propre issue de secours : hors sujet.
        preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*\)/i', $body, $used);

        foreach (array_unique($used[1]) as $token) {
            // Les variables des bibliothèques et celles posées par le script
            // ne sont pas déclarées en CSS, et c'est normal.
            if (str_starts_with($token, '--reka-') || str_starts_with($token, '--sidebar-width')
                || str_starts_with($token, '--row-') || str_starts_with($token, '--tw-')) {
                continue;
            }

            if (! isset($known[$token])) {
                $unresolved[] = str_replace(resource_path('js').'/', '', $file->getPathname()).' → '.$token;
            }
        }
    }

    expect($unresolved)->toBe([]);
});

test('a cleaned file writes no colour literal', function () {
    // La dérive est revenue par là : les tokens étaient propres, et 293
    // hexadécimaux vivaient dans les pages. Interdire le littéral est le seul
    // moyen de rendre le nettoyage durable — les trois premiers cas
    // n'interdisent que de *redéclarer* un token, pas d'en réécrire la valeur.
    //
    // La liste s'allonge à chaque lot plutôt que de viser tout le dépôt : les
    // écrans du lot 4 portent encore des dizaines d'occurrences de turquoise,
    // et une garde qui rougit en permanence ne protège rien.
    // `css/app.css` est volontairement absent : hors de :root et de @theme, il
    // ne contient plus que le bloc [data-sidebar='sidebar'], du CSS mort qui
    // cible les composants shadcn ui/sidebar — présents mais importés nulle
    // part. Le supprimer est une décision à prendre, pas un effet de bord de
    // cette garde.
    $cleaned = [
        'js/layouts/console/ConsoleHeader.vue',
        'js/layouts/console/ConsoleLayout.vue',
        'js/layouts/console/ConsoleSidebar.vue',
        'js/pages/pharmacy/Dashboard.vue',
        'js/components/aphaspb/DashboardKpis.vue',
        'js/components/aphaspb/KpiCard.vue',
    ];

    $offenders = [];

    foreach ($cleaned as $relative) {
        $body = File::get(resource_path($relative));

        // Seul le bloc <style> est concerné : une couleur passée en chaîne à
        // une bibliothèque de graphiques ne peut pas citer un token CSS.
        $at = strpos($body, '<style');
        $body = $at === false ? '' : substr($body, $at);

        // Les commentaires citent volontiers les anciennes valeurs.
        $body = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $body);

        // La fonction est capturée en entier, pas seulement son ouverture :
        // sans cela, `rgb(255 255 255 / .16)` remonte comme « rgb(2 » et on ne
        // peut plus distinguer le blanc d'une teinte.
        preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/i', $body, $found);

        foreach ($found[0] as $literal) {
            // Le blanc et le noir ne sont pas des teintes de charte.
            $canonical = preg_replace('/[\s,]+/', ' ', strtolower($literal));

            if (preg_match('/^#(fff|ffffff|000|000000)$/', $canonical) === 1
                || preg_match('/^rgba?\( ?(255 255 255|0 0 0)\b/', $canonical) === 1) {
                continue;
            }

            $offenders[] = $relative.' → '.$literal;
        }
    }

    expect($offenders)->toBe([]);
});
