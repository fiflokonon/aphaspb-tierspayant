<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['joomla.site_url' => 'https://joomla.test']);
});

test('a write is recorded with its author, officine and origin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('User-Agent', 'TestBrowser/1.0')
        ->post(route('auth.logout'));

    $entry = AuditLog::sole();

    // The logout leaves no user behind it: the author is read before.
    expect($entry->user_id)->toBe($user->id)
        ->and($entry->pharmacy_id)->toBe($user->current_pharmacy_id)
        ->and($entry->method)->toBe('POST')
        ->and($entry->route_name)->toBe('auth.logout')
        ->and($entry->path)->toBe('auth/logout')
        ->and($entry->status)->toBe(302)
        ->and($entry->ip_address)->toBe('127.0.0.1')
        ->and($entry->user_agent)->toBe('TestBrowser/1.0');
});

test('a failed trace does not turn a successful write into an error', function () {
    Schema::drop('audit_logs');

    $this->actingAs(User::factory()->create())
        ->post(route('auth.logout'))
        ->assertRedirect('https://joomla.test');

    $this->assertGuest();
});

test('a plain page view is not recorded', function () {
    $this->actingAs(User::factory()->create())->get(route('notifications.index'))->assertOk();

    expect(AuditLog::count())->toBe(0);
});

test('a refused request is recorded even when it only reads', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.network'))->assertForbidden();

    expect(AuditLog::sole())
        ->user_id->toBe($user->id)
        ->route_name->toBe('admin.network')
        ->status->toBe(403);
});

test('a download is recorded without its query string', function () {
    $admin = User::factory()->networkAdmin()->notOnboarded()->create();

    $this->actingAs($admin)->get(route('admin.csv-exports.download', ['year' => 2026]))->assertOk();

    expect(AuditLog::sole())
        ->route_name->toBe('admin.csv-exports.download')
        ->path->toBe('admin/csv-exports/download');
});

test('entries older than the retention are pruned, recent ones kept', function () {
    $expired = AuditLog::factory()->create(['created_at' => now()->subMonths(AuditLog::RETENTION_MONTHS)->subDay()]);
    $recent = AuditLog::factory()->create(['created_at' => now()->subMonths(AuditLog::RETENTION_MONTHS)->addDay()]);

    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

    expect(AuditLog::find($expired->id))->toBeNull()
        ->and(AuditLog::find($recent->id))->not->toBeNull();
});
