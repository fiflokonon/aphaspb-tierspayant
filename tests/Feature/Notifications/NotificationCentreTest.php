<?php

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\PharmacyInvitation;
use App\Models\User;
use App\Notifications\Declarations\OverduePaymentsDigest;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => useJoomlaTestKeys());

/**
 * Une notification de retard déjà enregistrée, sans passer par l'envoi.
 *
 * @param  array<string, mixed>  $data
 */
function storedDigest(User $user, array $data = []): string
{
    $id = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $id,
        'type' => OverduePaymentsDigest::class,
        'data' => [
            'pharmacy_id' => 1,
            'pharmacy_name' => 'Pharmacie Témoin',
            'outstanding' => 1_240_000,
            'lines' => [[
                'declaration_id' => 1,
                'insurer_name' => 'Mutuelle A',
                'period_label' => 'Août 26',
                'age_days' => 62,
                'standard_delay_days' => 45,
                'outstanding' => 1_240_000,
            ]],
            ...$data,
        ],
        'read_at' => null,
    ]);

    return $id;
}

test('the bell counts unread notifications and pending invitations', function () {
    $user = User::factory()->create();

    storedDigest($user);
    storedDigest($user);

    $pharmacy = Pharmacy::factory()->create();
    $inviter = User::factory()->create();
    $pharmacy->members()->attach($inviter, ['role' => PharmacyRole::Owner->value]);

    PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $inviter->id,
        'email' => $user->email,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('notifications/Index')
            ->where('notifications.unread', 3),
        );
});

test('reading a notification clears it from the count', function () {
    $user = User::factory()->create();
    $id = storedDigest($user);

    $this->actingAs($user)
        ->patch(route('notifications.update', ['notification' => $id]))
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.unread', 0));
});

test('a notification belonging to someone else cannot be marked read', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $id = storedDigest($theirs);

    // 404 plutôt que 403 : un 403 confirmerait que la notification existe.
    $this->actingAs($mine)
        ->patch(route('notifications.update', ['notification' => $id]))
        ->assertNotFound();

    expect($theirs->notifications()->sole()->read_at)->toBeNull();
});

test('the list carries overdue digests and pending invitations together', function () {
    $user = User::factory()->create();
    storedDigest($user);

    $pharmacy = Pharmacy::factory()->create(['name' => 'Pharmacie Invitante']);
    $inviter = User::factory()->create();
    $pharmacy->members()->attach($inviter, ['role' => PharmacyRole::Owner->value]);

    PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $inviter->id,
        'email' => $user->email,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(function (AssertableInertia $page) {
            $rows = collect($page->toArray()['props']['notifications']['items']['data']);

            expect($rows)->toHaveCount(2)
                ->and($rows->pluck('kind')->sort()->values()->all())
                ->toBe(['invitation', 'notification']);

            // Chaque type doit se présenter tout seul, sans que le front ait à
            // connaître un nom de classe PHP.
            $rows->each(function (array $row) {
                expect($row['title'])->not->toBe('')
                    ->and($row['body'])->not->toBe('');
            });
        });
});

test('an accepted invitation no longer shows up', function () {
    $user = User::factory()->create();

    $pharmacy = Pharmacy::factory()->create();
    $inviter = User::factory()->create();
    $pharmacy->members()->attach($inviter, ['role' => PharmacyRole::Owner->value]);

    PharmacyInvitation::factory()->create([
        'pharmacy_id' => $pharmacy->id,
        'invited_by' => $inviter->id,
        'email' => $user->email,
        'accepted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications.unread', 0));
});

test('the centre honours a whitelisted page size', function () {
    $user = User::factory()->create();

    foreach (range(1, 12) as $ignored) {
        storedDigest($user);
    }

    $this->actingAs($user)
        ->get(route('notifications.index', ['per_page' => 10]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('notifications.items.data', 10)
            ->where('notifications.perPage', 10),
        );
});
