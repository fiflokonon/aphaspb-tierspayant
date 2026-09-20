# Refonte visuelle — lot 3 : le tableau de bord officine

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire du tableau de bord l'écran que le client réclame — les
assureurs en retard en rouge plein, les panneaux sans données retirés, les
chiffres clés dans le bandeau sur mobile, et plus une seule couleur en dur.

**Architecture:** Quatre mouvements indépendants sur un seul fichier, plus une
garde. Les bandes changent de peinture, pas de structure : le contrôleur envoie
déjà `late` / `owing` / `settled` et le Vue pose les classes, donc **aucun code
PHP ne bouge**. Les chiffres clés sont extraits dans un composant rendu à deux
endroits, chacun masqué à la largeur de l'autre. Enfin un cinquième test de
`PaletteSourceTest` interdit les littéraux de couleur dans les fichiers déjà
assainis, et le lot 4 étendra cette liste.

**Tech Stack:** Inertia 3, Vue 3 `<script setup>`, Tailwind 4, Pest 4,
Chromium headless pour les captures.

**Spec:** `docs/superpowers/specs/2026-09-20-refonte-visuelle-design.md`

## Global Constraints

- **Aucune dépendance ajoutée ou modifiée** (règle projet, `CLAUDE.md`).
- **Aucune règle métier touchée.** Les 593 tests actuels restent verts sans
  qu'une assertion soit modifiée.
- **Aucun fichier PHP modifié.** Les tons des bandes ne remontent pas du
  serveur : `PaymentJourneyController::insurerBands()` rend des structures
  `late` / `owing` / `settled`, et c'est `Dashboard.vue` qui en tire des
  classes CSS. La spec listait ce contrôleur « si les tons remontent du
  serveur » — ils n'en viennent pas.
- **La couleur vient de `:root`.** `PaletteSourceTest` interdit déjà toute
  redéclaration locale et vérifie que chaque `var()` se résout ; ce lot y
  ajoute l'interdiction des littéraux.
- **Le projet est clair uniquement.**
- Le seuil responsive est **1024 px**.
- Vitest tourne en **environnement node** et ne monte aucun composant.

### Décision de conception à acter : le slot `#hero` demande deux emplacements

Le slot `#hero` bâti au lot 2 se rend **sous le titre, aux deux largeurs**. Or
les maquettes validées ne demandent pas la même chose des deux côtés :

- sur **grand écran**, l'ordre approuvé est **titre → bandes → chiffres** (la
  planche « rouge et accents », variante 1, retenue par le client) ;
- sur **mobile**, l'ordre approuvé est **bandeau portant titre *et* chiffres →
  bandes** (la planche « B et C », variante C pour le mobile).

Mettre les chiffres dans `#hero` et rien d'autre les ferait passer **avant**
les bandes sur grand écran, ce qui enterre le rouge que le client veut voir en
premier. Garder les chiffres où ils sont priverait le bandeau mobile de sa
raison d'être.

**Décision : la rangée est extraite dans `DashboardKpis.vue` et rendue à deux
endroits**, chacun masqué à la largeur de l'autre par CSS. `display: none`
retire l'élément de l'arbre d'accessibilité, donc aucun lecteur d'écran n'entend
les chiffres deux fois. Le coût est un composant de plus et du DOM dupliqué ;
l'alternative — un `<Teleport>` piloté par un observateur de largeur — ajoute
de l'état réactif pour le même rendu.

## File Structure

| Fichier | Rôle |
|---|---|
| `resources/js/pages/pharmacy/Dashboard.vue` | Bandes rouges, panneaux retirés, deux emplacements de chiffres, littéraux remplacés. |
| `resources/js/components/aphaspb/DashboardKpis.vue` | **Créé.** Les trois cartes de chiffres clés, source unique rendue deux fois. |
| `tests/Feature/Design/PaletteSourceTest.php` | Cinquième cas : pas de littéral de couleur dans les fichiers assainis. |

### Les couleurs en dur à remplacer

