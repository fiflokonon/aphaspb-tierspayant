<?php

namespace App\Notifications\Evaluations;

use App\Models\EvaluationCampaign;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'ouverture d'une campagne d'évaluation des assureurs.
 *
 * Une par personne et par campagne, pas une par officine : un titulaire de
 * deux officines n'a qu'une campagne à apprendre, et l'écran d'évaluation
 * suit l'officine courante.
 *
 * Envoyée sur-le-champ, sans file : l'hébergement mutualisé n'a pas de
 * worker, et l'envoi part déjà d'une commande lancée par le cron, hors de
 * toute requête qu'il ralentirait.
 */
class EvaluationCampaignOpened extends Notification
{
    public function __construct(public EvaluationCampaign $campaign)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Évaluation des assureurs {$this->campaign->year} : la campagne est ouverte")
            ->line(sprintf(
                "L'APhaSPB ouvre l'évaluation annuelle des assureurs sur l'année %d. Vous pouvez noter chacun de vos assureurs jusqu'au %s.",
                $this->campaign->year,
                $this->campaign->closes_on->format('d/m/Y'),
            ))
            ->line('La note du respect du délai de règlement vous est proposée à partir de vos déclarations ; il vous reste à la valider et à noter les autres critères.')
            ->action('Évaluer mes assureurs', route('pharmacy.evaluations'))
            ->line('Vos notes ne sont jamais publiées sous le nom de votre officine.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'year' => $this->campaign->year,
            'closes_on' => $this->campaign->closes_on->toDateString(),
        ];
    }
}
