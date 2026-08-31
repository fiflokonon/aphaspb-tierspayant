<?php

namespace App\Console\Commands;

use App\Enums\PharmacyRole;
use App\Models\Pharmacy;
use App\Models\User;
use App\Notifications\Declarations\NetworkOverdueDigest;
use App\Notifications\Declarations\OverduePaymentsDigest;
use App\Services\Declarations\OverduePaymentsService;
use App\Support\Fcfa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Le récapitulatif hebdomadaire des factures en retard.
 *
 * Aucune table de suivi : ce qui empêche un second envoi dans la semaine est
 * une lecture de la table `notifications`. Le choix d'un récapitulatif groupé
 * rend inutile toute mémoire par facture — il n'y a qu'une question binaire,
 * cette officine a-t-elle déjà reçu son digest cette semaine.
 */
class NotifyOverduePayments extends Command
{
    protected $signature = 'declarations:notify-overdue
                            {--dry-run : Affiche ce qui partirait, sans rien envoyer}
                            {--force : Envoie même si un récapitulatif est déjà parti cette semaine}';

    protected $description = 'Prévenir les officines des factures dépassant le délai de leur assureur';

    public function handle(OverduePaymentsService $overdue): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $sent = 0;

        foreach ($overdue->pharmaciesWithOverdue() as $pharmacy) {
            $lines = $overdue->forPharmacy($pharmacy);

            if ($lines === []) {
                continue;
            }

            if (! $force && $this->alreadyNotifiedThisWeek($pharmacy)) {
                $this->line("· {$pharmacy->name} — déjà prévenue cette semaine");

                continue;
            }

            $recipients = $pharmacy->members()
                ->wherePivotIn('role', [PharmacyRole::Owner->value, PharmacyRole::Admin->value])
                ->get();

            $total = array_sum(array_map(fn ($line) => $line->outstanding, $lines));

            $this->line(sprintf(
                '· %s — %d facture%s, %s FCFA, %d destinataire%s',
                $pharmacy->name,
                count($lines),
                count($lines) > 1 ? 's' : '',
                Fcfa::format($total),
                $recipients->count(),
                $recipients->count() > 1 ? 's' : '',
            ));

            if ($dryRun || $recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new OverduePaymentsDigest($pharmacy, $lines));
            $sent++;
        }

        $this->notifyNetworkAdmins($overdue, $dryRun);

        $this->info($dryRun
            ? "Essai à blanc : rien n'a été envoyé."
            : "{$sent} récapitulatif(s) envoyé(s).");

        return self::SUCCESS;
    }

    /**
     * Le digest agrégé de l'APhaSPB.
     *
     * Les admins ne sont pas un rôle en base mais un groupe Joomla, et la
     * shadow table ne connaît que les comptes déjà connectés au moins une
     * fois : ce digest ne touche que ceux-là, ce qui est une propriété du
     * modèle d'authentification et non un défaut de cet envoi.
     */
    protected function notifyNetworkAdmins(OverduePaymentsService $overdue, bool $dryRun): void
    {
        $totals = $overdue->networkTotals();

        if ($totals === []) {
            $this->line("· Réseau — rien au-delà du seuil d'anonymat");

            return;
        }

        $admins = User::query()
            ->where(function ($query) {
                foreach ((array) config('joomla.groups.admin') as $group) {
                    $query->orWhereJsonContains('joomla_groups', $group);
                }
            })
            ->get();

        $this->line(sprintf(
            '· Réseau — %d assureur(s), %d destinataire(s)',
            count($totals),
            $admins->count(),
        ));

        if ($dryRun || $admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new NetworkOverdueDigest($totals));
    }

    /**
     * Un récapitulatif est-il déjà parti pour cette officine cette semaine ?
     *
     * Limite connue : la notification est `ShouldQueue`, donc la ligne
     * n'apparaît qu'une fois le job traité. Deux exécutions à quelques
     * secondes d'intervalle pourraient doubler l'envoi — ce que la
     * planification hebdomadaire et `withoutOverlapping()` rendent théorique.
     * `--force` existe pour le rattrapage délibéré.
     */
    protected function alreadyNotifiedThisWeek(Pharmacy $pharmacy): bool
    {
        return DB::table('notifications')
            ->where('type', OverduePaymentsDigest::class)
            ->where('data->pharmacy_id', $pharmacy->id)
            ->where('created_at', '>=', now()->startOfWeek())
            ->exists();
    }
}
