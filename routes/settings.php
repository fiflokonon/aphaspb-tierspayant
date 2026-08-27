<?php

use App\Http\Controllers\Pharmacies\PharmacyController;
use App\Http\Controllers\Pharmacies\PharmacyInvitationController;
use App\Http\Controllers\Pharmacies\PharmacyMemberController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Middleware\EnsurePharmacyMembership;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/pharmacies', [PharmacyController::class, 'index'])->name('pharmacies.index');
    Route::post('settings/pharmacies', [PharmacyController::class, 'store'])->name('pharmacies.store');

    Route::middleware(EnsurePharmacyMembership::class)->group(function () {
        Route::get('settings/pharmacies/{pharmacy}', [PharmacyController::class, 'edit'])->name('pharmacies.edit');
        Route::patch('settings/pharmacies/{pharmacy}', [PharmacyController::class, 'update'])->name('pharmacies.update');
        Route::delete('settings/pharmacies/{pharmacy}', [PharmacyController::class, 'destroy'])->name('pharmacies.destroy');
        Route::post('settings/pharmacies/{pharmacy}/switch', [PharmacyController::class, 'switch'])->name('pharmacies.switch');
        Route::delete('settings/pharmacies/{pharmacy}/leave', [PharmacyController::class, 'leave'])->name('pharmacies.leave');

        Route::patch('settings/pharmacies/{pharmacy}/members/{user}', [PharmacyMemberController::class, 'update'])->name('pharmacies.members.update');
        Route::delete('settings/pharmacies/{pharmacy}/members/{user}', [PharmacyMemberController::class, 'destroy'])->name('pharmacies.members.destroy');

        Route::post('settings/pharmacies/{pharmacy}/invitations', [PharmacyInvitationController::class, 'store'])->name('pharmacies.invitations.store');
        Route::delete('settings/pharmacies/{pharmacy}/invitations/{invitation}', [PharmacyInvitationController::class, 'destroy'])->name('pharmacies.invitations.destroy');
    });
});
