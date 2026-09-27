<?php

namespace App\Http\Controllers\Pharmacy;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Exports\CsvRenderer;
use App\Services\Exports\XlsxWriter;
use App\Services\Pharmacy\PharmacyPenaltyLedger;
use App\Services\Pharmacy\PharmacyPenaltyLedgerPdf;
use App\Services\Pharmacy\PharmacyPenaltyLedgerRows;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le journal mensuel des pénalités d'une officine, et ses trois exports.
 *
 * Distinct de NetworkPenaltyLedgerController pour la même raison que les deux
 * exports : celui-ci nomme une officine et ne retient rien, l'autre ne nomme
 * personne et retient sous le seuil.
 *
 * L'officine vient toujours de la session, jamais de la requête.
 */
class PharmacyPenaltyLedgerController extends Controller
{
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(
        protected PharmacyPenaltyLedger $ledger,
        protected PharmacyPenaltyLedgerRows $rows,
        protected PharmacyPenaltyLedgerPdf $pdf,
        protected CsvRenderer $csv,
        protected XlsxWriter $xlsx,
    ) {
        //
    }

    public function index(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request, $pharmacy);

        [$from, $to] = $period->bounds();

        return Inertia::render('pharmacy/PenaltyLedger', [
            'penaltyTrend' => Inertia::defer(
                fn () => $this->ledger->for($pharmacy, $from, $to, $insurerId)->toArray(),
            ),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'insurer' => $insurerId,
            'insurers' => $this->ledger->clauseInsurers($pharmacy)
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->values(),
            'downloadUrl' => route('pharmacy.penalty-ledger.download', absolute: false),
            'pharmacyName' => $pharmacy->name,
        ]);
    }

    /**
     * L'assureur demandé, seulement s'il est sous convention **et** déclaré par
     * cette officine : un identifiant forgé retombe sur « tous », jamais sur
     * les chiffres d'un autre.
     */
    protected function insurerId(Request $request, Pharmacy $pharmacy): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->ledger->clauseInsurers($pharmacy)->contains('id', $requested) ? $requested : null;
    }

    public function download(Request $request): StreamedResponse|BinaryFileResponse
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request, $pharmacy);

        [$from, $to] = $period->bounds();

        $stem = sprintf('%s-penalites-%04d-%02d', $pharmacy->slug, $to->year, $to->month);

        // Un seul point d'entrée : le fichier ne peut pas couvrir une période
        // ou un assureur différents de l'écran.
        return match ($request->string('format')->value()) {
            'xlsx' => $this->workbook($stem.'.xlsx', $this->rows->rows($pharmacy, $from, $to, $insurerId)),
            'pdf' => $this->report($stem.'.pdf', $this->pdf->document($pharmacy, $from, $to, $insurerId, $period->describe())),
            default => $this->spreadsheet($stem.'.csv', $this->rows->rows($pharmacy, $from, $to, $insurerId)),
        };
    }

    /**
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    protected function spreadsheet(string $filename, iterable $rows): StreamedResponse
    {
        $lines = $this->csv->render(PharmacyPenaltyLedgerRows::COLUMNS, $rows);

        return response()->streamDownload(function () use ($lines) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Un BOM, sans quoi un Excel français lit les accents de travers.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($lines as $line) {
                // Échappement vide : le comportement RFC 4180 que les tableurs
                // attendent, et PHP 8.4 déprécie de ne pas le passer.
                fputcsv($handle, $line, ';', '"', '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Un fichier plutôt qu'un flux : le writer d'OpenSpout pose ses propres
     * en-têtes et se bat avec streamDownload().
     *
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    protected function workbook(string $filename, iterable $rows): BinaryFileResponse
    {
        // tempnam() tel quel : lui concaténer une extension écrirait dans un
        // second fichier et abandonnerait le premier, orphelin.
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        $this->xlsx->write($path, 'Journal des pénalités', PharmacyPenaltyLedgerRows::COLUMNS, $rows);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    protected function report(string $filename, PdfDocument $document): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        file_put_contents($path, $document->output());

        return response()->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend();
    }
}
