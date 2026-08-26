# Architecture — Authentification Joomla → Laravel

## Vue d'ensemble

```
Joomla (IdP)                          Laravel (App Inertia)
├─ POST /api/auth/login          →    ├─ POST /auth/callback  (consomme le JWT)
├─ POST /api/auth/refresh             ├─ guard web (session)  ← pages Inertia
├─ GET  /api/me   (M2M)               ├─ guard api (JWT)      ← clients externes
└─ private.pem (hors webroot)         └─ public.pem
```

Joomla reste le seul détenteur des identifiants. Laravel maintient une *shadow
table* `users` pour ses propres clés étrangères métier.

## Alternatives écartées

| Option | Raison du rejet |
|---|---|
| Lecture directe de `#__users` | Contourne le MFA, les plugins d'auth et le flag `block`. Trois formats de hash à gérer selon l'ancienneté des comptes (bcrypt `$2y$`, phpass `$P$`, `md5:salt`). |
| Partage de la session Joomla | Ne fonctionne que sur le même domaine, ingérable dès qu'une SPA ou une API séparée entre en jeu. |
| Keycloak en frontal | Cible propre si d'autres services arrivent, mais coût d'entrée disproportionné pour un seul consommateur. À reconsidérer si un 3ᵉ service apparaît. |
| SPA Vue + API REST séparée | Double codebase, validation dupliquée, state management à construire. À reconsidérer uniquement si une app mobile consomme la même API. |
| Blade + composants Vue ponctuels | Rechargement complet à chaque navigation, pas la fluidité recherchée. |

## Génération des clés

```bash
openssl genpkey -algorithm RSA -out private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -pubout -in private.pem -out public.pem
```

## Côté Joomla — plugin webservices

Passer par la chaîne native, jamais par un `SELECT` direct :

```php
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Firebase\JWT\JWT;

$credentials = ['username' => $username, 'password' => $password];
$auth = Authentication::getInstance();
$response = $auth->authenticate($credentials, ['action' => 'core.login.site']);

if ($response->status !== Authentication::STATUS_SUCCESS) {
    // 401 générique
}

// Déclenche onUserAuthorisation → respecte block, activation, MFA
Factory::getApplication()->triggerEvent('onUserLogin', [(array) $response, []]);

$user = Factory::getUser($userId);

$payload = [
    'iss'    => 'https://tonsite.bj',
    'aud'    => 'laravel-api',
    'sub'    => (string) $user->id,
    'jti'    => bin2hex(random_bytes(16)),
    'iat'    => time(),
    'exp'    => time() + 900,
    'groups' => $user->getAuthorisedGroups(),
    'tv'     => (int) $user->getParam('token_version', 0),
];

$accessToken = JWT::encode($payload, $privateKey, 'RS256');
```

Refresh token : jeton opaque aléatoire, stocké hashé dans une table custom
`#__api_refresh_tokens` (`token_hash`, `user_id`, `expires_at`, `revoked_at`),
renvoyé en cookie `HttpOnly`.

Rate limiting obligatoire sur `/api/auth/login` — c'est le point d'entrée le
plus attaqué d'un site Joomla.

## Côté Laravel — shadow table

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('joomla_user_id')->unique();
    $table->string('name');
    $table->string('email')->unique();
    $table->json('joomla_groups')->nullable();
    $table->unsignedInteger('token_version')->default(0);
    $table->timestamps();
    // Pas de colonne password
});
```

## Côté Laravel — callback Inertia

```php
// POST /auth/callback
$claims = JWT::decode($token, new Key($publicKey, 'RS256'));

abort_if($claims->aud !== 'laravel-api', 401);

$user = User::firstOrCreate(
    ['joomla_user_id' => $claims->sub],
    [/* hydratation via /api/me en M2M */]
);

Auth::login($user, remember: false);
$request->session()->regenerate();

return redirect()->intended('/dashboard');
```

## Côté Laravel — guard JWT (clients externes)

```php
// app/Auth/JoomlaJwtGuard.php
public function user()
{
    if ($this->user !== null) return $this->user;

    $token = $this->request->bearerToken();
    if (!$token) return null;

    try {
        $claims = JWT::decode(
            $token,
            new Key(file_get_contents(config('joomla.public_key')), 'RS256')
        );
    } catch (\Throwable $e) {
        return null;
    }

    if ($claims->aud !== 'laravel-api') return null;

    $user = User::firstOrNew(['joomla_user_id' => $claims->sub]);
    $user->joomla_groups = $claims->groups;
    if ($user->isDirty() || !$user->exists) $user->save();

    return $this->user = $user;
}
```

Enregistrement :

```php
Auth::extend('joomla-jwt', fn($app, $name, $config) =>
    new JoomlaJwtGuard($app['request'])
);
```

## Autorisation

```php
Gate::define('manage-content', fn(User $u) =>
    !empty(array_intersect($u->joomla_groups, [6, 7, 8]))
);
```

## Hydratation du profil au premier login

Les claims ne transportent ni `name` ni `email` (données perso). Au premier
`firstOrCreate`, Laravel appelle `GET /api/me` sur Joomla en machine-to-machine
(secret partagé côté serveur) pour récupérer le profil.

Ne **pas** faire transiter l'objet `user` par le client : falsifiable.

## Révocation

Un JWT ne s'annule pas. Deux mécanismes :

1. `token_version` sur l'utilisateur, incrémenté au changement de mot de passe
   ou au logout global. Revérifié périodiquement par un middleware Laravel.
2. Webhook Joomla → Laravel sur `onUserAfterSave` / `onUserAfterDelete` pour
   invalider immédiatement la session sur les actions sensibles.

Fenêtre d'exposition : 15 min par défaut, à ramener à 5 min si l'app manipule
des données sensibles.

## Reste à trancher

- Le site Joomla utilise-t-il le MFA ? (détermine si le flux deux étapes est requis)
- Y a-t-il des comptes migrés depuis Joomla 3 avec des hash legacy ?
- Laravel et Joomla sur le même VPS ou séparés ?
