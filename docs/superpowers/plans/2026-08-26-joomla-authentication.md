# Authentification Joomla — plan d'implémentation (incrément 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer l'authentification Fortify du starter kit par le flux Joomla — Laravel ne détient plus aucun mot de passe et n'accepte l'identité que d'un JWT RS256 signé par Joomla.

**Architecture:** Joomla est fournisseur d'identité. Un ticket JWT à usage unique est consommé par `POST /auth/callback`, qui ouvre une session Laravel classique (guard `web`, celui des pages Inertia). Un second guard `api` lit un Bearer token pour les clients externes. Un middleware revérifie périodiquement le `token_version` auprès de Joomla pour détruire les sessions des comptes bloqués. Le décodage du JWT vit dans un seul service consommé par les trois chemins.

**Tech Stack:** Laravel 13.29, PHP 8.4, Inertia 3.3, Pest 5, `firebase/php-jwt` v7.

**Spec:** `docs/superpowers/specs/2026-08-26-aphaspb-design-implementation-design.md` (incrément 1, §13)

## Global Constraints

- **Laravel ne stocke aucun mot de passe.** La table `users` n'a pas de colonne `password`. Aucune tâche ne doit en réintroduire une.
- **Ne jamais requêter les tables Joomla** (`#__users`, etc.). L'unique surface de contact est le JWT signé et les endpoints API Joomla.
- **RS256 uniquement.** Laravel ne détient que la clé publique. Ne pas basculer sur HS256.
- **Aucun JWT en `localStorage`.** Access token en mémoire, refresh token en cookie `HttpOnly` / `Secure` / `SameSite=Strict`.
- **Claims minimalistes** : `iss`, `aud`, `sub`, `jti`, `iat`, `exp`, `groups`, `tv`.
- **Réponses d'erreur au login : toujours un 401 générique.** Ne jamais distinguer « utilisateur inconnu » de « jeton invalide » ou « jeton expiré ».
- **Guard `web` (session) pour toutes les routes Inertia**, guard `api` (Bearer) pour les clients externes uniquement. Ne pas les confondre.
- **Aucun identifiant de groupe Joomla en dur** hors de `config/joomla.php`. Passer par une Gate nommée.
- **La revérification du `token_version` est obligatoire**, elle ne peut être retirée au nom de la performance.
- Nommage du code, des routes et des colonnes **en anglais** ; le français reste dans les libellés d'interface.
- Après toute modification PHP : `vendor/bin/pint --dirty --format agent`.
- Le renommage `Team` → `Pharmacy` appartient à l'incrément 2. Ce plan conserve `Team`, `current_team_id` et le préfixe de route `{current_team}` tels quels.

## Écarts assumés par rapport à `docs/architecture-auth-joomla.md`

Deux raffinements, à valider par le mainteneur avant la tâche 5 :

1. **Le guard `api` ne crée pas d'utilisateur.** Le doc d'architecture utilise `firstOrNew` sur les claims seuls, mais les claims ne portent ni `name` ni `email` (données personnelles, cf. §4.5 de la spec) alors que ces colonnes sont `NOT NULL`. Le guard résout donc uniquement des utilisateurs existants ; `/auth/callback` est le seul chemin qui crée une ligne, parce que c'est le seul qui hydrate le profil via `/api/me`.
2. **Le guard `api` vérifie aussi le `token_version`.** Le doc ne le contrôle que dans le middleware de session. Le vérifier dans le guard ferme la même fenêtre pour les clients externes, sans coût : la valeur est déjà en base.

---

### Task 1: Mettre le dépôt sous contrôle de version

Le dossier n'est pas un dépôt git — `git rev-parse` échoue. Sans cela, aucune des tâches suivantes ne peut committer, et la tâche 4 supprime une trentaine de fichiers sans filet.

**Files:**
- Verify: `.gitignore` (déjà présent)

**Interfaces:**
- Consumes: rien
- Produces: un dépôt git avec un commit initial, prérequis de l'étape « Commit » de toutes les tâches suivantes

- [ ] **Step 1: Vérifier que le dépôt n'existe pas déjà**

Run: `git rev-parse --is-inside-work-tree`
Expected: `fatal: not a git repository` (ou son équivalent localisé). Si la commande réussit, cette tâche est déjà faite — passer à la tâche 2.

- [ ] **Step 2: Vérifier que `.gitignore` exclut bien vendor et node_modules**

Run: `grep -E '^/?(vendor|node_modules|\.env)$' .gitignore`
Expected: les trois lignes apparaissent. Si `.env` manque, l'ajouter avant de committer — il contient des secrets.

- [ ] **Step 3: Initialiser le dépôt et committer l'état actuel**

```bash
git init
git add .
git commit -m "chore: import du starter kit Laravel avant bascule Joomla"
```

- [ ] **Step 4: Vérifier que rien de sensible n'est suivi**

Run: `git ls-files | grep -E '^(\.env$|vendor/|node_modules/)'`
Expected: aucune sortie.

- [ ] **Step 5: Créer la branche de travail**

```bash
git checkout -b feat/joomla-authentication
```

---

### Task 2: Dépendance JWT, configuration Joomla et outillage de test

**Files:**
- Create: `config/joomla.php`
- Modify: `composer.json` (via composer require)
- Modify: `.env.example`
- Modify: `tests/Pest.php`
- Test: `tests/Feature/Auth/JoomlaTestKeysTest.php`

**Interfaces:**
- Consumes: rien
- Produces:
  - `config('joomla.issuer')`, `config('joomla.audience')`, `config('joomla.public_key_path')`, `config('joomla.api_url')`, `config('joomla.m2m_secret')`, `config('joomla.token_version_recheck_seconds')`, `config('joomla.groups.admin')` (`list<int>`), `config('joomla.groups.pharmacy')` (`list<int>`)
  - `useJoomlaTestKeys(): void` — écrit la clé publique de test là où la configuration la cherche et fixe `joomla.issuer`, `joomla.audience`, `joomla.groups`
  - `joomlaTestKeys(): array{private: string, public: string}`
  - `joomlaToken(array $claims = [], ?string $privateKey = null): string` — forge un JWT RS256 ; `$claims` écrase les valeurs par défaut

- [ ] **Step 1: Installer la dépendance**

```bash
composer require firebase/php-jwt:^7.1
```

- [ ] **Step 2: Créer le fichier de configuration**

Create `config/joomla.php`:

```php
<?php

return [

    /*
    | Émetteur attendu dans le claim "iss". Tout autre émetteur est refusé.
    */
    'issuer' => env('JOOMLA_ISSUER'),

    /*
    | Audience attendue dans le claim "aud". Tout autre public est refusé.
    */
    'audience' => env('JOOMLA_AUDIENCE', 'laravel-api'),

    /*
    | Chemin de la clé publique RSA. La clé privée reste chez Joomla, hors webroot.
    */
    'public_key_path' => env('JOOMLA_PUBLIC_KEY_PATH', storage_path('keys/joomla-public.pem')),

    /*
    | Base de l'API Joomla et secret machine-to-machine pour GET /api/me.
    */
    'api_url' => env('JOOMLA_API_URL'),
    'm2m_secret' => env('JOOMLA_M2M_SECRET'),

    /*
    | Intervalle minimum entre deux revérifications du token_version, en secondes.
    */
    'token_version_recheck_seconds' => (int) env('JOOMLA_TOKEN_VERSION_RECHECK', 900),

    /*
    | Groupes Joomla mappés vers les Gates. Seul endroit où un identifiant de
    | groupe Joomla apparaît dans l'application.
    */
    'groups' => [
        'admin' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('JOOMLA_ADMIN_GROUPS', '')),
        ))),
        'pharmacy' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('JOOMLA_PHARMACY_GROUPS', '')),
        ))),
    ],

];
```

