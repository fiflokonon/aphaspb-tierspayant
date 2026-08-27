<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
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
        ->get(route('dashboard', ['current_pharmacy' => $this->user->currentPharmacy->slug]))
        ->assertOk();

    $this->assertAuthenticated();
});

test('a bumped token version destroys the session', function () {
    fakeJoomlaTokenVersion(7);

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->subHour()->timestamp])
        ->get(route('dashboard', ['current_pharmacy' => $this->user->currentPharmacy->slug]))
        ->assertRedirect('/');

    $this->assertGuest();
});

test('the check is skipped inside the recheck window', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->timestamp])
        ->get(route('dashboard', ['current_pharmacy' => $this->user->currentPharmacy->slug]))
        ->assertOk();

    Http::assertNothingSent();
});

test('an unreachable Joomla leaves the session alone', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->actingAs($this->user)
        ->withSession(['joomla.token_version_checked_at' => now()->subHour()->timestamp])
        ->get(route('dashboard', ['current_pharmacy' => $this->user->currentPharmacy->slug]))
        ->assertOk();

    $this->assertAuthenticated();
});

test('a guest is not checked at all', function () {
    Http::fake();

    $this->get('/')->assertOk();

    Http::assertNothingSent();
});