23 occurrences relevées dans `Dashboard.vue` le 21/09/2026. Correspondances :

| Littéral | Remplacement | Pourquoi |
|---|---|---|
| `#ffffff` (×4) | `#fff` | Autorisé par la garde : c'est du blanc, pas une teinte de charte. |
| `rgba(0, 143, 131, …)` (×6) | `color-mix(in srgb, var(--officine) N%, transparent)` | Turquoise résiduel, à convertir au vert. |
| `rgba(35, 70, 68, …)` (×4) | `color-mix(in srgb, var(--ink) N%, transparent)` | Encre en longhand. |
| `rgba(23, 33, 28, …)` (×2) | `color-mix(in srgb, var(--ink) N%, transparent)` | Ancienne encre `#17211c`. |
| `rgba(215, 163, 61, 0.45)` | `color-mix(in srgb, var(--gold-mid) 45%, transparent)` | Ancien or. |
| `rgba(255, 255, 255, 0.28)` | `rgb(255 255 255 / 0.28)` | Blanc : autorisé, mais en syntaxe moderne. |
| `#fffdf7` | `var(--gold-soft)` | Fond ambré. |
| `#faf8f3` | `var(--cream-state)` | Existe déjà comme token. |
| `#788585` | `var(--muted)` | Texte secondaire, déjà déclaré localement. |
| `#a2adad` | `var(--light)` | Idem. |

`N%` se lit de l'alpha du littéral : `rgba(x, y, z, 0.07)` devient `7%`.

### Le harnais de capture

`php artisan serve --port=8000` doit tourner. **`public/hot` a été supprimé au
lot 2** : l'application sert les assets construits, donc `npm run build` après
chaque modification, sinon la capture montre l'état précédent. Si un
`npm run dev` est relancé, il réécrira `public/hot` et les captures repasseront
par Vite — vérifier alors que le port annoncé écoute réellement.

```bash
mkdir -p /tmp/apha-shots
shot() {  # shot <profil> <nom> <url> <largeur>
  chromium --headless --disable-gpu --user-data-dir="/tmp/apha-prof-$1" \
    --virtual-time-budget=7000 --screenshot="/tmp/apha-shots/$2-$4.png" \
    --window-size="$4,1400" "$3" 2>/dev/null
}
# Session : chromium … 'http://localhost:8000/dev/login/officine', une fois.
```

---

### Task 1 : Les bandes de retard en rouge plein

La demande du client, telle qu'il l'a tranchée. La spec consigne la réserve —
trois blocs rouges de force égale ne se distinguent plus les uns des autres —
et le fait qu'elle a été écartée en connaissance de cause. **Ne pas la
rouvrir ici.**

**Files:**
- Modify: `resources/js/pages/pharmacy/Dashboard.vue`

**Interfaces:**
- Consumes: les classes `.insurer-banner.late` / `.owing` / `.settled` que le
  template pose déjà depuis `insurerBands`.
- Produces: rien de nommé.

- [ ] **Step 1 : Capturer l'état de référence**

```bash
shot officine bandes-avant 'http://localhost:8000/' 1440
shot officine bandes-avant 'http://localhost:8000/' 390
```

- [ ] **Step 2 : Peindre la bande de retard**

Remplacer la règle `.insurer-banner.late` :

```css
.insurer-banner.late {
    /*
      Rouge plein, décidé par le client contre l'avis consigné dans la spec :
      trois blocs de force égale ne se distinguent plus les uns des autres.
      Le blanc sur --terracotta donne 4,82:1, au-dessus du seuil AA de 4,5:1
      mais sans marge — ne pas éclaircir --terracotta sans recalculer.
    */
    border-color: var(--terracotta-dark);
    background: var(--terracotta);

    color: #fff;
}
```

- [ ] **Step 3 : Faire suivre ce que la bande contient**

Le nom est un `<Link>`, le montant un `<strong>`, le retard un `<span>` : sans
ces règles, chacun garde sa couleur d'encre sur fond rouge.

