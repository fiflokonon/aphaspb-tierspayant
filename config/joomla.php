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
    | Page de connexion Joomla. Cette application n'en a aucune : la route
    | nommée "login" ne fait que rediriger ici.
    */
    'login_url' => env('JOOMLA_LOGIN_URL'),

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