- [ ] **Step 3: Documenter les variables dans `.env.example`**

Ajouter à la fin de `.env.example` :

```dotenv
JOOMLA_ISSUER=https://exemple.bj
JOOMLA_AUDIENCE=laravel-api
JOOMLA_PUBLIC_KEY_PATH=
JOOMLA_API_URL=https://exemple.bj/api
JOOMLA_M2M_SECRET=
JOOMLA_TOKEN_VERSION_RECHECK=900
JOOMLA_ADMIN_GROUPS=8
JOOMLA_PHARMACY_GROUPS=2
```

- [ ] **Step 4: Écrire le test qui échoue**

Create `tests/Feature/Auth/JoomlaTestKeysTest.php`:

```php
<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

test('the test helper forges a JWT that verifies against the configured public key', function () {
    useJoomlaTestKeys();

    $token = joomlaToken(['sub' => '4242']);

    $claims = (array) JWT::decode(
        $token,
        new Key(file_get_contents(config('joomla.public_key_path')), 'RS256'),
    );

    expect($claims['sub'])->toBe('4242')
        ->and($claims['aud'])->toBe('laravel-api')
        ->and($claims['iss'])->toBe('https://joomla.test');
});

test('a token forged with a foreign key does not verify', function () {
    useJoomlaTestKeys();

    $foreign = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($foreign, $foreignPrivateKey);

    $token = joomlaToken([], $foreignPrivateKey);

    expect(fn () => JWT::decode(
        $token,
        new Key(file_get_contents(config('joomla.public_key_path')), 'RS256'),
    ))->toThrow(Firebase\JWT\SignatureInvalidException::class);
});
```

- [ ] **Step 5: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaTestKeysTest.php`
Expected: FAIL — `Call to undefined function useJoomlaTestKeys()`

- [ ] **Step 6: Ajouter les fonctions d'aide dans `tests/Pest.php`**

Remplacer la fonction `something()` de fin de fichier par :

```php
/**
 * Generate — once per process — an RSA keypair standing in for Joomla's.
 *
 * @return array{private: string, public: string}
 */
function joomlaTestKeys(): array
{
    static $keys = null;

    if ($keys === null) {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);

        $keys = [
            'private' => $privateKey,
            'public' => openssl_pkey_get_details($resource)['key'],
        ];
    }

    return $keys;
}

/**
 * Point the Joomla configuration at the test keypair and fixed group ids.
 */
function useJoomlaTestKeys(): void
{
    $path = storage_path('framework/testing/joomla-public.pem');

    Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
    Illuminate\Support\Facades\File::put($path, joomlaTestKeys()['public']);

    config([
        'joomla.issuer' => 'https://joomla.test',
        'joomla.audience' => 'laravel-api',
        'joomla.public_key_path' => $path,
        'joomla.api_url' => 'https://joomla.test/api',
        'joomla.m2m_secret' => 'test-secret',
        'joomla.groups.admin' => [8],
        'joomla.groups.pharmacy' => [2],
    ]);
}

/**
 * Forge a JWT the way the Joomla plugin will. Claims passed in override defaults.
 *
 * @param  array<string, mixed>  $claims
 */
function joomlaToken(array $claims = [], ?string $privateKey = null): string
{
    return Firebase\JWT\JWT::encode(
        array_merge([
            'iss' => config('joomla.issuer'),
            'aud' => config('joomla.audience'),
            'sub' => '1001',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + 900,
            'groups' => [2],
            'tv' => 0,
        ], $claims),
        $privateKey ?? joomlaTestKeys()['private'],
        'RS256',
    );
}
```

- [ ] **Step 7: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaTestKeysTest.php`
Expected: PASS, 2 tests

- [ ] **Step 8: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/joomla.php .env.example tests/Pest.php tests/Feature/Auth/JoomlaTestKeysTest.php
git commit -m "feat: configuration Joomla et outillage de test JWT RS256"
```

---

### Task 3: Décodeur de jeton Joomla

Un seul point de vérité pour « ce JWT est-il authentique et destiné à cette application ? ». Le guard, le ticket de callback et les tests le partagent.

**Files:**
- Create: `app/Data/JoomlaClaims.php`
- Create: `app/Services/Joomla/JoomlaTokenDecoder.php`
- Test: `tests/Unit/Joomla/JoomlaTokenDecoderTest.php`

**Interfaces:**
- Consumes: `config('joomla.*')` et les aides de test de la tâche 2
- Produces:
  - `App\Data\JoomlaClaims` — propriétés publiques `int $joomlaUserId`, `list<int> $groups`, `int $tokenVersion`, `string $jti`, `int $expiresAt`
  - `App\Services\Joomla\JoomlaTokenDecoder::decode(string $token): ?JoomlaClaims` — `null` sur tout échec, sans distinction de motif

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Unit/Joomla/JoomlaTokenDecoderTest.php`:

```php
<?php

use App\Services\Joomla\JoomlaTokenDecoder;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->decoder = new JoomlaTokenDecoder;
});

test('it decodes a well formed token into claims', function () {
    $claims = $this->decoder->decode(joomlaToken([
        'sub' => '77',
        'groups' => [2, 6],
        'tv' => 3,
        'jti' => 'ticket-abc',
    ]));

    expect($claims)->not->toBeNull()
        ->and($claims->joomlaUserId)->toBe(77)
        ->and($claims->groups)->toBe([2, 6])
        ->and($claims->tokenVersion)->toBe(3)
        ->and($claims->jti)->toBe('ticket-abc');
});

test('it refuses a token signed by a foreign key', function () {
    $foreign = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($foreign, $foreignPrivateKey);

    expect($this->decoder->decode(joomlaToken([], $foreignPrivateKey)))->toBeNull();
});

test('it refuses a token minted for another audience', function () {
    expect($this->decoder->decode(joomlaToken(['aud' => 'someone-else'])))->toBeNull();
});

test('it refuses a token from another issuer', function () {
    expect($this->decoder->decode(joomlaToken(['iss' => 'https://attacker.test'])))->toBeNull();
});

test('it refuses an expired token', function () {
    expect($this->decoder->decode(joomlaToken([
        'iat' => time() - 3600,
        'exp' => time() - 60,
    ])))->toBeNull();
});

test('it refuses a token missing the claims the application relies on', function () {
    $incomplete = Firebase\JWT\JWT::encode([
        'iss' => config('joomla.issuer'),
        'aud' => config('joomla.audience'),
        'exp' => time() + 900,
    ], joomlaTestKeys()['private'], 'RS256');

    expect($this->decoder->decode($incomplete))->toBeNull();
});

test('it refuses garbage', function () {
    expect($this->decoder->decode('not-a-token'))->toBeNull()
        ->and($this->decoder->decode(''))->toBeNull();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Unit/Joomla/JoomlaTokenDecoderTest.php`