```css
.insurer-banner.late .insurer-banner-name,
.insurer-banner.late .insurer-banner-days,
.insurer-banner.late strong {
    color: #fff;
}

.insurer-banner.late .insurer-banner-icon {
    color: #fff;
}
```

- [ ] **Step 4 : Laisser l'ambre dilué**

`.insurer-banner.owing` reste tel quel — fond `var(--gold-soft)`, filet
`var(--gold)`. **C'est le choix du client**, interrogé séparément : le plein
devient le signal « en faute », et un assureur qui doit dans son délai n'est
pas en faute. Vérifier seulement que la règle n'a pas été touchée.

```bash
sed -n '/^\.insurer-banner\.owing {/,/^}/p' resources/js/pages/pharmacy/Dashboard.vue
```

Attendu : `border-color: var(--gold);` et `background: var(--gold-soft);`.

- [ ] **Step 5 : Construire et comparer**

```bash
npm run build
shot officine bandes-apres 'http://localhost:8000/' 1440
shot officine bandes-apres 'http://localhost:8000/' 390
```

Attendu : trois bandes rouge plein à texte blanc, puis une bande ambre claire,
puis la verte. Le lien du nom reste souligné et lisible. À 390 px, le texte
passe sur plusieurs lignes sans déborder.

- [ ] **Step 6 : Mesurer le contraste plutôt que de le supposer**

```bash
python3 - <<'PY'
def lum(c):
    def f(v):
        v /= 255
        return v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4
    r, g, b = (int(c[i:i+2], 16) for i in (1, 3, 5))
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)

def ratio(a, b):
    la, lb = sorted((lum(a), lum(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)

print(f"blanc sur --terracotta : {ratio('#ffffff', '#d13b22'):.2f}:1  (AA = 4,5)")
PY
```

Attendu : `4.82:1`. Si le chiffre est sous 4,5, **arrêter et le signaler** —
une bande illisible n'attire pas l'attention, elle la repousse.

- [ ] **Step 7 : Lancer la suite**

```bash
php artisan test --compact
```

Attendu : 593 verts. Aucune assertion ne porte sur la couleur.

- [ ] **Step 8 : Commit**

```bash
git add resources/js/pages/pharmacy/Dashboard.vue
git commit -m "style: passer les bandes de retard en rouge plein

Décision du client, consignée dans la spec avec l'avis contraire qu'elle
écarte. Le blanc sur --terracotta mesure 4,82:1, au-dessus du seuil AA sans
marge : ne pas éclaircir cette teinte sans recalculer.

La bande « dans le délai » reste en ambre dilué — le plein devient le signal
« en faute », et un assureur dans son délai ne l'est pas.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2 : Retirer les deux panneaux sans données

**Files:**
- Modify: `resources/js/pages/pharmacy/Dashboard.vue`

**Interfaces:**
- Consumes: rien.
- Produces: rien de nommé. Les classes `.dashboard-intro`, `.intro-left`,
  `.intro-icon`, `.intro-pulse`, `.intro-symbol`, `.intro-text`,
  `.intro-label`, `.intro-status`, `.status-dot`, `.dashboard-footnote` et
  `.footnote-icon` disparaissent du fichier, template **et** style.

- [ ] **Step 1 : Écrire le test qui échoue**

Le retrait doit être épinglé : sans cela, un futur copier-coller réintroduit le
panneau et personne ne le remarque. Le test porte sur du **contenu rendu**, ce
que la suite de ce projet sait faire.

À la fin de `tests/Feature/Pharmacy/PaymentJourneyTest.php` :

```php
test('the dashboard carries no panel that states only what the title says', function () {
    // « Suivez vos paiements » répétait le titre de l'écran sous une icône,
    // avec une pastille « Données actualisées » et aucune donnée. La note de
    // bas de page occupait une bande pleine pour une phrase.
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['current_pharmacy' => $user->currentPharmacy->slug]));

    $response->assertOk();

    expect($response->content())
        ->not->toContain('Suivez vos paiements')
        ->and($response->content())->not->toContain('Données actualisées');
});
```

- [ ] **Step 2 : Lancer, vérifier l'échec**

```bash
vendor/bin/pest tests/Feature/Pharmacy/PaymentJourneyTest.php --filter='no panel' --compact
```

Attendu : **ROUGE** — les deux chaînes sont dans la page.

> Si le test passe d'emblée, c'est que le rendu SSR n'est pas actif sous
> phpunit (`INERTIA_SSR_ENABLED=false` dans `phpunit.xml`) et que le contenu
> vit dans le JSON de la prop `page`. Il y est quand même : Inertia sérialise
> le composant et ses props, pas son template. Dans ce cas **le test est
> inopérant** — le supprimer et s'en remettre à la capture de l'étape 5,
> plutôt que de garder un test qui ne peut pas rougir.

- [ ] **Step 3 : Retirer le panneau d'introduction**

Supprimer du template le bloc `<section class="dashboard-intro"> … </section>`
en entier (il commence par `<div class="intro-left">` et finit après
`<div class="intro-status">`).

- [ ] **Step 4 : Démoter la note de bas de page**

Remplacer le bloc `<div class="dashboard-footnote"> … </div>` par une ligne
sans conteneur :

```html
        <p class="dashboard-source">
            Les indicateurs sont calculés à partir des déclarations transmises
            par les officines participantes.
        </p>
