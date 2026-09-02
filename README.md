# APhaSPB — plateforme de déclaration des paiements d'assurance

Les officines du réseau béninois déclarent, mois par mois et assureur par
assureur, ce qu'elles ont facturé et ce qu'elles ont reçu. L'APhaSPB n'en lit
que des agrégats anonymisés : combien de jours chaque assureur met à payer le
réseau, et combien il lui doit.

**Aucun montant rattachable à une officine n'est consultable depuis l'espace
APhaSPB.** C'est la contrainte qui structure tout le code, pas une option
d'affichage.

- Cahier des charges : `docs/` (documents de travail confidentiels)
- Architecture de l'authentification : [`docs/architecture-auth-joomla.md`](docs/architecture-auth-joomla.md)
- Spécification d'implémentation : [`docs/superpowers/specs/`](docs/superpowers/specs/)
- Règles de projet à lire avant de coder : [`.ai/rules/index.md`](.ai/rules/index.md)

---

## Prérequis

| Outil | Version | Pourquoi cette version |
|---|---|---|
| PHP | **8.4** ou plus | L'arbre verrouillé l'exige (Symfony 8.1, Pest 5, Paratest). PHP 8.3 échoue à `composer install`. |
| Node | 22 ou plus | Version utilisée par la CI. |
| Composer | 2 | |
| SQLite | — | Base par défaut en développement, aucun serveur à installer. |

## Installation

```bash
git clone https://github.com/fiflokonon/aphaspb-tierspayant.git
cd aphaspb-tierspayant
composer setup
```

`composer setup` enchaîne : `composer install`, copie de `.env.example` vers
`.env`, génération de la clé, migrations, `npm install` et `npm run build`.

### Compléter le `.env`

`composer setup` copie `.env.example`, qui contient déjà les variables Joomla.
**Vérifie que les deux lignes de groupes sont renseignées** — sans elles, toutes
les autorisations refusent et l'application répond 403 partout, même connecté :

```dotenv
JOOMLA_ADMIN_GROUPS=8
JOOMLA_PHARMACY_GROUPS=10
```

Ce sont les identifiants réels de tes groupes Joomla, à relever dans
**Utilisateurs → Groupes** : `8` est Super Users sur une installation par
défaut, `10` n'est qu'un exemple pour le groupe des officines.

> **Ne jamais mettre `2` dans `JOOMLA_PHARMACY_GROUPS`.** Le claim `groups` du
> ticket est rempli par `getAuthorisedGroups()`, qui remonte toute la chaîne
> d'héritage : un compte du groupe « Officines » porte aussi Registered (2) et
> Public (1). Pointer `2` ouvre donc le tiers-payant à **tout compte inscrit sur
> le site Joomla**. Le groupe des officines doit être un groupe dédié, créé pour
> ça, et c'est l'appartenance à ce groupe qui vaut autorisation d'accès.

Les autres variables `JOOMLA_*` ne servent qu'au vrai flux d'authentification,
qui n'est pas nécessaire en développement (voir plus bas).

## Lancer en développement

```bash
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\DemoSeeder
composer run dev
```

`composer run dev` démarre le serveur PHP, Vite et le lecteur de journaux dans
une seule interface. L'application écoute sur <http://localhost:8000>.

## Se connecter sans Joomla