Expected: FAIL — `Class "App\Services\Joomla\JoomlaTokenDecoder" not found`

- [ ] **Step 3: Créer le DTO de claims**

Create `app/Data/JoomlaClaims.php`:

```php
<?php

namespace App\Data;

readonly class JoomlaClaims
{
    /**
     * @param  list<int>  $groups
     */
    public function __construct(
        public int $joomlaUserId,
        public array $groups,
        public int $tokenVersion,
        public string $jti,
        public int $expiresAt,
    ) {
        //
    }
}
```

- [ ] **Step 4: Écrire le décodeur**

Create `app/Services/Joomla/JoomlaTokenDecoder.php`:

```php
<?php

namespace App\Services\Joomla;

use App\Data\JoomlaClaims;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class JoomlaTokenDecoder
{
    /**
     * Verify a Joomla-issued JWT and return its claims.
     *
     * Returns null on every failure — bad signature, foreign audience or
     * issuer, expiry, malformed payload — so no caller can leak which one
     * occurred. RS256 only: the private key never leaves Joomla.
     */
    public function decode(string $token): ?JoomlaClaims
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = (array) JWT::decode($token, new Key($this->publicKey(), 'RS256'));
        } catch (Throwable) {
            return null;
        }

        if (($payload['aud'] ?? null) !== config('joomla.audience')) {
            return null;
        }

        if (($payload['iss'] ?? null) !== config('joomla.issuer')) {
            return null;
        }

        foreach (['sub', 'jti', 'exp', 'tv'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return null;
            }
        }

        return new JoomlaClaims(
            joomlaUserId: (int) $payload['sub'],
            groups: array_values(array_map('intval', (array) ($payload['groups'] ?? []))),
            tokenVersion: (int) $payload['tv'],
            jti: (string) $payload['jti'],
            expiresAt: (int) $payload['exp'],
        );
    }

    protected function publicKey(): string
    {
        $path = (string) config('joomla.public_key_path');

        if (! is_readable($path)) {
            throw new JoomlaConfigurationException(
                "La clé publique Joomla est introuvable ou illisible : {$path}",
            );
        }

        return (string) file_get_contents($path);
    }
}
```

- [ ] **Step 5: Créer l'exception de configuration**

Create `app/Services/Joomla/JoomlaConfigurationException.php`:

```php
<?php

namespace App\Services\Joomla;

use RuntimeException;

class JoomlaConfigurationException extends RuntimeException
{
    //
}
```

- [ ] **Step 6: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Unit/Joomla/JoomlaTokenDecoderTest.php`
Expected: PASS, 7 tests

Note : `tests/Pest.php` n'applique `TestCase` et `RefreshDatabase` qu'à `Feature`. Les aides `useJoomlaTestKeys()` et `joomlaToken()` utilisent le helper `config()`, qui exige une application démarrée. Si ces tests échouent avec « A facade root has not been set », étendre `TestCase` sur `Unit` aussi dans `tests/Pest.php` :

```php
pest()->extend(Tests\TestCase::class)->in('Unit');
```

- [ ] **Step 7: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/JoomlaClaims.php app/Services/Joomla tests/Unit/Joomla tests/Pest.php
git commit -m "feat: décodeur de JWT Joomla avec échec uniforme"
```

---

### Task 4: Retirer Fortify et les mots de passe

Tâche destructrice : elle supprime une trentaine de fichiers. Le commit de la tâche 1 est le filet.

La suppression de la confirmation par mot de passe emporte la suppression de soi-même : un compte appartient à Joomla, et supprimer sa ligne miroir depuis Laravel désynchroniserait les deux systèmes. La route `profile.destroy`, son *request*, sa méthode et son composant partent donc aussi.

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`
- Delete: `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`
- Delete: `database/migrations/2024_01_01_000000_create_passkeys_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `bootstrap/providers.php`
- Modify: `routes/settings.php`
- Modify: `app/Http/Controllers/Settings/ProfileController.php`
- Modify: `tests/Feature/Settings/ProfileUpdateTest.php`
- Delete: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/` (2 fichiers), `app/Http/Responses/` (5 fichiers + le dossier `Concerns/`), `app/Concerns/PasswordValidationRules.php`, `app/Http/Controllers/Settings/SecurityController.php`, `app/Http/Requests/Settings/PasswordUpdateRequest.php`, `app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php`, `app/Http/Requests/Settings/ProfileDeleteRequest.php`
- Delete (Vue): `resources/js/pages/auth/` (7 fichiers), `resources/js/pages/settings/Security.vue`, `resources/js/layouts/AuthLayout.vue`, `resources/js/layouts/auth/` (3 fichiers), `resources/js/components/ManagePasskeys.vue`, `ManageTwoFactor.vue`, `PasskeyItem.vue`, `PasskeyRegister.vue`, `PasskeyVerify.vue`, `PasswordInput.vue`, `TwoFactorRecoveryCodes.vue`, `TwoFactorSetupModal.vue`, `DeleteUser.vue`, `resources/js/composables/useTwoFactorAuth.ts`
- Delete (tests, approuvé en spec §3): `tests/Feature/Auth/AuthenticationTest.php`, `EmailVerificationTest.php`, `PasswordConfirmationTest.php`, `PasswordResetTest.php`, `RegistrationTest.php`, `TwoFactorChallengeTest.php`, `VerificationNotificationTest.php`, `tests/Feature/Settings/SecurityTest.php`
- Test: `tests/Feature/Auth/UsersSchemaTest.php`

**Interfaces:**
- Consumes: rien
- Produces:
  - Table `users` : `id`, `joomla_user_id` (unique), `name`, `email` (unique), `email_verified_at`, `joomla_groups` (json), `token_version`, `current_team_id` (via la migration existante), timestamps
  - `User::$joomla_groups` casté en `array`, `User::$token_version` en `int`
  - `User::hasAnyJoomlaGroup(array $groups): bool`
  - `UserFactory::networkAdmin(): static` — état plaçant l'utilisateur dans le groupe admin configuré

- [ ] **Step 1: Écrire le test de schéma qui échoue**

Create `tests/Feature/Auth/UsersSchemaTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

test('the users table carries the Joomla identity', function () {
    expect(Schema::hasColumns('users', [
        'joomla_user_id',
        'joomla_groups',
        'token_version',
    ]))->toBeTrue();
});