```

et sa règle de style par :

```css
/*
  Une ligne de métadonnée, plus un panneau : la phrase mérite d'être lisible,
  pas d'occuper une bande pleine avec une icône « i ».
*/
.dashboard-source {
    margin-top: 16px;

    color: var(--muted);

    font-size: 12.5px;
    line-height: 1.5;
}
```

- [ ] **Step 5 : Supprimer les règles devenues orphelines**

```bash
for c in dashboard-intro intro-left intro-icon intro-pulse intro-symbol \
         intro-text intro-label intro-status status-dot dashboard-footnote \
         footnote-icon; do
  echo "--- .$c ---"
  grep -n "\.$c" resources/js/pages/pharmacy/Dashboard.vue
done
```

Supprimer chaque règle listée, **y compris celles des requêtes média**. Le lot
1 a montré qu'une suppression au jugé emporte des accolades fermantes :
relire le diff avant de construire.

- [ ] **Step 6 : Construire et vérifier**

```bash
npm run build
npx vue-tsc --noEmit
vendor/bin/pest tests/Feature/Pharmacy/PaymentJourneyTest.php --compact
shot officine sans-panneaux 'http://localhost:8000/' 1440
shot officine sans-panneaux 'http://localhost:8000/' 390
```

Attendu : les bandes sont suivies directement des mois à rattraper, sans
panneau intercalaire. La phrase de source est une ligne grise en bas de page.
Aucune règle CSS orpheline, aucun débordement.

- [ ] **Step 7 : Commit**

```bash
git add resources/js/pages/pharmacy/Dashboard.vue tests/Feature/Pharmacy/PaymentJourneyTest.php
git commit -m "style: retirer les deux panneaux du tableau de bord qui ne portent rien

« Suivez vos paiements » répétait le titre sous une icône, avec une pastille
et aucune donnée. La note de bas de page occupait une bande pleine pour une
phrase : elle devient une ligne de métadonnée.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3 : Les chiffres clés à deux emplacements

Voir « Décision de conception » en tête de plan : l'ordre approuvé diffère
selon la largeur, et le slot `#hero` ne rend qu'à un endroit.

**Files:**
- Create: `resources/js/components/aphaspb/DashboardKpis.vue`
- Modify: `resources/js/pages/pharmacy/Dashboard.vue`

**Interfaces:**
- Consumes: le slot `#hero` de `ConsoleHeader`, bâti au lot 2 et gardé par
  `v-if="$slots.hero"`.
- Produces: `DashboardKpis`, qui prend une prop
  `summary: { invoiced: number; recoveryRate: number | null; averageDelayDays: number | null; insurers: number; declarations: number; received: number }`
  et une prop `tone: 'light' | 'band'`. L'étape 1 confronte cette forme à
  l'objet réellement rendu par le contrôleur : ne déclarer que les champs que
  les trois cartes lisent, un champ de trop se remarquerait au premier
  `vue-tsc`.

