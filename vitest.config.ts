import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Le projet n'avait aucun runner JS : la logique de dessin de l'export PNG a
 * cassé deux fois en silence, sans que eslint, prettier, vue-tsc ni Pest ne
 * puissent le voir. Ces tests couvrent le calcul pur, pas le rendu.
 *
 * Environnement `node` et non `jsdom` : les tests bouchonnent eux-mêmes le peu
 * de DOM dont chartPng a besoin, ce qui évite une dépendance de plus et rend
 * visible ce sur quoi le code s'appuie réellement.
 */
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
});