L'authentification est déléguée à un site Joomla qui joue le rôle de
fournisseur d'identité. Les extensions Joomla correspondantes vivent dans un
dépôt séparé, [`aphaspb-joomla-sso`](https://github.com/fiflokonon/aphaspb-joomla-sso),
qui porte aussi la procédure d'installation côté CMS et la recette manuelle.

Pour travailler sur les écrans sans monter tout le CMS, deux routes de
développement ouvrent une session directement :

| URL | Profil | Où elle mène |
|---|---|---|
| `/dev/login/officine` | Titulaire d'officine | Le tableau de bord de « Pharmacie Le Bon Secours » |
| `/dev/login/admin` | Administrateur APhaSPB | Les statistiques réseau |

> **Ces routes n'existent qu'en local.** Elles ne sont enregistrées que si
> `app()->isLocal()` : en production elles sont absentes du routeur, pas
> seulement gardées — invisibles dans `route:list`, inatteignables même par
> erreur de configuration. `tests/Feature/Dev/LocalLoginTest.php` vérifie cette
> absence.

Le vrai flux Joomla reste intact et testé : la route `login` pose un cookie
`state` et redirige vers `JOOMLA_LOGIN_URL`, puis `POST /auth/callback` échange
un ticket JWT RS256 à usage unique contre une session Laravel.

Un compte Joomla dont les groupes ne donnent accès ni à l'espace officine ni à
l'espace APhaSPB est refusé **au callback** : aucune session n'est ouverte,
aucun utilisateur miroir n'est créé, et la page `/auth/no-access` le lui
explique. C'est la gate `access-tierspayant` qui tranche, sur les groupes du
ticket signé.

## Ce qu'il y a à voir

### Espace officine

| Écran | URL | Ce qu'on y fait |
|---|---|---|
| Parcours des paiements sur 12 mois | `/{officine}/dashboard` | lire ses propres indicateurs |
| Déclaration mensuelle | `/pharmacy/declare` | saisir, assureur par assureur |
| Historique | `/pharmacy/history` | relire et corriger, filtré et paginé |
| Mes assureurs | `/pharmacy/insurers` | activer ou retirer un assureur |
| Onboarding — profil de l'officine | `/onboarding` | premier passage |
| Onboarding — choix des assureurs | `/onboarding/insurers` | premier passage |

### Espace APhaSPB

| Écran | URL | Ce qu'on y fait |
|---|---|---|
| Indicateurs par assureur | `/admin/network` | classement, délais, encours |
| Évolution des délais et encours | `/admin/trends` | douze mois de tendance |
| Pharmacies inscrites | `/admin/pharmacies` | annuaire, recherche, paginé |
| Gestion des assureurs | `/admin/insurers` | ajouter, renommer, désactiver |
| Exports CSV | `/admin/csv-exports` | agrégats réseau uniquement |

### Communs aux deux espaces

Ils ne figurent pas dans la barre de navigation — on y arrive par la cloche du
bandeau ou par le sélecteur d'officine — mais ce sont des écrans à part entière :

| Écran | URL | Ce qu'on y fait |
|---|---|---|
| Centre de notifications | `/notifications` | relire les alertes et les invitations reçues |
| Mes officines | `/settings/pharmacies` | changer d'officine, en créer une, quitter |
| Membres d'une officine | `/settings/pharmacies/{officine}` | inviter, retirer, changer un rôle |
| Profil | `/settings/profile` | l'entrée de menu a été retirée le 31/08/2026, la route vit toujours |

Aucun des onze écrans des deux espaces n'est une page d'attente : ils sont tous
livrés. Là où une donnée manque — un assureur sous le seuil des 5 officines déclarantes, un mois
sans déclaration — l'écran l'explique au lieu de rester vide, comme le prévoit
le canvas.

Les deux listes dont la longueur dépend des données sont paginées :
l'historique par 20 lignes, les pharmacies inscrites par 50. Les filtres
voyagent avec le numéro de page, et changer un filtre ramène à la première.

## Le jeu de données de démonstration

`DemoSeeder` crée une trentaine d'officines et douze mois de déclarations. Il
n'est pas aléatoire : il arrange délibérément des états qui font partie du
design et que des données tirées au hasard ne produiraient pas.

**Quatre assureurs franchissent le seuil des 5 officines déclarantes**, sinon
les écrans APhaSPB n'auraient aucun chiffre à montrer. **Deux restent en
dessous** — Atlantique Assurances à 3 officines, Ascoma Bénin à 1 — pour que
l'état « DONNÉES INSUFFISANTES » soit visible.

Les délais sont façonnés par assureur, pas tirés au sort, afin que la courbe de
`/admin/trends` raconte quelque chose :

| Assureur | Délai moyen | Sous 30 jours | Rejets |
|---|---|---|---|
| NSIA Assurances | ~28 j | ~57 % | ~4 % |
| SUNU Assurances | ~42 j | ~13 % | ~8 % |
| Sanlam Assurances | ~50 j | ~0 % | ~6 % |
| L'Africaine des Assurances | ~70 j, en dérive | 0 % | ~16 % |

La probabilité qu'une facture soit encore impayée **décroît avec son âge** : une
créance d'il y a un an a généralement été réglée ou relancée, celle de ce mois-ci
non. Sans cette décroissance, la tranche « > 90 j » avalerait tout l'encours —
elle couvre neuf mois contre un seul pour « 0–30 j », et c'est l'arithmétique,
non le réalisme, qui déciderait de la forme du graphique.

L'officine de démonstration **laisse deux assureurs à déclarer pour le mois
courant**. Sans cela, `/pharmacy/declare` ouvrirait sur l'écran de fin et
l'assistant de saisie — l'écran le plus important de l'application — resterait
invisible.

Pour repartir de zéro :

```bash
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\DemoSeeder
```

## Tester

### La commande qui compte

```bash
composer ci:check
```

C'est ce que lance la CI, et **la seule vérification complète** : Prettier,
ESLint, `vue-tsc`, Vitest, PHPStan, Pint sur tout le projet, puis la suite Pest.

Deux étapes échappent aux vérifications partielles et cassent régulièrement sans
que rien d'autre ne le signale :

- **`npm run format:check`** — un script de renommage ou une édition manuelle
  sur un `.vue` casse facilement le formatage, et ni ESLint ni `types:check` ne
  le voient.
- **`pint --parallel --test`** — il scanne tout le projet, alors que
  `pint --dirty` ne voit que les fichiers modifiés depuis git. En committant
  entre deux passes de `--dirty`, des fichiers restent durablement non formatés.

### Commandes ciblées

```bash
php artisan test --compact                              # toute la suite
php artisan test --compact tests/Feature/Pharmacy        # un dossier
vendor/bin/pest --filter="statut déduit"                 # un test
vendor/bin/phpstan analyse                               # analyse statique
vendor/bin/pint                                          # formatage PHP
npm run types:check && npm run lint:check                # front
```

### Ce que la suite garantit

400 tests, dont trois familles qui méritent d'être connues :

- **Authentification** — un JWT signé RS256 par une paire de clés de test ouvre
  une session ; un `aud` étranger, une signature invalide, un jeton expiré ou
  rejoué, un `state` absent ou faux donnent chacun un 401 générique ; un compte
  sans groupe autorisé repart sur `/auth/no-access` sans rien laisser derrière
  lui ; un `token_version` désynchronisé détruit la session.
- **Déduction du statut** — les quatre cas, plus la correction manuelle qui
  survit à un nouvel enregistrement. Les valeurs de l'énumération sont épinglées
  par un test parce que le SQL d'agrégation les écrit en littéral.
- **Confidentialité** — aucune route de l'espace APhaSPB ne renvoie un montant
  d'officine, une note privée ni une déclaration individuelle. Vérifié côté
  service *et* côté route.

## Pièges connus

Ces points ont chacun coûté du temps ; ils sont détaillés dans `.ai/rules/`.

**Ne jamais lancer `php artisan wayfinder:generate` seul.** `vite.config.ts`
active `formVariants: true`, option que la commande artisan ignore : elle écrase
la sortie du plugin Vite et casse les appels `.form()`. Régénérer les routes avec
**`npm run build`** ou `npm run dev`.

**Après avoir renommé un dossier de pages, reconstruire.** Les pages Inertia sont
résolues par le manifeste Vite ; sans `npm run build` les tests échouent en
`ViteException: Unable to locate file in Vite manifest`.

**`Http::fake()` garde le premier stub qui correspond.** Un stub de succès posé
dans un `beforeEach` masque tout stub d'échec qu'un test voudrait exercer. Le
poser dans chaque test, via une fonction d'aide locale.

**Nommer les routes en évitant les identifiants réservés de TypeScript.**
Wayfinder génère une constante par route : une route nommée `exports` produit
`export const exports`, que TypeScript refuse.

## Structure

```
app/
  Auth/JoomlaJwtGuard.php            guard « api », lecture du Bearer
  Data/                              objets de valeur (Period, InsurerIndicators…)
  Services/
    Joomla/                          décodage du JWT, ticket à usage unique, client /api/me
    Network/NetworkStatsService.php   SEUL chemin de lecture de l'espace APhaSPB
    Pharmacy/PharmacyStatsService.php métriques d'une officine, jamais du réseau
    Declarations/                    état du parcours de déclaration mensuel
  Support/ConsoleNavigation.php      navigation et rappels, construits côté serveur
resources/js/
  layouts/console/                   coquille partagée par les deux profils
  components/aphaspb/                composants métier et graphiques
  pages/{admin,pharmacy,onboarding}/ écrans
```

Les deux services de statistiques sont séparés à dessein : celui du réseau ne
doit jamais lire une officine nommément, celui de l'officine ne doit jamais
agréger le réseau. Deux classes rendent la frontière lisible, et chacune a son
propre test de confidentialité.

## Mise en production

L'application ne se déploie pas seule : la moitié authentification vit dans le
dépôt [`aphaspb-joomla-sso`](https://github.com/fiflokonon/aphaspb-joomla-sso),
et **il faut l'installer d'abord** — sans le plugin système, personne ne peut se
connecter, l'application n'ayant aucun formulaire de connexion propre.

Ordre des opérations :

1. **Côté Joomla** — dérouler le README de `aphaspb-joomla-sso` : paire de clés
   RSA hors webroot, installation des trois extensions, publication des deux
   plugins, secret machine-to-machine. Les archives installables sont les
   pièces jointes de la
   [release](https://github.com/fiflokonon/aphaspb-joomla-sso/releases/latest) —
   rien à compiler.
2. **Créer le groupe des officines** dans **Utilisateurs → Groupes**, relever son
   identifiant, et y placer les adhérents qui ont accès au tiers-payant. C'est ce
   groupe, et lui seul, qui ouvre l'application — voir l'avertissement sur
   `JOOMLA_PHARMACY_GROUPS` plus haut.
3. **Côté Laravel** — déployer le code, puis :

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

4. **Vérifier** avec le chapitre 9 du README des extensions, puis une connexion
   réelle de bout en bout.

### Les tâches de fond

Deux écrans ne suffisent pas : l'application envoie des courriels et planifie
des travaux. **Sans les trois points suivants, elle fonctionne en apparence et
n'envoie jamais rien** — aucune erreur, aucun journal, juste des messages qui ne
partent pas.

**Un worker de file d'attente.** Les trois notifications — invitation à
rejoindre une officine, récapitulatif des retards à l'officine, récapitulatif
réseau — implémentent `ShouldQueue` et sont donc mises en file, pas envoyées à
chaud. `QUEUE_CONNECTION=database` : la table existe, mais rien ne la dépile
sans worker.

```bash
php artisan queue:work --queue=default --tries=3 --max-time=3600
```

À placer sous supervision (systemd ou Supervisor) pour qu'il redémarre seul.

**Une entrée cron.** `routes/console.php` planifie la purge quotidienne des
invitations expirées et le récapitulatif hebdomadaire des retards, le lundi à
07:00 heure du Bénin.

```cron
* * * * * cd /chemin/vers/le/projet && php artisan schedule:run >> /dev/null 2>&1
```

**Un vrai mailer.** `.env.example` livre `MAIL_MAILER=log`, qui écrit les
courriels dans `storage/logs` au lieu de les envoyer. Renseigner un transport
réel et `MAIL_FROM_ADDRESS` avant la mise en service.

Pour vérifier la chaîne complète sans attendre lundi :

```bash
php artisan declarations:notify-overdue --dry-run   # ce qui partirait
php artisan schedule:list                           # les deux tâches et leur prochaine exécution
```

Deux contraintes non négociables :

- **HTTPS des deux côtés.** Le cookie `state` est `SameSite=None; Secure`, sans
  quoi le navigateur ne l'envoie pas sur le POST cross-site de retour. En clair
  sur du HTTP en production, **toute connexion échoue en 401**. Les navigateurs
  font une exception pour `http://localhost`, ce qui masque le problème en local.
- **`php artisan config:cache` après chaque changement de `.env`.** Sans ça, un
  `.env` modifié reste sans effet si la configuration est déjà en cache.

Enfin, mettre l'application en production bascule deux comportements :
`app()->isLocal()` étant faux, les routes `/dev/login/*` disparaissent du
routeur, et `DB::prohibitDestructiveCommands()` bloque `migrate:fresh`.

## Reste à faire

Hors du périmètre livré, dans l'ordre où l'APhaSPB en aura probablement besoin :

- le **lien d'accès depuis Joomla** — un élément de menu vers `/login` de
  l'application, restreint au groupe des officines, pour que l'adhérent
  autorisé ait un bouton et que les autres ne voient rien ;
- l'**incrément de `token_version` côté Joomla** sur changement de mot de passe
  (`onUserAfterSave`) : le mécanisme de révocation existe côté Laravel et est
  vérifié périodiquement, mais rien ne le déclenche encore ;
- le **rappel mensuel par email le 25** (CDC §3.6) ;
- la **validation des douze colonnes d'export** par l'APhaSPB avant que le
  premier fichier ne circule — le format est en place, son contenu exact n'est
  pas encore arbitré.