- [ ] **Step 1 : Relever la forme exacte de `summary`**

Les cartes lisent `summary.*` ; le composant extrait doit recevoir le même
objet, sans le reconstruire.

```bash
grep -n 'summary' resources/js/pages/pharmacy/Dashboard.vue | head -20
grep -n -A12 'summary' app/Http/Controllers/Pharmacy/PaymentJourneyController.php | grep -E "'(invoiced|received|recoveryRate|averageDelayDays|insurers|declarations)'"
```

- [ ] **Step 2 : Créer le composant**

`resources/js/components/aphaspb/DashboardKpis.vue` — reprendre **à
l'identique** le contenu actuel de `<KpiRow :columns="3" class="dashboard-kpis">`
du tableau de bord, y compris les `.dashboard-kpi-wrapper`, `.kpi-side-accent`
et `.kpi-icon`, en le paramétrant par `tone` :

```vue
<script setup lang="ts">
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import { formatMillions } from '@/lib/millions';

/**
 * Les trois chiffres d'ouverture, rendus à deux endroits.
 *
 * Source unique pour deux emplacements : sur grand écran la rangée vit après
 * les bandes, parce que le rouge doit frapper en premier ; sous 1024 px elle
 * vit dans le bandeau vert de l'en-tête, parce que trois cartes empilées y
 * repousseraient les bandes hors de l'écran. Chaque emplacement est masqué à
 * la largeur de l'autre — `display: none` retire l'élément de l'arbre
 * d'accessibilité, donc les chiffres ne sont jamais annoncés deux fois.
 */
defineProps<{
    summary: {
        invoiced: number;
        received: number;
        recoveryRate: number | null;
        averageDelayDays: number | null;
        insurers: number;
        declarations: number;
    };
    /** `band` inverse les couleurs pour le fond vert du bandeau. */
    tone: 'light' | 'band';
}>();
</script>
```

Le `<template>` reçoit, **coupé mot pour mot** du tableau de bord, le bloc
qui va de `<KpiRow :columns="3" class="dashboard-kpis">` à son `</KpiRow>`
fermant — les trois `.dashboard-kpi-wrapper` avec leurs `.kpi-side-accent` et
`.kpi-icon` compris. Ne pas le réécrire de mémoire : le retrouver par

```bash
grep -n 'KpiRow :columns="3"' resources/js/pages/pharmacy/Dashboard.vue
```

et couper jusqu'au `</KpiRow>` correspondant. Les styles de
`.dashboard-kpi-wrapper`, `.kpi-side-accent`, `.kpi-icon` et `.dashboard-kpis`
suivent dans le même mouvement.

- [ ] **Step 3 : Poser les deux emplacements dans le tableau de bord**

Dans l'en-tête :

```html
        <ConsoleHeader title="Parcours des paiements" class="dashboard-header">
            <template #hero>
                <DashboardKpis :summary="summary" tone="band" class="kpis-band" />
            </template>

            <template #action>
                <PrimaryAction
                    label="+ Nouvelle déclaration"
                    :href="declareUrl"
                />
            </template>
        </ConsoleHeader>
```

et, à la place de l'ancienne rangée :

```html
        <DashboardKpis :summary="summary" tone="light" class="kpis-page" />
```

- [ ] **Step 4 : Masquer chacun à la largeur de l'autre**

Dans le style du tableau de bord :

```css
/*
  Deux emplacements, jamais les deux visibles. `display: none` — et non
  `visibility` ou une position hors écran — pour que l'emplacement masqué
  quitte aussi l'arbre d'accessibilité : un lecteur d'écran ne doit pas
  énoncer les trois chiffres deux fois.
*/
@media (max-width: 1023px) {
    .kpis-page {
        display: none;
    }
}

@media (min-width: 1024px) {
    .kpis-band {
        display: none;
    }
}
```

