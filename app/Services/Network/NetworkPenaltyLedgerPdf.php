<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Services\Settings\SettingsRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le journal des pénalités du réseau, mis en page pour être joint à un courrier.
 *
 * Les pages itèrent le journal publié par NetworkPenaltyJournal : un assureur
 * masqué n'a pas de page, et un mois retenu y est écrit « retenu ».
 */
class NetworkPenaltyLedgerPdf
{
    public function __construct(
        protected NetworkPenaltyJournal $journal,
        protected SettingsRepository $settings,
    ) {
        //
    }

    public function document(Period $from, Period $to, ?string $city, ?int $insurerId, string $periodLabel): PdfDocument
    {
        return Pdf::loadView('exports.penalty-ledger-network', [
            'ledger' => $this->journal->for($from, $to, $city, $insurerId),
            'city' => $city,
            'periodLabel' => $periodLabel,
            'anonymityThreshold' => $this->settings->anonymityMinPharmacies(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');
    }
}