test('the users table holds no credential of its own', function () {
    expect(Schema::hasColumn('users', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'remember_token'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse()
        ->and(Schema::hasTable('passkeys'))->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/UsersSchemaTest.php`
Expected: FAIL — les deux tests échouent, `joomla_user_id` absent et `password` présent

- [ ] **Step 3: Réécrire la migration des utilisateurs**

L'application n'est pas déployée : on modifie la migration d'origine plutôt que d'en empiler une de correction.

Replace the `up()` method of `database/migrations/0001_01_01_000000_create_users_table.php`:

```php
public function up(): void
{
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('joomla_user_id')->unique();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->json('joomla_groups')->nullable();
        $table->unsignedInteger('token_version')->default(0);
        $table->timestamps();
    });

    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
}
```

And its `down()`:

```php
public function down(): void
{
    Schema::dropIfExists('users');
    Schema::dropIfExists('sessions');
}
```

- [ ] **Step 4: Supprimer les migrations de mot de passe et de passkeys**

```bash
git rm database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php \
       database/migrations/2024_01_01_000000_create_passkeys_table.php
```

- [ ] **Step 5: Réécrire le modèle User**

Replace `app/Models/User.php` entirely:

```php
<?php

namespace App\Models;

use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $joomla_user_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property list<int>|null $joomla_groups
 * @property int $token_version
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 */
#[Fillable(['joomla_user_id', 'name', 'email', 'joomla_groups', 'token_version', 'current_team_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable;

    /**
     * Joomla owns the credentials, so this application has no remember token.
     *
     * @var string
     */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'joomla_groups' => 'array',
            'token_version' => 'integer',
        ];
    }

    /**
     * Laravel never validates a password for this model: authentication is
     * delegated to Joomla and the users table holds no password column.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * Determine whether the user belongs to any of the given Joomla groups.
     *
     * @param  list<int>  $groups
     */
    public function hasAnyJoomlaGroup(array $groups): bool
    {
        return array_intersect($this->joomla_groups ?? [], $groups) !== [];
    }
}
```

- [ ] **Step 6: Réécrire la factory**

Replace the `definition()`, drop `withTwoFactor()`, add `networkAdmin()` in `database/factories/UserFactory.php`. The `configure()` hook and `unverified()` stay as they are, and the `$password` property and the `Hash` / `Str` imports go:

```php
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'joomla_user_id' => fake()->unique()->numberBetween(1_000, 999_999),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'joomla_groups' => config('joomla.groups.pharmacy') ?: [2],
            'token_version' => 0,
        ];
    }

    /**
     * Indicate that the user belongs to the APhaSPB admin group.
     */
    public function networkAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'joomla_groups' => config('joomla.groups.admin') ?: [8],
        ]);
    }
```

- [ ] **Step 7: Retirer Fortify du bootstrap et de la configuration**

Replace `bootstrap/providers.php`:

```php
<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
];
```

```bash
git rm config/fortify.php app/Providers/FortifyServiceProvider.php
git rm -r app/Actions/Fortify app/Http/Responses
git rm app/Concerns/PasswordValidationRules.php \
       app/Http/Controllers/Settings/SecurityController.php \
       app/Http/Requests/Settings/PasswordUpdateRequest.php \
       app/Http/Requests/Settings/TwoFactorAuthenticationRequest.php \
       app/Http/Requests/Settings/ProfileDeleteRequest.php
```

- [ ] **Step 8: Retirer la suppression de compte du contrôleur de profil**

Dans `app/Http/Controllers/Settings/ProfileController.php`, supprimer la méthode `destroy()` et les imports devenus inutiles `ProfileDeleteRequest` et `Illuminate\Support\Facades\Auth`.

- [ ] **Step 9: Nettoyer les routes**

Replace `routes/settings.php`:

```php
<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    });
});
```

- [ ] **Step 10: Supprimer les composants Vue et les tests des flux disparus**

```bash
git rm -r resources/js/pages/auth resources/js/layouts/auth
git rm resources/js/pages/settings/Security.vue \
       resources/js/layouts/AuthLayout.vue \
       resources/js/components/ManagePasskeys.vue \
       resources/js/components/ManageTwoFactor.vue \
       resources/js/components/PasskeyItem.vue \
       resources/js/components/PasskeyRegister.vue \
       resources/js/components/PasskeyVerify.vue \
       resources/js/components/PasswordInput.vue \
       resources/js/components/TwoFactorRecoveryCodes.vue \
       resources/js/components/TwoFactorSetupModal.vue \
       resources/js/components/DeleteUser.vue \
       resources/js/composables/useTwoFactorAuth.ts
git rm tests/Feature/Auth/AuthenticationTest.php \
       tests/Feature/Auth/EmailVerificationTest.php \
       tests/Feature/Auth/PasswordConfirmationTest.php \
       tests/Feature/Auth/PasswordResetTest.php \
       tests/Feature/Auth/RegistrationTest.php \
       tests/Feature/Auth/TwoFactorChallengeTest.php \
       tests/Feature/Auth/VerificationNotificationTest.php \
       tests/Feature/Settings/SecurityTest.php
npm uninstall @laravel/passkeys vue-input-otp
composer remove laravel/fortify
```

- [ ] **Step 11: Retirer les deux tests de suppression de compte par mot de passe**

Dans `tests/Feature/Settings/ProfileUpdateTest.php`, supprimer le test qui poste `password => 'password'` sur `profile.destroy` et le test `'correct password must be provided to delete account'`. Ces deux flux n'existent plus.

- [ ] **Step 12: Purger les références Vue mortes**

Ouvrir `resources/js/pages/settings/Profile.vue` et `resources/js/layouts/settings/Layout.vue`, retirer tout import de `DeleteUser`, `PasswordInput` ou de l'entrée de menu « Security ». Puis régénérer les fonctions de route :

Run: `php artisan wayfinder:generate`
Expected: les dossiers `resources/js/actions/Laravel/Fortify`, `Laravel/Passkeys`, `routes/two-factor`, `routes/password`, `routes/register`, `routes/login`, `routes/verification`, `routes/user-password`, `routes/well-known` et `actions/.../SecurityController.ts` disparaissent.

- [ ] **Step 13: Lancer le test de schéma**

Run: `vendor/bin/pest tests/Feature/Auth/UsersSchemaTest.php`
Expected: PASS, 2 tests

- [ ] **Step 14: Lancer la suite entière et la vérification de types**

Run: `php artisan test --compact`
Expected: PASS. Les tests survivants sont `UsersSchemaTest`, `JoomlaTestKeysTest`, `JoomlaTokenDecoderTest`, `DashboardTest`, `ExampleTest` ×2, `ProfileUpdateTest` (allégé) et les 4 fichiers de `tests/Feature/Teams/`.

Run: `npm run types:check`
Expected: aucune erreur. Toute erreur restante pointe un import Vue mort oublié à l'étape 12.

- [ ] **Step 15: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat!: retirer Fortify, les mots de passe et les passkeys au profit de l'identité Joomla"
```

---

### Task 5: Guard `api` lisant le Bearer token

**Files:**
- Create: `app/Auth/JoomlaJwtGuard.php`
- Modify: `config/auth.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Auth/JoomlaJwtGuardTest.php`

**Interfaces:**
- Consumes: `JoomlaTokenDecoder::decode()`, `App\Data\JoomlaClaims`, `UserFactory`
- Produces: guard nommé `api`, résolvable par `Auth::guard('api')` et par le middleware `auth:api`

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Auth/JoomlaJwtGuardTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    useJoomlaTestKeys();

    Route::middleware('auth:api')->get('/test-api-user', fn () => response()->json([
        'id' => auth('api')->id(),
    ]));
});

test('a valid bearer token resolves the matching user', function () {
    $user = User::factory()->create(['joomla_user_id' => 5150, 'token_version' => 2]);

    $this->withToken(joomlaToken(['sub' => '5150', 'tv' => 2]))
        ->getJson('/test-api-user')
        ->assertOk()
        ->assertJson(['id' => $user->id]);
});

