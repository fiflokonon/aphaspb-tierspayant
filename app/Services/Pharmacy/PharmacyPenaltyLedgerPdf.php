<?php

namespace App\Services\Pharmacy;

use App\Models\Pharmacy;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * La trace des pénalités d'une officine, mise en page pour être jointe à une
 * réclamation : la pénalité exigible au jour, puis, facture par facture, le
 * calcul et chaque événement qui y mène.
 *
 * Les libellés viennent de PharmacyPenaltyTraceRows : le PDF et le tableur
 * disent les mêmes mots.
 */
class PharmacyPenaltyLedgerPdf
{
    public function __construct(
        protected PharmacyPenaltyTrace $trace,
        protected PharmacyPenaltyTraceRows $wording,
    ) {
        //
    }

    public function document(Pharmacy $pharmacy, ?int $insurerId): PdfDocument
    {
        return Pdf::loadView('exports.penalty-ledger-pharmacy', [
            'trace' => $this->trace->for($pharmacy, $insurerId),
            'wording' => $this->wording,
            'pharmacyName' => $pharmacy->name,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');
    }
}
