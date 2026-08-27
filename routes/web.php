<?php

use App\Http\Controllers\Admin\NetworkStatsController;
use App\Http\Controllers\Admin\NetworkTrendsController;
use App\Http\Controllers\Auth\JoomlaCallbackController;
use App\Http\Controllers\Auth\LoginRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\Dev\LocalLoginController;
use App\Http\Controllers\Onboarding\PharmacyInsurersController;
use App\Http\Controllers\Onboarding\PharmacyProfileController;
use App\Http\Controllers\Pharmacies\PharmacyInvitationController;
use App\Http\Controllers\Pharmacy\DeclarationController;
use App\Http\Controllers\Pharmacy\PaymentJourneyController;
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
        Route::get('network', NetworkStatsController::class)->name('network');
        Route::get('trends', NetworkTrendsController::class)->name('trends');
        Route::get('pharmacies', ComingSoonController::class)->name('pharmacies');
        Route::get('insurers', ComingSoonController::class)->name('insurers');
        Route::get('csv-exports', ComingSoonController::class)->name('csv-exports');
    });

Route::middleware(['auth', 'can:declare-payments'])
    ->prefix('onboarding')
    ->name('onboarding.')
    ->group(function () {
        Route::get('/', [PharmacyProfileController::class, 'edit'])->name('profile');
        Route::post('/', [PharmacyProfileController::class, 'store'])->name('profile.store');
        Route::get('insurers', [PharmacyInsurersController::class, 'edit'])->name('insurers');
        Route::post('insurers', [PharmacyInsurersController::class, 'store'])->name('insurers.store');
    });

Route::middleware(['auth', 'can:declare-payments', 'onboarded'])
    ->prefix('pharmacy')
    ->name('pharmacy.')
    ->group(function () {
        Route::get('declare', [DeclarationController::class, 'show'])->name('declare');
        Route::post('declare', [DeclarationController::class, 'store'])->name('declare.store');
        Route::get('history', ComingSoonController::class)->name('history');
        Route::get('insurers', ComingSoonController::class)->name('insurers');
    });

Route::prefix('{current_pharmacy}')
    ->middleware(['auth', 'verified', 'can:declare-payments', 'onboarded', EnsurePharmacyMembership::class])
    ->group(function () {
        Route::get('dashboard', PaymentJourneyController::class)->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [PharmacyInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [PharmacyInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| Local development only
|--------------------------------------------------------------------------
|
| A side door that opens a session without Joomla, so the screens can be
| looked at before the CMS plugin exists. Registered only when the
| application is local: in production these routes are absent from the
| router, not merely guarded. tests/Feature/Dev/LocalLoginTest.php asserts
| that absence.
|
*/

if (app()->isLocal()) {
    Route::get('dev/login/{profile}', LocalLoginController::class)->name('dev.login');
}
