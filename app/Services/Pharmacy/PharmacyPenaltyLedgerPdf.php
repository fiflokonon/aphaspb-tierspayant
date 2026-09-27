<?php

namespace App\Services\Pharmacy;

use App\Data\Period;
use App\Models\Pharmacy;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Le journal des pénalités d'une officine, mis en page pour être lu.
 */
class PharmacyPenaltyLedgerPdf
{
    public function __construct(protected PharmacyPenaltyLedger $ledger)
    {
        //
    }

    public function document(Pharmacy $pharmacy, Period $from, Period $to, ?int $insurerId, string $periodLabel): PdfDocument
    {
        return Pdf::loadView('exports.penalty-ledger-pharmacy', [
            'ledger' => $this->ledger->for($pharmacy, $from, $to, $insurerId),
            'pharmacyName' => $pharmacy->name,
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');
    }
}