test('a request without a bearer token is rejected', function () {
    $this->getJson('/test-api-user')->assertUnauthorized();
});

test('a token whose subject has no local user is rejected', function () {
    $this->withToken(joomlaToken(['sub' => '999999']))
        ->getJson('/test-api-user')
        ->assertUnauthorized();
});

test('a token whose version trails the stored one is rejected', function () {
    User::factory()->create(['joomla_user_id' => 5150, 'token_version' => 4]);

    $this->withToken(joomlaToken(['sub' => '5150', 'tv' => 3]))
        ->getJson('/test-api-user')
        ->assertUnauthorized();
});

test('the guard refreshes the stored groups when the claim diverges', function () {
    $user = User::factory()->create([
        'joomla_user_id' => 5150,
        'joomla_groups' => [2],
    ]);

    $this->withToken(joomlaToken(['sub' => '5150', 'groups' => [2, 6]]))
        ->getJson('/test-api-user')
        ->assertOk();

    expect($user->fresh()->joomla_groups)->toBe([2, 6]);
});

test('the guard ignores the session and reads only the bearer token', function () {
    $user = User::factory()->create(['joomla_user_id' => 5150]);

    $this->actingAs($user)
        ->getJson('/test-api-user')
        ->assertUnauthorized();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaJwtGuardTest.php`
Expected: FAIL — `Auth guard [api] is not defined.`

- [ ] **Step 3: Écrire le guard**

Create `app/Auth/JoomlaJwtGuard.php`:

```php
<?php

namespace App\Auth;

use App\Data\JoomlaClaims;
use App\Models\User;
use App\Services\Joomla\JoomlaTokenDecoder;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Stateless guard for external API clients.
 *
 * Reserved for external consumers: Inertia pages are stateful and go through
 * the session-backed "web" guard. This guard never creates a user — only
 * /auth/callback does, because only it hydrates the profile from Joomla.
 */
class JoomlaJwtGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        protected Request $request,
        protected JoomlaTokenDecoder $decoder,
    ) {
        //
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request->bearerToken();

        if ($token === null) {
            return null;
        }

        $claims = $this->decoder->decode($token);

        if ($claims === null) {
            return null;
        }

        return $this->user = $this->resolve($claims);
    }

    /**
     * This guard authenticates by signed token only, never by credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    protected function resolve(JoomlaClaims $claims): ?User
    {
        $user = User::query()->firstWhere('joomla_user_id', $claims->joomlaUserId);

        if ($user === null || $claims->tokenVersion !== $user->token_version) {
            return null;
        }

        if ($user->joomla_groups !== $claims->groups) {
            $user->forceFill(['joomla_groups' => $claims->groups])->save();
        }

        return $user;
    }
}
```

- [ ] **Step 4: Déclarer le guard dans la configuration**

Dans `config/auth.php`, remplacer le tableau `guards` par :

```php
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'joomla-jwt',
        ],
    ],
```

- [ ] **Step 5: Enregistrer le driver**

Dans `app/Providers/AppServiceProvider.php`, ajouter l'appel dans `boot()` et la méthode qui l'implémente, plus les imports `App\Auth\JoomlaJwtGuard`, `App\Services\Joomla\JoomlaTokenDecoder` et `Illuminate\Support\Facades\Auth` :

```php
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureJoomlaGuard();
    }

    /**
     * Register the stateless guard used by external API clients.
     */
    protected function configureJoomlaGuard(): void
    {
        Auth::extend('joomla-jwt', fn ($app) => new JoomlaJwtGuard(
            $app['request'],
            $app->make(JoomlaTokenDecoder::class),
        ));
    }
```

- [ ] **Step 6: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaJwtGuardTest.php`
Expected: PASS, 6 tests

- [ ] **Step 7: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Auth config/auth.php app/Providers/AppServiceProvider.php tests/Feature/Auth/JoomlaJwtGuardTest.php
git commit -m "feat: guard api authentifiant les clients externes par JWT Joomla"
```

---

### Task 6: Client machine-to-machine `/api/me`

Les claims ne transportent ni `name` ni `email` : ce sont des données personnelles, et le payload d'un JWT est lisible par quiconque. Le profil est donc récupéré côté serveur.

**Files:**
- Create: `app/Services/Joomla/JoomlaApiClient.php`
- Create: `app/Data/JoomlaProfile.php`
- Test: `tests/Feature/Auth/JoomlaApiClientTest.php`

**Interfaces:**
- Consumes: `config('joomla.api_url')`, `config('joomla.m2m_secret')`
- Produces:
  - `App\Data\JoomlaProfile` — `int $joomlaUserId`, `string $name`, `string $email`, `bool $isVerified`, `int $tokenVersion`
  - `JoomlaApiClient::profile(int $joomlaUserId): ?JoomlaProfile` — `null` si Joomla répond autre chose qu'un 200 exploitable

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Auth/JoomlaApiClientTest.php`:

```php
<?php

use App\Services\Joomla\JoomlaApiClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->client = app(JoomlaApiClient::class);
});

test('it fetches a profile and sends the shared secret', function () {
    Http::fake([
        'joomla.test/api/me*' => Http::response([
            'id' => 5150,
            'name' => 'Pharmacie Le Bon Secours',
            'email' => 'titulaire@officine.bj',
            'verified' => true,
            'token_version' => 3,
        ]),
    ]);

    $profile = $this->client->profile(5150);

    expect($profile)->not->toBeNull()
        ->and($profile->joomlaUserId)->toBe(5150)
        ->and($profile->name)->toBe('Pharmacie Le Bon Secours')
        ->and($profile->email)->toBe('titulaire@officine.bj')
        ->and($profile->isVerified)->toBeTrue()
        ->and($profile->tokenVersion)->toBe(3);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Joomla-Secret', 'test-secret'));
});

test('it returns null when Joomla refuses the call', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(status: 403)]);

    expect($this->client->profile(5150))->toBeNull();
});

test('it returns null when Joomla is unreachable', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

    expect($this->client->profile(5150))->toBeNull();
});

test('it returns null when the payload lacks the fields the application needs', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(['id' => 5150])]);

    expect($this->client->profile(5150))->toBeNull();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaApiClientTest.php`
Expected: FAIL — `Class "App\Services\Joomla\JoomlaApiClient" not found`

- [ ] **Step 3: Créer le DTO de profil**

Create `app/Data/JoomlaProfile.php`:

```php
<?php

namespace App\Data;

readonly class JoomlaProfile
{
    public function __construct(
        public int $joomlaUserId,
        public string $name,
        public string $email,
        public bool $isVerified,
        public int $tokenVersion,
    ) {
        //
    }
}
```

- [ ] **Step 4: Écrire le client**

Create `app/Services/Joomla/JoomlaApiClient.php`:

```php
<?php

namespace App\Services\Joomla;

use App\Data\JoomlaProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Server-to-server access to Joomla's read-only user endpoint.
 *
 * The shared secret never reaches the browser, and the profile is never
 * carried by the client: a user object coming from the client is forgeable.
 */
class JoomlaApiClient
{
    public function profile(int $joomlaUserId): ?JoomlaProfile
    {
        try {
            $response = Http::withHeaders([
                'X-Joomla-Secret' => (string) config('joomla.m2m_secret'),
                'Accept' => 'application/json',
            ])
                ->timeout(5)
                ->retry(2, 200)
                ->get(rtrim((string) config('joomla.api_url'), '/').'/me', [
                    'id' => $joomlaUserId,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        foreach (['id', 'name', 'email'] as $required) {
            if (! array_key_exists($required, $payload)) {
                return null;
            }
        }

        return new JoomlaProfile(
            joomlaUserId: (int) $payload['id'],
            name: (string) $payload['name'],
            email: (string) $payload['email'],
            isVerified: (bool) ($payload['verified'] ?? false),
            tokenVersion: (int) ($payload['token_version'] ?? 0),
        );
    }
}
```

- [ ] **Step 5: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaApiClientTest.php`
Expected: PASS, 4 tests

- [ ] **Step 6: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/JoomlaProfile.php app/Services/Joomla/JoomlaApiClient.php tests/Feature/Auth/JoomlaApiClientTest.php
git commit -m "feat: client machine-to-machine pour le profil Joomla"
```

---

### Task 7: Ticket d'entrée à usage unique

Le JWT remis au navigateur est un ticket : il ouvre une session puis ne doit plus jamais servir. Rejouable, il serait un identifiant permanent volable dans un historique de navigation ou un log de proxy.

**Files:**
- Create: `app/Services/Joomla/JoomlaTicket.php`
- Test: `tests/Feature/Auth/JoomlaTicketTest.php`

**Interfaces:**
- Consumes: `JoomlaTokenDecoder::decode()`, `App\Data\JoomlaClaims`, le cache applicatif
- Produces: `JoomlaTicket::consume(string $token): ?JoomlaClaims` — retourne les claims au premier appel, `null` à tout appel suivant avec le même `jti`

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Auth/JoomlaTicketTest.php`:

```php
<?php

use App\Services\Joomla\JoomlaTicket;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->ticket = app(JoomlaTicket::class);
});

