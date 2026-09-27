<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Exports\CsvRenderer;
use App\Services\Exports\XlsxWriter;
use App\Services\Network\NetworkPenaltyJournal;
use App\Services\Network\NetworkPenaltyLedgerPdf;
use App\Services\Network\NetworkPenaltyLedgerRows;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le journal mensuel des pénalités du réseau, et ses trois exports.
 *
 * Ne nomme aucune officine. Toute rétention se décide dans
 * NetworkPenaltyJournal : ce contrôleur n'en prend aucune.
 */
class NetworkPenaltyLedgerController extends Controller
{
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    public function __construct(
        protected NetworkPenaltyJournal $journal,
        protected NetworkPenaltyLedgerRows $rows,
        protected NetworkPenaltyLedgerPdf $pdf,
        protected CsvRenderer $csv,
        protected XlsxWriter $xlsx,
    ) {
        //
    }

    public function index(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request);

        [$from, $to] = $period->bounds();

        return Inertia::render('admin/PenaltyLedger', [
            'penaltyTrend' => Inertia::defer(
                fn () => $this->journal->for($from, $to, $city, $insurerId)->toArray(),
            ),
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),
            'insurer' => $insurerId,
            'insurers' => $this->journal->clauseInsurers()
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
                ->values(),
            'downloadUrl' => route('admin.penalty-ledger.download', absolute: false),
        ]);
    }

    /**
     * Un identifiant hors liste retombe sur « tous » : un fichier vide se
     * lirait « rien couru », pas « filtre sans objet ».
     */
    protected function insurerId(Request $request): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->journal->clauseInsurers()->contains('id', $requested) ? $requested : null;
    }

    public function download(Request $request): StreamedResponse|BinaryFileResponse
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request);

        [$from, $to] = $period->bounds();

        $stem = sprintf('reseau-penalites-%04d-%02d', $to->year, $to->month);

        // Un seul point d'entrée : le fichier ne peut pas couvrir une période,
        // une ville ou un assureur différents de l'écran.
        return match ($request->string('format')->value()) {
            'xlsx' => $this->workbook($stem.'.xlsx', $this->rows->rows($from, $to, $city, $insurerId)),
            'pdf' => $this->report($stem.'.pdf', $this->pdf->document($from, $to, $city, $insurerId, $period->describe())),
            default => $this->spreadsheet($stem.'.csv', $this->rows->rows($from, $to, $city, $insurerId)),
        };
    }

    /**
     * Recopiée de PharmacyPenaltyLedgerController plutôt que partagée par
     * héritage : la règle exports.md garde les deux périmètres dans deux
     * classes, et seuls les rendus (CsvRenderer, XlsxWriter) sont communs.
     *
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    protected function spreadsheet(string $filename, iterable $rows): StreamedResponse
    {
        $lines = $this->csv->render(NetworkPenaltyLedgerRows::COLUMNS, $rows);

        return response()->streamDownload(function () use ($lines) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // Un BOM, sans quoi un Excel français lit les accents de travers.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($lines as $line) {
                fputcsv($handle, $line, ';', '"', '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  iterable<int, list<string|int|null>>  $rows
     */
    protected function workbook(string $filename, iterable $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        $this->xlsx->write($path, 'Journal des pénalités', NetworkPenaltyLedgerRows::COLUMNS, $rows);

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