- [ ] **Step 5 : Donner un ton à `KpiCard` plutôt que de viser ses utilitaires**

**`KpiCard.vue` n'a aucune classe sémantique** — uniquement des utilitaires
Tailwind (`rounded-[11px] border border-border bg-card px-4 py-[15px]`,
`text-[28px]/none font-extrabold`, `text-xs font-medium text-ink/50`). Un
`:deep(.bg-card)` fonctionnerait par accident et casserait au premier
changement d'utilitaire — c'est précisément le piège qui a mordu au lot 2, où
`:deep(.w-full)` attrapait aussi les liens de changement d'officine.

`KpiCard` gagne donc une prop explicite, avec un défaut qui laisse ses quatre
appelants actuels inchangés :

```ts
const props = withDefaults(
    defineProps<{
        label: string;
        value: string;
        unit?: string;
        hint?: string;
        /** `band` rend la carte lisible sur le bandeau vert de l'en-tête. */
        tone?: 'light' | 'band';
    }>(),
    { tone: 'light' },
);
```

et applique le ton sur les trois éléments concernés :

```html
    <div
        class="px-4 py-[15px]"
        :class="
            tone === 'band'
                ? 'rounded-[11px] bg-transparent'
                : 'rounded-[11px] border border-border bg-card'
        "
    >
```

le libellé en `tone === 'band' ? 'text-white/55' : 'text-ink/45'`, la valeur et
l'indice en `tone === 'band' ? 'text-white' : ''`.

Relever les classes exactes avant d'éditer, le fichier ayant pu bouger :

```bash
grep -n 'class=' resources/js/components/aphaspb/KpiCard.vue
```

`DashboardKpis` transmet simplement `:tone="tone"` à chacune de ses trois
cartes.

- [ ] **Step 6 : Construire et vérifier aux deux largeurs**

```bash
npm run build
npx vue-tsc --noEmit
shot officine hero-1440 'http://localhost:8000/' 1440
shot officine hero-390 'http://localhost:8000/' 390
shot officine hero-768 'http://localhost:8000/' 768
```

Attendu, à 1440 : en-tête clair, bandes rouges, **puis** les trois cartes
claires. À 390 et 768 : bandeau vert portant remarque, titre **et** les trois
chiffres en blanc, puis les bandes ; aucune carte claire plus bas.

- [ ] **Step 7 : Vérifier qu'aucun chiffre n'est annoncé deux fois**

```bash
chromium --headless --disable-gpu --user-data-dir=/tmp/apha-prof-officine \
  --virtual-time-budget=7000 --dump-dom 'http://localhost:8000/' 2>/dev/null \
  | grep -c 'FACTURÉ SUR 12 MOIS'
```

Attendu : **2**. Le DOM porte bien les deux emplacements ; c'est le CSS qui en
masque un, et c'est voulu. Si le chiffre est 1, un emplacement ne se rend pas.

- [ ] **Step 8 : Lancer la suite**

```bash
php artisan test --compact
npx vitest run
```

- [ ] **Step 9 : Commit**

```bash
git add resources/js/components/aphaspb/DashboardKpis.vue resources/js/pages/pharmacy/Dashboard.vue
git commit -m "feat: porter les chiffres clés dans le bandeau sous 1024 px

Deux emplacements pour une source : sur grand écran la rangée reste après
les bandes, parce que le rouge doit frapper en premier ; sous 1024 px elle
rejoint le bandeau vert, parce que trois cartes empilées y repousseraient
les bandes hors de l'écran. Chacun est masqué à la largeur de l'autre par
display: none, qui retire aussi l'élément de l'arbre d'accessibilité.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4 : Remplacer les 23 littéraux de couleur

**Files:**
- Modify: `resources/js/pages/pharmacy/Dashboard.vue`

**Interfaces:**
- Consumes: les tokens de `:root` posés au lot 1.
- Produces: un `Dashboard.vue` sans littéral de couleur autre que du blanc.

- [ ] **Step 1 : Relever l'inventaire à jour**

Les tâches 1 à 3 ont pu en ajouter ou en retirer.

```bash
grep -nE '#[0-9a-fA-F]{3,8}|rgba?\([0-9]' resources/js/pages/pharmacy/Dashboard.vue
```

- [ ] **Step 2 : Appliquer la table du haut de plan**

Chaque littéral y a son remplacement. La règle d'alpha :
`rgba(x, y, z, 0.07)` devient `color-mix(in srgb, var(--token) 7%, transparent)`.

`#fff`, `rgb(255 255 255 / …)` et `#000` restent autorisés : ce ne sont pas des
teintes de charte. Tout le reste passe par un token.

