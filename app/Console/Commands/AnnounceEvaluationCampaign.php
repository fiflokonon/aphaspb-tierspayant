<?php

namespace App\Console\Commands;

use App\Enums\PharmacyRole;
use App\Models\EvaluationCampaign;
use App\Models\User;
use App\Notifications\Evaluations\EvaluationCampaignOpened;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Annoncer aux officines la campagne d'évaluation qui vient d'ouvrir.
 *
 * Lancée chaque matin : elle ne fait rien tant qu'aucune campagne n'est
 * ouverte, et une campagne n'est annoncée qu'une fois (`announced_at`).
 *
 * Mêmes destinataires que le récapitulatif des retards : titulaires et
 * administrateurs des officines qui ont au moins un assureur, chacun une
 * seule fois même s'il en tient plusieurs.
 */
class AnnounceEvaluationCampaign extends Command
{
    protected $signature = 'evaluations:announce
                            {--dry-run : Affiche ce qui partirait, sans rien envoyer}';

    protected $description = "Annoncer l'ouverture de la campagne d'évaluation des assureurs";

    public function handle(): int
    {
        $campaign = EvaluationCampaign::current();

        if ($campaign === null) {
            $this->line('Aucune campagne ouverte.');

            return self::SUCCESS;
        }

        if ($campaign->announced_at !== null) {
            $this->line("Campagne {$campaign->year} déjà annoncée.");

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereHas('pharmacies', fn (Builder $query) => $query
                ->whereIn('pharmacy_members.role', [PharmacyRole::Owner->value, PharmacyRole::Admin->value])
                ->whereHas('insurers'))
            ->get();

        $this->line("Campagne {$campaign->year} : {$recipients->count()} destinataire(s).");

        if ($this->option('dry-run')) {
            $this->info("Essai à blanc : rien n'a été envoyé.");

            return self::SUCCESS;
        }

        // Daté avant l'envoi : un échec à mi-chemin ne renverra pas l'annonce
        // à ceux qui l'ont déjà reçue, ce qui vaut mieux qu'un doublon.
        $campaign->forceFill(['announced_at' => now()])->save();

        Notification::send($recipients, new EvaluationCampaignOpened($campaign));

        $this->info('Annonce envoyée.');

        return self::SUCCESS;
    }
}