test('a ticket is accepted once', function () {
    $claims = $this->ticket->consume(joomlaToken(['sub' => '5150']));

    expect($claims)->not->toBeNull()
        ->and($claims->joomlaUserId)->toBe(5150);
});

test('replaying the same ticket is refused', function () {
    $token = joomlaToken(['jti' => 'ticket-once']);

    expect($this->ticket->consume($token))->not->toBeNull()
        ->and($this->ticket->consume($token))->toBeNull();
});

test('two distinct tickets for the same user are both accepted', function () {
    expect($this->ticket->consume(joomlaToken(['jti' => 'ticket-a'])))->not->toBeNull()
        ->and($this->ticket->consume(joomlaToken(['jti' => 'ticket-b'])))->not->toBeNull();
});

test('an invalid token is refused without being remembered', function () {
    expect($this->ticket->consume('not-a-token'))->toBeNull();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaTicketTest.php`
Expected: FAIL — `Class "App\Services\Joomla\JoomlaTicket" not found`

- [ ] **Step 3: Écrire le service**

Create `app/Services/Joomla/JoomlaTicket.php`:

```php
<?php

namespace App\Services\Joomla;

use App\Data\JoomlaClaims;
use Illuminate\Support\Facades\Cache;

/**
 * Single-use wrapper around a Joomla-issued JWT.
 *
 * The jti is remembered until the token would have expired anyway, which is
 * the shortest window that still blocks a replay.
 */
class JoomlaTicket
{
    public function __construct(protected JoomlaTokenDecoder $decoder)
    {
        //
    }

    public function consume(string $token): ?JoomlaClaims
    {
        $claims = $this->decoder->decode($token);

        if ($claims === null) {
            return null;
        }

        $ttl = $claims->expiresAt - time();

        if ($ttl <= 0) {
            return null;
        }

        $firstUse = Cache::add($this->key($claims->jti), true, $ttl);

        return $firstUse ? $claims : null;
    }

    protected function key(string $jti): string
    {
        return 'joomla:ticket:'.hash('sha256', $jti);
    }
}
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaTicketTest.php`
Expected: PASS, 4 tests

- [ ] **Step 5: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Joomla/JoomlaTicket.php tests/Feature/Auth/JoomlaTicketTest.php
git commit -m "feat: consommation à usage unique du ticket JWT Joomla"
```

---

### Task 8: Callback d'ouverture de session et déconnexion

**Files:**
- Create: `app/Http/Controllers/Auth/JoomlaCallbackController.php`
- Create: `app/Http/Controllers/Auth/LogoutController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auth/JoomlaCallbackTest.php`

**Interfaces:**
- Consumes: `JoomlaTicket::consume()`, `JoomlaApiClient::profile()`, `User`, `UserFactory`
- Produces:
  - Route `POST /auth/callback`, nommée `auth.callback`, hors middleware `auth`
  - Route `POST /auth/logout`, nommée `auth.logout`
  - Clé de session `joomla.token_version_checked_at` (timestamp Unix), lue par la tâche 9

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Auth/JoomlaCallbackTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    useJoomlaTestKeys();

    Http::fake([
        'joomla.test/api/me*' => Http::response([
            'id' => 5150,
            'name' => 'Pharmacie Le Bon Secours',
            'email' => 'titulaire@officine.bj',
            'verified' => true,
            'token_version' => 0,
        ]),
    ]);
});

test('a valid ticket creates the shadow user and opens a session', function () {
    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])])
        ->assertRedirect();

    $user = User::query()->firstWhere('joomla_user_id', 5150);

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Pharmacie Le Bon Secours')
        ->and($user->email)->toBe('titulaire@officine.bj')
        ->and($user->joomla_groups)->toBe([2]);

    $this->assertAuthenticatedAs($user);
});

test('the profile is fetched from Joomla, never taken from the request', function () {
    $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150']),
        'name' => 'Attaquant',
        'email' => 'attaquant@example.test',
    ])->assertRedirect();

    expect(User::query()->firstWhere('joomla_user_id', 5150)->email)
        ->toBe('titulaire@officine.bj');
});

test('an existing user is reused and their groups refreshed', function () {
    $user = User::factory()->create([
        'joomla_user_id' => 5150,
        'joomla_groups' => [2],
    ]);

    $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150', 'groups' => [2, 6]]),
    ])->assertRedirect();

    expect(User::query()->where('joomla_user_id', 5150)->count())->toBe(1)
        ->and($user->fresh()->joomla_groups)->toBe([2, 6]);
});

test('the session id is regenerated to defeat fixation', function () {
    $this->get('/');
    $before = session()->getId();

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])]);

    expect(session()->getId())->not->toBe($before);
});

test('the callback records when the token version was last checked', function () {
    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])]);

    expect(session('joomla.token_version_checked_at'))->toBeInt();
});

test('a replayed ticket is refused', function () {
    $token = joomlaToken(['sub' => '5150']);

    $this->post(route('auth.callback'), ['token' => $token])->assertRedirect();
    $this->post(route('auth.logout'));

    $this->post(route('auth.callback'), ['token' => $token])->assertStatus(401);
});

test('a token for another audience is refused with a bare 401', function () {
    $response = $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150', 'aud' => 'someone-else']),
    ]);

    $response->assertStatus(401);
    expect($response->getContent())->not->toContain('audience');
    $this->assertGuest();
});

