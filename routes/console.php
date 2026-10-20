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

Schedule::command('evaluations:announce')
    // Chaque matin : la commande ne fait rien tant qu'aucune campagne n'est
    // ouverte, et n'annonce une campagne qu'une fois. Une campagne créée dans
    // la journée sur une ouverture du jour part donc le lendemain matin.
    ->dailyAt('07:00')
    ->timezone('Africa/Porto-Novo')
    ->withoutOverlapping()
    ->description('Announce the insurer evaluation campaign');

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    // L'hébergement mutualisé n'a pas de worker permanent : le cron du
    // planificateur vide la file chaque minute, puis rend la main. Sans cela
    // les notifications ShouldQueue (invitations, récapitulatifs des retards)
    // resteraient dans la table `jobs` sans jamais partir. --max-time borne
    // le passage sous la minute, withoutOverlapping empêche deux vidages
    // simultanés.
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Drain the queue without a long-running worker');