**Règle d'arrêt : un littéral qui ne correspond à aucun token de la table se
signale, il ne s'improvise pas** — inventer une correspondance recrée
exactement la dérive que le lot 1 a passé six commits à effacer.

- [ ] **Step 3 : Vérifier**

```bash
grep -nE '#[0-9a-fA-F]{3,8}|rgba?\([0-9]' resources/js/pages/pharmacy/Dashboard.vue \
  | grep -viE '#fff\b|#ffffff|#000\b|rgb\(255 255 255' || echo "aucun littéral de teinte"
```

Attendu : `aucun littéral de teinte`.

- [ ] **Step 4 : Construire et comparer à l'œil**

```bash
npm run build
shot officine litteraux 'http://localhost:8000/' 1440
```

Attendu : identique à `hero-1440.png` de la tâche 3, à la nuance près des
teintes converties du turquoise au vert.

- [ ] **Step 5 : Commit**

```bash
git add resources/js/pages/pharmacy/Dashboard.vue
git commit -m "style: remplacer les littéraux de couleur du tableau de bord

23 occurrences, dont six rgba turquoise résiduelles converties au vert. Les
alphas passent par color-mix sur le token plutôt que par un canal réécrit à
la main : changer --ink ne doit plus laisser d'ancienne teinte derrière.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5 : Interdire les littéraux dans les fichiers assainis

Recommandation de la revue du lot 1, restée ouverte. `PaletteSourceTest`
prouve qu'aucun `.vue` ne **redéclare** un token et que chaque `var()` se
résout, mais rien n'empêche un hexadécimal — c'est par là que la dérive est
revenue la première fois.

La garde ne peut pas viser tout le dépôt : 128 occurrences de turquoise en dur
subsistent dans les écrans du lot 4. Elle porte donc sur une **liste de
fichiers assainis, qui s'allonge à chaque lot**.

**Files:**
- Modify: `tests/Feature/Design/PaletteSourceTest.php`

**Interfaces:**
- Consumes: l'état laissé par la tâche 4.
- Produces: une constante `CLEANED` que le lot 4 étendra.

- [ ] **Step 1 : Écrire le test**

À la fin de `tests/Feature/Design/PaletteSourceTest.php` :

```php
test('a cleaned file writes no colour literal', function () {
    // La dérive est revenue par là : les tokens étaient propres, et 293
    // hexadécimaux vivaient dans les pages. Interdire le littéral est le seul
    // moyen de rendre le nettoyage durable.
    //
    // La liste s'allonge à chaque lot plutôt que de viser tout le dépôt : les
    // écrans du lot 4 portent encore 128 occurrences de turquoise, et une
    // garde qui rougit en permanence ne protège rien.
    $cleaned = [
        'css/app.css',
        'js/layouts/console/ConsoleHeader.vue',
        'js/layouts/console/ConsoleSidebar.vue',
        'js/layouts/console/ConsoleLayout.vue',
        'js/pages/pharmacy/Dashboard.vue',
        'js/components/aphaspb/DashboardKpis.vue',
    ];

    $offenders = [];

    foreach ($cleaned as $relative) {
        $body = File::get(resource_path($relative));

        // `app.css` a le droit de définir la palette : c'est son travail. On
        // n'y regarde donc que hors du bloc :root et de @theme.
        if ($relative === 'css/app.css') {
            $body = preg_replace('/(:root|@theme[^{]*)\{.*?\n\}/s', '', $body);
        }

        preg_match_all(
            '/#[0-9a-f]{3,8}\b|rgba?\(\s*\d/i',
            preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $body),
            $found,
        );

        foreach ($found[0] as $literal) {
            // Le blanc et le noir ne sont pas des teintes de charte.
            if (preg_match('/^#(fff|ffffff|000|000000)$/i', $literal) === 1) {
                continue;
            }

            $offenders[] = $relative.' → '.$literal;
        }
    }

    expect($offenders)->toBe([]);
});
```

- [ ] **Step 2 : Lancer**

```bash
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : vert. Si un fichier rougit, c'est que la tâche 4 a laissé un
littéral — le corriger, ne pas l'ajouter à une liste d'exceptions.