test('an expired token is refused', function () {
    $this->post(route('auth.callback'), [
        'token' => joomlaToken(['sub' => '5150', 'iat' => time() - 3600, 'exp' => time() - 60]),
    ])->assertStatus(401);

    $this->assertGuest();
});

test('a missing token is refused', function () {
    $this->post(route('auth.callback'))->assertStatus(401);

    $this->assertGuest();
});

test('a callback is refused when Joomla will not hand over the profile', function () {
    Http::fake(['joomla.test/api/me*' => Http::response(status: 403)]);

    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])])
        ->assertStatus(401);

    expect(User::query()->where('joomla_user_id', 5150)->exists())->toBeFalse();
    $this->assertGuest();
});

test('logout destroys the session', function () {
    $this->post(route('auth.callback'), ['token' => joomlaToken(['sub' => '5150'])]);
    $this->assertAuthenticated();

    $this->post(route('auth.logout'))->assertRedirect('/');

    $this->assertGuest();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaCallbackTest.php`
Expected: FAIL — `Route [auth.callback] not defined.`

- [ ] **Step 3: Écrire le contrôleur de callback**

Create `app/Http/Controllers/Auth/JoomlaCallbackController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Data\JoomlaClaims;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Joomla\JoomlaApiClient;
use App\Services\Joomla\JoomlaTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Trade a single-use Joomla ticket for a Laravel session.
 *
 * Inertia is stateful and does not work with an Authorization header, so every
 * page of this application rides the session-backed "web" guard. The JWT is
 * spent here and never again.
 */
class JoomlaCallbackController extends Controller
{
    public function __construct(
        protected JoomlaTicket $ticket,
        protected JoomlaApiClient $joomla,
    ) {
        //
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $claims = $this->ticket->consume((string) $request->input('token', ''));

        abort_if($claims === null, 401);

        $user = $this->synchronise($claims);

        abort_if($user === null, 401);

        Auth::login($user, remember: false);

        $request->session()->regenerate();
        $request->session()->put('joomla.token_version_checked_at', now()->timestamp);

        return redirect()->intended($this->landingFor($user));
    }

    /**
     * Mirror the Joomla account locally, hydrating the profile server-side.
     */
    protected function synchronise(JoomlaClaims $claims): ?User
    {
        $profile = $this->joomla->profile($claims->joomlaUserId);

        if ($profile === null) {
            return null;
        }

        $user = User::query()->firstOrNew(['joomla_user_id' => $claims->joomlaUserId]);

        $user->forceFill([
            'name' => $profile->name,
            'email' => $profile->email,
            'email_verified_at' => $profile->isVerified ? ($user->email_verified_at ?? now()) : null,
            'joomla_groups' => $claims->groups,
            'token_version' => $claims->tokenVersion,
        ])->save();

        return $user;
    }

    /**
     * Where a freshly authenticated user lands.
     */
    protected function landingFor(User $user): string
    {
        if ($user->hasAnyJoomlaGroup(config('joomla.groups.admin'))) {
            return '/';
        }

        return $user->currentTeam
            ? route('dashboard', ['current_team' => $user->currentTeam->slug])
            : '/';
    }
}
```

Note : `landingFor()` renvoie `/` pour l'admin parce que `/admin/network` n'existe qu'à l'incrément 3. Le point d'extension est isolé dans cette seule méthode.

- [ ] **Step 4: Écrire le contrôleur de déconnexion**

Create `app/Http/Controllers/Auth/LogoutController.php`:

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
```

- [ ] **Step 5: Déclarer les routes**

Dans `routes/web.php`, ajouter les imports `App\Http\Controllers\Auth\JoomlaCallbackController` et `App\Http\Controllers\Auth\LogoutController`, puis juste après la route `home` :

```php
Route::post('auth/callback', JoomlaCallbackController::class)->name('auth.callback');
Route::post('auth/logout', LogoutController::class)->name('auth.logout');
```

- [ ] **Step 6: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaCallbackTest.php`
Expected: PASS, 11 tests

- [ ] **Step 7: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
git add app/Http/Controllers/Auth routes/web.php resources/js/actions resources/js/routes tests/Feature/Auth/JoomlaCallbackTest.php
git commit -m "feat: ouverture de session Laravel depuis un ticket Joomla"
```

---

### Task 9: Revérification du `token_version`

Un utilisateur bloqué dans Joomla conserverait sinon une session Laravel valide jusqu'à son expiration. Ce contrôle est obligatoire ; la spec interdit de le retirer pour gagner en performance.

**Files:**
- Create: `app/Http/Middleware/VerifyJoomlaTokenVersion.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Auth/TokenVersionTest.php`

**Interfaces:**
- Consumes: `JoomlaApiClient::profile()`, la clé de session `joomla.token_version_checked_at` posée par la tâche 8, `config('joomla.token_version_recheck_seconds')`
- Produces: middleware appliqué au groupe `web`, qui déconnecte et redirige vers `/` sur écart de version

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Auth/TokenVersionTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    useJoomlaTestKeys();
    $this->user = User::factory()->create(['joomla_user_id' => 5150, 'token_version' => 1]);
});

function fakeJoomlaTokenVersion(int $version): void
{
    Http::fake([
        'joomla.test/api/me*' => Http::response([
            'id' => 5150,
            'name' => 'Pharmacie Le Bon Secours',
            'email' => 'titulaire@officine.bj',
            'verified' => true,
            'token_version' => $version,
        ]),
    ]);
}

test('a session survives when Joomla reports the same token version', function () {
    fakeJoomlaTokenVersion(1);

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->subHour()->timestamp])
        ->get(route('dashboard', ['current_team' => $this->user->currentTeam->slug]))
        ->assertOk();

    $this->assertAuthenticated();
});

test('a bumped token version destroys the session', function () {
    fakeJoomlaTokenVersion(7);

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->subHour()->timestamp])
        ->get(route('dashboard', ['current_team' => $this->user->currentTeam->slug]))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('the check is skipped inside the recheck window', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->timestamp])
        ->get(route('dashboard', ['current_team' => $this->user->currentTeam->slug]))
        ->assertOk();

    Http::assertNothingSent();
});

test('an unreachable Joomla leaves the session alone', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->subHour()->timestamp])
        ->get(route('dashboard', ['current_team' => $this->user->currentTeam->slug]))
        ->assertOk();

    $this->assertAuthenticated();
});

