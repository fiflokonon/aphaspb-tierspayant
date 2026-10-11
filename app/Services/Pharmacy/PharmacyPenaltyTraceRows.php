<?php

namespace App\Services\Pharmacy;

use App\Models\Pharmacy;
use App\Support\Fcfa;

/**
 * La trace des pénalités d'une officine, en lignes de tableur.
 *
 * Le fichier se joint à une réclamation : il doit se lire seul, sans l'écran.
 * D'où une ligne par événement de chaque facture (dépôt, déclenchement,
 * tranches, versements, solde, clôture) avec son libellé et son calcul en
 * clair, puis une ligne « Pénalité exigible » qui clôt la facture. Seule cette
 * dernière remplit `penalite_exigible` : la colonne se somme sans doublon.
 *
 * Mêmes chiffres que l'écran, puisque tout sort de PharmacyPenaltyTrace. Les
 * libellés vivent ici, et la vue PDF les reprend par title() et detail().
 *
 * Classe sœur de NetworkPenaltyLedgerRows, jamais fusionnée avec elle : celle-ci
 * nomme l'officine et ne retient rien (règle exports.md).
 *
 * @phpstan-import-type TraceEvent from PharmacyPenaltyTrace
 * @phpstan-import-type TraceInvoice from PharmacyPenaltyTrace
 * @phpstan-import-type TraceInsurer from PharmacyPenaltyTrace
 */
class PharmacyPenaltyTraceRows
{
    public const COLUMNS = [
        'assureur', 'facture_mois', 'montant_facture', 'depose_le', 'statut_penalite',
        'date', 'evenement', 'detail', 'versement', 'reste_du_facture',
        'tranche_n', 'montant_tranche', 'penalite_cumulee', 'penalite_exigible',
    ];

    public function __construct(protected PharmacyPenaltyTrace $trace)
    {
        //
    }

    /**
     * @return iterable<int, list<string|int|null>>
     */
    public function rows(Pharmacy $pharmacy, ?int $insurerId = null): iterable
    {
        $trace = $this->trace->for($pharmacy, $insurerId);

        foreach ($trace['insurers'] as $insurer) {
            foreach ($insurer['invoices'] as $invoice) {
                $invoiceColumns = [
                    $insurer['name'],
                    $invoice['period'],
                    $invoice['amountInvoiced'],
                    $invoice['events'][0]['date'],
                    $this->status($invoice),
                ];

                foreach ($invoice['events'] as $event) {
                    yield [
                        ...$invoiceColumns,
                        $event['date'],
                        $this->title($event),
                        $this->detail($event, $invoice, $insurer),
                        $event['kind'] === 'payment' ? $event['amount'] : null,
                        $event['remaining'],
                        $event['number'],
                        $event['kind'] === 'tranche' ? $event['amount'] : null,
                        $event['cumulative'],
                        null,
                    ];
                }

                yield [
                    ...$invoiceColumns,
                    $trace['asOfDate'],
                    'Pénalité exigible',
                    $this->dueDetail($invoice),
                    null, null, null, null, null,
                    $invoice['due'],
                ];
            }
        }
    }

    /**
     * @param  TraceEvent  $event
     */
    public function title(array $event): string
    {
        return match ($event['kind']) {
            'deposit' => 'Facture déposée',
            'trigger' => 'Déclenchement de la pénalité',
            'tranche' => 'Tranche n° '.$event['number'],
            'payment' => 'Versement reçu',
            'paidOff' => 'Facture soldée',
            'settled' => 'Pénalité close : '.mb_strtolower((string) $event['label']),
        };
    }

    /**
     * @param  TraceEvent  $event
     * @param  TraceInvoice  $invoice
     * @param  TraceInsurer  $insurer
     */
    public function detail(array $event, array $invoice, array $insurer): string
    {
        return match ($event['kind']) {
            'deposit' => 'Montant facturé : '.$this->money($event['amount']),
            'trigger' => sprintf('Dépôt + %d jours (délai de la convention)', $insurer['triggerDays']),
            'tranche' => sprintf('%s × %s', $this->money($invoice['amountInvoiced']), $this->percent($insurer['ratePercent'])),
            'payment' => sprintf('%s encaissés · reste dû %s', $this->money($event['amount']), $this->money($event['remaining'])),
            'paidOff' => 'La pénalité cesse de courir',
            'settled' => 'Montant clos : '.$this->money($event['amount']),
        };
    }

    /**
     * « 4 tranches × 20 000 F = 80 000 F », ou la raison d'un zéro.
     *
     * @param  TraceInvoice  $invoice
     */
    public function dueDetail(array $invoice): string
    {
        $calculation = sprintf(
            '%d tranche%s × %s = %s',
            $invoice['tranches'],
            $invoice['tranches'] > 1 ? 's' : '',
            $this->money($invoice['trancheAmount']),
            $this->money($invoice['accrued']),
        );

        return $invoice['settlement'] === null
            ? $calculation
            : $calculation.' · pénalité '.mb_strtolower($invoice['settlement']['label']).', plus rien à réclamer';
    }

    /**
     * @param  TraceInvoice  $invoice
     */
    public function status(array $invoice): string
    {
        return match (true) {
            $invoice['settlement'] !== null => 'Pénalité '.mb_strtolower($invoice['settlement']['label']),
            $invoice['running'] => 'Court encore',
            default => 'Facture soldée',
        };
    }

    public function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').' %';
    }

    protected function money(?int $amount): string
    {
        return Fcfa::format($amount ?? 0).' F';
    }
}