> `rgb(255 255 255 / 0.28)` n'est pas attrapé par `rgba?\(\s*\d` — le motif
> exige un chiffre juste après la parenthèse, et l'espace l'en sépare. C'est
> volontaire : la syntaxe moderne du blanc reste lisible et sans teinte.
> Si cette tolérance gêne un jour, resserrer le motif **et** convertir les
> blancs en `#fff` dans le même commit.

- [ ] **Step 3 : Vérifier que le filet mord**

```bash
cp resources/js/pages/pharmacy/Dashboard.vue /tmp/dash.bak
sed -i '0,/^\.bands {/s//.bands {\n    outline: 1px solid #d7a33d;/' resources/js/pages/pharmacy/Dashboard.vue
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : **ROUGE**, citant `js/pages/pharmacy/Dashboard.vue → #d7a33d`.

```bash
cp /tmp/dash.bak resources/js/pages/pharmacy/Dashboard.vue
vendor/bin/pest tests/Feature/Design/PaletteSourceTest.php --compact
```

Attendu : vert. **Copie de sauvegarde et non `git checkout`** — au lot 1, un
`git checkout` sur un fichier portant du travail non indexé a écrasé une
suppression qu'il a fallu refaire.

- [ ] **Step 4 : Lancer la suite complète**

```bash
php artisan test --compact
npx vitest run
npx vue-tsc --noEmit
npm run build
npx prettier --check resources/js resources/css
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --dirty --format agent
```

Attendu : 595 tests Pest (593 + 1 tâche 2 + 1 ici), 14 Vitest, 0 partout.

- [ ] **Step 5 : Commit**

```bash
git add tests/Feature/Design/PaletteSourceTest.php
git commit -m "test: interdire les littéraux de couleur dans les fichiers assainis

Recommandation restée ouverte de la revue du lot 1 : le test prouvait
qu'aucun .vue ne redéclare un token et que chaque var() se résout, mais rien
n'empêchait un hexadécimal — et c'est par là que 293 littéraux étaient
arrivés.

La liste des fichiers couverts s'allonge à chaque lot : viser tout le dépôt
ferait rougir en permanence sur les 128 occurrences que le lot 4 doit encore
traiter, et une garde toujours rouge ne protège rien.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 6 : Consigner la règle**

Avec l'outil Boost `record-rule` :

- glob : `resources/js/pages/pharmacy/Dashboard.vue`
- titre : `Le tableau de bord : rouge plein assumé, chiffres à deux emplacements`
- note : le rouge plein sur les bandes de retard est une **décision du
  client**, prise contre l'avis consigné dans la spec — ne pas la « corriger » ;
  le blanc sur `--terracotta` mesure 4,82:1, donc ne pas éclaircir cette teinte
  sans recalculer ; la bande « dans le délai » reste diluée, le plein étant
  réservé au signal « en faute » ; les chiffres clés sont rendus **deux fois**,
  masqués l'un et l'autre par `display: none` selon la largeur, parce que
  l'ordre approuvé diffère entre grand écran et mobile et que le slot `#hero`
  ne rend qu'à un endroit ; `PaletteSourceTest` interdit désormais tout
  littéral de couleur dans ce fichier.