test('a guest is not checked at all', function () {
    Http::fake();

    $this->get('/')->assertOk();

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/TokenVersionTest.php`
Expected: FAIL — le test « a bumped token version destroys the session » renvoie 200 au lieu d'une redirection

- [ ] **Step 3: Écrire le middleware**

Create `app/Http/Middleware/VerifyJoomlaTokenVersion.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Services\Joomla\JoomlaApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-read the Joomla token version so a blocked account loses its session.
 *
 * A JWT cannot be revoked, so the session is the thing revoked instead. The
 * check is throttled per session rather than skipped: dropping it would leave
 * blocked accounts authenticated for the whole session lifetime.
 *
 * An unreachable Joomla leaves the session in place: a CMS outage must not log
 * the whole network out.
 */
class VerifyJoomlaTokenVersion
{
    protected const CHECKED_AT = 'joomla.token_version_checked_at';

    public function __construct(protected JoomlaApiClient $joomla)
    {
        //
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->isDue($request)) {
            return $next($request);
        }

        $profile = $this->joomla->profile($user->joomla_user_id);

        if ($profile === null) {
            return $next($request);
        }

        if ($profile->tokenVersion !== $user->token_version) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        $request->session()->put(self::CHECKED_AT, now()->timestamp);

        return $next($request);
    }

    protected function isDue(Request $request): bool
    {
        $checkedAt = (int) $request->session()->get(self::CHECKED_AT, 0);

        return now()->timestamp - $checkedAt
            >= (int) config('joomla.token_version_recheck_seconds');
    }
}
```

- [ ] **Step 4: Brancher le middleware sur le groupe web**

Dans `bootstrap/app.php`, ajouter l'import `App\Http\Middleware\VerifyJoomlaTokenVersion` et l'inscrire en dernier de la pile `web`, après `SetTeamUrlDefaults` :

```php
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            VerifyJoomlaTokenVersion::class,
        ]);
```

- [ ] **Step 5: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/TokenVersionTest.php`
Expected: PASS, 5 tests

- [ ] **Step 6: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/VerifyJoomlaTokenVersion.php bootstrap/app.php tests/Feature/Auth/TokenVersionTest.php
git commit -m "feat: revérification du token_version Joomla sur les sessions actives"
```

---

### Task 10: Gates d'autorisation dérivées des groupes Joomla

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Auth/JoomlaGatesTest.php`

**Interfaces:**
- Consumes: `User::hasAnyJoomlaGroup()`, `config('joomla.groups.*')`, `UserFactory::networkAdmin()`
- Produces: Gates `manage-network`, `manage-insurers`, `declare-payments`

- [ ] **Step 1: Écrire le test qui échoue**

Create `tests/Feature/Auth/JoomlaGatesTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => useJoomlaTestKeys());

test('a network admin may read the aggregated network and manage insurers', function () {
    $admin = User::factory()->networkAdmin()->create();

    expect(Gate::forUser($admin)->allows('manage-network'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manage-insurers'))->toBeTrue();
});

test('a network admin may not declare payments', function () {
    $admin = User::factory()->networkAdmin()->create();

    expect(Gate::forUser($admin)->allows('declare-payments'))->toBeFalse();
});

test('a pharmacy may declare payments', function () {
    $pharmacy = User::factory()->create();

    expect(Gate::forUser($pharmacy)->allows('declare-payments'))->toBeTrue();
});

test('a pharmacy may not read the network or manage insurers', function () {
    $pharmacy = User::factory()->create();

    expect(Gate::forUser($pharmacy)->allows('manage-network'))->toBeFalse()
        ->and(Gate::forUser($pharmacy)->allows('manage-insurers'))->toBeFalse();
});

test('a user in no configured group is granted nothing', function () {
    $stranger = User::factory()->create(['joomla_groups' => [999]]);

    expect(Gate::forUser($stranger)->allows('manage-network'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('manage-insurers'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('declare-payments'))->toBeFalse();
});

test('a user with no groups at all is granted nothing', function () {
    $stranger = User::factory()->create(['joomla_groups' => null]);

    expect(Gate::forUser($stranger)->allows('manage-network'))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('declare-payments'))->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaGatesTest.php`
Expected: FAIL — les Gates ne sont pas définies, donc `allows()` renvoie `false` partout et les tests attendant `true` échouent

- [ ] **Step 3: Déclarer les Gates**

Dans `app/Providers/AppServiceProvider.php`, ajouter l'appel dans `boot()`, la méthode ci-dessous, et les imports `App\Models\User` et `Illuminate\Support\Facades\Gate` :

```php
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureJoomlaGuard();
        $this->configureJoomlaGates();
    }

    /**
     * Map Joomla groups onto named abilities.
     *
     * Group ids live in config/joomla.php and nowhere else — a controller or a
     * view must never test one directly.
     */
    protected function configureJoomlaGates(): void
    {
        Gate::define('manage-network', fn (User $user): bool => $user->hasAnyJoomlaGroup(
            config('joomla.groups.admin'),
        ));

        Gate::define('manage-insurers', fn (User $user): bool => $user->hasAnyJoomlaGroup(
            config('joomla.groups.admin'),
        ));

        Gate::define('declare-payments', fn (User $user): bool => $user->hasAnyJoomlaGroup(
            config('joomla.groups.pharmacy'),
        ));
    }
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `vendor/bin/pest tests/Feature/Auth/JoomlaGatesTest.php`
Expected: PASS, 6 tests

- [ ] **Step 5: Lancer la suite entière**

Run: `php artisan test --compact`
Expected: PASS sur l'ensemble

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: aucune erreur. `larastan` est installé et `phpstan.neon` présent.

Run: `npm run types:check && npm run lint:check`
Expected: aucune erreur.

- [ ] **Step 6: Formater et committer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Providers/AppServiceProvider.php tests/Feature/Auth/JoomlaGatesTest.php
git commit -m "feat: Gates d'autorisation dérivées des groupes Joomla"
```

---

## Auto-revue du plan

**Couverture de la spec, incrément 1 (§13) :**

| Exigence de la spec | Tâche |
|---|---|
| Retrait de Fortify | 4 |
| Table `users` sans mot de passe | 4 |
| `JoomlaJwtGuard` | 5 |
| `/auth/callback` | 8 |
| `VerifyJoomlaTokenVersion` | 9 |
| `JoomlaApiClient` | 6 |
| `config/joomla.php` | 2 |
| Gates | 10 |
| Nouvelle suite de tests d'auth | 2, 3, 5, 6, 7, 8, 9, 10 |
| 401 génériques (§4.4) | 3 (échec uniforme), 8 (assertion explicite) |
| Ticket à usage unique (§4.2) | 7 |
| Hydratation par `/api/me`, jamais par le client (§4.5) | 6, 8 |

Deux éléments de la spec §4 ne sont **pas** couverts ici, à dessein : l'onboarding métier de l'officine dépend de `pharmacies.onpb_license` / `city` / `owner_name`, qui appartiennent à l'incrément 2 ; le refresh token en cookie `HttpOnly` est émis et renouvelé par Joomla, hors de ce dépôt.

**Cohérence des types :** `JoomlaClaims` est produit par la tâche 3 et consommé tel quel par 5, 7 et 8. `JoomlaProfile` est produit par la tâche 6 et consommé par 8 et 9. La clé de session `joomla.token_version_checked_at` est écrite par la tâche 8 et lue par la tâche 9 sous la constante `VerifyJoomlaTokenVersion::CHECKED_AT`, même littéral. `hasAnyJoomlaGroup()` est défini en tâche 4 et consommé en 8 et 10. `networkAdmin()` est défini en tâche 4 et consommé en 10.

**Risque connu :** la tâche 4 supprime des composants Vue dont `pages/settings/Profile.vue` et `layouts/settings/Layout.vue` importent peut-être encore certains. L'étape 12 de cette tâche et le `npm run types:check` de l'étape 14 sont là pour l'attraper — ne pas committer avant qu'il passe.
