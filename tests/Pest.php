<?php

use App\Models\Declaration;
use App\Models\Insurer;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The Inertia props of a response, as searchable JSON with accents intact.
 *
 * Asserting confidentiality against the raw HTML is unsound: Inertia
 * json_encodes the props, which escapes every non-ASCII character, so
 * `not->toContain('note privée')` never matches and the assertion passes
 * whatever leaked. JSON_UNESCAPED_UNICODE restores the accents so the search
 * means what it reads.
 */
function inertiaPropsJson(TestResponse $response): string
{
    /** @var array<string, mixed> $page */
    $page = $response->viewData('page');

    return json_encode($page['props'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

/**
 * L'exemple de la spec : 1 000 000 F déposés le 31/03, clause 60 j / 2 %,
 * 400 000 F le 15/06 puis 600 000 F le 20/07 → 32 000 F de pénalité.
 *
 * Le nom d'assureur est unique en base : un test qui crée deux mois de
 * référence passe un second nom.
 *
 * @param  list<array{amount: int, paid_on: string}>|null  $instalments
 */
function referenceMonth(User $user, ?array $instalments = null, string $insurerName = 'NSIA'): Declaration
{
    return Declaration::factory()
        ->instalments($instalments ?? referenceInstalments())
        ->create([
            'pharmacy_id' => $user->currentPharmacy->id,
            'insurer_id' => Insurer::factory()->withPenalty(triggerDays: 60, ratePercent: 2.0)->create(['name' => $insurerName])->id,
            'period_year' => 2026,
            'period_month' => 3,
            'amount_invoiced' => 1_000_000,
            'invoice_deposited_on' => '2026-03-31',
        ])->fresh(['insurer', 'payments']);
}

/**
 * Les deux versements qui soldent le mois de référence.
 *
 * @return list<array{amount: int, paid_on: string}>
 */
function referenceInstalments(): array
{
    return [
        ['amount' => 400_000, 'paid_on' => '2026-06-15'],
        ['amount' => 600_000, 'paid_on' => '2026-07-20'],
    ];
}

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

    File::ensureDirectoryExists(dirname($path));
    File::put($path, joomlaTestKeys()['public']);

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
 * The JSON:API envelope Joomla's profile endpoint answers with.
 *
 * Kept in one place because the shape belongs to Joomla, not to us: the API
 * application wraps every resource in data.attributes.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function joomlaProfilePayload(array $attributes = []): array
{
    $attributes = array_merge([
        'id' => 5150,
        'name' => 'Pharmacie Le Bon Secours',
        'email' => 'titulaire@officine.bj',
        'verified' => true,
        'token_version' => 0,
        'blocked' => false,
    ], $attributes);

    return [
        'data' => [
            'type' => 'me',
            'id' => (string) $attributes['id'],
            'attributes' => $attributes,
        ],
    ];
}

/**
 * Forge a JWT the way the Joomla plugin will. Claims passed in override defaults.
 *
 * @param  array<string, mixed>  $claims
 */
function joomlaToken(array $claims = [], ?string $privateKey = null): string
{
    return JWT::encode(
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
