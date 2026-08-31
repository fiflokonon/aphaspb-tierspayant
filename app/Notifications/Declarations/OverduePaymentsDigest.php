<?php

namespace App\Notifications\Declarations;

use App\Data\OverdueLine;
use App\Models\Pharmacy;
use App\Support\Fcfa;
use App\Support\MonthLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le récapitulatif hebdomadaire des factures en retard d'une officine.
 *
 * Groupé plutôt qu'une alerte par facture : une officine qui reçoit sept
 * messages le même matin les classe en indésirables, et l'alerte cesse
 * d'alerter.
 */
class OverduePaymentsDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<OverdueLine>  $lines  la plus ancienne en tête
     */
    public function __construct(
        public Pharmacy $pharmacy,
        public array $lines,
    ) {
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
        $count = count($this->lines);
        $plural = $count > 1 ? 's' : '';

        $message = (new MailMessage)
            ->subject(sprintf(
                '%s : %d facture%s au-delà du délai de paiement',
                $this->pharmacy->name,
                $count,
                $plural,
            ))
            ->line(sprintf(
                'Au %s, %d facture%s de votre officine dépasse%s le délai convenu avec son assureur, pour un total de %s FCFA restant dû.',
                now()->translatedFormat('j F Y'),
                $count,
                $plural,
                $count > 1 ? 'nt' : '',
                Fcfa::format($this->outstanding()),
            ));

        foreach ($this->lines as $line) {
            $message->line(sprintf(
                '• %s · %s · %d jours écoulés (délai convenu : %d) · %s FCFA dus',
                $line->insurerName,
                MonthLabel::short($line->periodMonth, $line->periodYear),
                $line->ageDays,
                $line->standardDelayDays,
                Fcfa::format($line->outstanding),
            ));
        }

        return $message
            ->action('Ouvrir mon historique', route('pharmacy.history'))
            ->line("Ce récapitulatif est envoyé une fois par semaine tant qu'une facture reste en retard.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'pharmacy_id' => $this->pharmacy->id,
            'pharmacy_name' => $this->pharmacy->name,
            'outstanding' => $this->outstanding(),
            'lines' => array_map(fn (OverdueLine $line) => [
                'declaration_id' => $line->declarationId,
                'insurer_name' => $line->insurerName,
                'period_label' => MonthLabel::short($line->periodMonth, $line->periodYear),
                'age_days' => $line->ageDays,
                'standard_delay_days' => $line->standardDelayDays,
                'outstanding' => $line->outstanding,
            ], $this->lines),
        ];
    }

    protected function outstanding(): int
    {
        return array_sum(array_map(fn (OverdueLine $line) => $line->outstanding, $this->lines));
    }
}
