<?php

namespace App\Notifications\Declarations;

use App\Data\InsurerOverdueTotals;
use App\Support\Fcfa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le retard du réseau vu par l'APhaSPB.
 *
 * Agrégé par assureur, jamais par officine : le CDC n'autorise l'agrégation
 * qu'au-delà du seuil d'anonymat, et nommer ici une officine à côté d'un
 * montant contournerait cette protection par la porte de derrière.
 */
class NetworkOverdueDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<InsurerOverdueTotals>  $totals  le plus gros encours en tête
     */
    public function __construct(public array $totals)
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
        $message = (new MailMessage)
            ->subject('Retards de paiement du réseau — '.now()->translatedFormat('j F Y'))
            ->line(sprintf(
                'Au %s, %s FCFA restent dus au réseau au-delà des délais convenus.',
                now()->translatedFormat('j F Y'),
                Fcfa::format($this->outstanding()),
            ));

        foreach ($this->totals as $total) {
            $message->line(sprintf(
                '• %s (délai convenu : %d j) · %d facture%s sur %d officines · %s FCFA',
                $total->insurerName,
                $total->standardDelayDays,
                $total->declarations,
                $total->declarations > 1 ? 's' : '',
                $total->pharmacies,
                Fcfa::format($total->outstanding),
            ));
        }

        return $message
            ->action("Ouvrir l'évolution du réseau", route('admin.trends'))
            ->line("Les assureurs comptant trop peu d'officines déclarantes sont omis, conformément au seuil d'anonymat.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'outstanding' => $this->outstanding(),
            'insurers' => array_map(fn (InsurerOverdueTotals $total) => [
                'insurer_id' => $total->insurerId,
                'insurer_name' => $total->insurerName,
                'standard_delay_days' => $total->standardDelayDays,
                'declarations' => $total->declarations,
                'pharmacies' => $total->pharmacies,
                'outstanding' => $total->outstanding,
            ], $this->totals),
        ];
    }

    protected function outstanding(): int
    {
        return array_sum(array_map(fn (InsurerOverdueTotals $total) => $total->outstanding, $this->totals));
    }
}
