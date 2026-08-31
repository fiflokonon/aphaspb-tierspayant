<?php

use App\Models\PharmacyInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    PharmacyInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired pharmacy invitations');

Schedule::command('declarations:notify-overdue')
    // Lundi matin, heure du Bénin : app.timezone vaut UTC, et sans zone
    // explicite le récapitulatif arriverait une heure plus tard sur place.
    ->weeklyOn(1, '07:00')
    ->timezone('Africa/Porto-Novo')
    ->withoutOverlapping()
    ->description('Weekly overdue payment digests');
