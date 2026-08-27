<?php

use App\Http\Controllers\Auth\JoomlaCallbackController;
use App\Http\Controllers\Auth\LoginRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Pharmacies\PharmacyInvitationController;
use App\Http\Middleware\EnsurePharmacyMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('login', LoginRedirectController::class)->name('login');
Route::post('auth/callback', JoomlaCallbackController::class)->name('auth.callback');
Route::post('auth/logout', LogoutController::class)->name('auth.logout');

Route::middleware(['auth', 'can:manage-network'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('network', ComingSoonController::class)->name('network');
        Route::get('pharmacies', ComingSoonController::class)->name('pharmacies');
        Route::get('insurers', ComingSoonController::class)->name('insurers');
        Route::get('csv-exports', ComingSoonController::class)->name('csv-exports');
    });

Route::middleware(['auth', 'can:declare-payments'])
    ->prefix('pharmacy')
    ->name('pharmacy.')
    ->group(function () {
        Route::get('declare', ComingSoonController::class)->name('declare');
        Route::get('history', ComingSoonController::class)->name('history');
        Route::get('insurers', ComingSoonController::class)->name('insurers');
    });

Route::prefix('{current_pharmacy}')
    ->middleware(['auth', 'verified', EnsurePharmacyMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [PharmacyInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [PharmacyInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
