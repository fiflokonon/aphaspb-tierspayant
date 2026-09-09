<?php

namespace App\Http\Controllers\Pharmacy;

use App\Data\Period;
use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Exports\CsvRenderer;
use App\Services\Exports\XlsxWriter;
use App\Services\Pharmacy\PharmacyExportRows;
use App\Services\Pharmacy\PharmacyPdfExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An officine's own declarations, in the three formats the network gets.
 *
 * Deliberately a separate controller from NetworkExportController rather than a
 * mode of it: that one may never name an officine, this one reads exactly one
 * and hands back everything about it, private notes included. A single class
 * serving both would put that boundary behind an `if`.
 *
 * Which officine is never taken from the request — always from the session's
 * current pharmacy, so a crafted query string cannot export someone else's.
 */
class PharmacyExportController extends Controller
{
    /** The window the file covers, until the officine picks otherwise. */
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    /** @var Collection<int, Insurer>|null */
    protected ?Collection $declaredInsurers = null;

    public function __construct(
        protected PharmacyExportRows $source,
        protected PharmacyPdfExport $pdf,
        protected CsvRenderer $csv,
        protected XlsxWriter $xlsx,
    ) {
        //
    }

    public function index(Request $request): Response
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);

        return Inertia::render('pharmacy/Exports', [
            'downloadUrl' => route('pharmacy.data-exports.download', absolute: false),
            'columns' => PharmacyExportRows::COLUMNS,
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'insurer' => $this->insurerId($request, $pharmacy),
            'insurers' => $this->declaredInsurers($pharmacy),
            'pharmacyName' => $pharmacy->name,
        ]);
    }

    public function download(Request $request): StreamedResponse|BinaryFileResponse
    {
        $pharmacy = $request->user()->currentPharmacy;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurerId = $this->insurerId($request, $pharmacy);

        [$from, $to] = $period->bounds();

        $stem = sprintf('%s-tiers-payant-%04d-%02d', $pharmacy->slug, $to->year, $to->month);

        // Un seul point d'entrée pour les trois formats : le fichier ne peut
        // pas couvrir une période ou un assureur différents de l'écran.
        return match ($request->string('format')->value()) {
            'xlsx' => $this->workbook($pharmacy, $stem.'.xlsx', $from, $to, $insurerId),
            'pdf' => $this->report($pharmacy, $stem.'.pdf', $from, $to, $insurerId),
            default => $this->spreadsheet($pharmacy, $stem.'.csv', $from, $to, $insurerId),
        };
    }

    protected function spreadsheet(Pharmacy $pharmacy, string $filename, Period $from, Period $to, ?int $insurerId): StreamedResponse
    {
        $rows = $this->csv->render(
            PharmacyExportRows::COLUMNS,
            $this->source->rows($pharmacy, $from, $to, $insurerId),
        );

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // A BOM, or a French Excel renders « L'Africaine » as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                // L'échappement est explicitement vide : PHP 8.4 déprécie de ne
                // pas le passer, et « aucun » est le comportement RFC 4180 que
                // les tableurs attendent — avec le « \ » historique, une note
                // contenant un antislash ressort non relisible.
                fputcsv($handle, $row, ';', '"', '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A file rather than a stream: OpenSpout's browser writer sets its own
     * headers and fights streamDownload() for control of the response.
     */
    protected function workbook(Pharmacy $pharmacy, string $filename, Period $from, Period $to, ?int $insurerId): BinaryFileResponse
    {
        // Le chemin rendu par tempnam() tel quel : y concaténer une extension
        // écrirait dans un second fichier et abandonnerait celui que tempnam()
        // vient de créer — un orphelin par téléchargement. Le nom que voit
        // l'utilisateur est porté par l'en-tête Content-Disposition, pas par le
        // chemin sur disque.
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        $this->xlsx->write(
            $path,
            'Mes déclarations',
            PharmacyExportRows::COLUMNS,
            $this->source->rows($pharmacy, $from, $to, $insurerId),
        );

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    protected function report(Pharmacy $pharmacy, string $filename, Period $from, Period $to, ?int $insurerId): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        file_put_contents($path, $this->pdf->document($pharmacy, $from, $to, $insurerId)->output());

        return response()->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend();
    }

    /**
     * The insurer filter, kept only when this officine actually declared to it.
     *
     * An id it never declared to would simply match nothing, but silently
     * returning an empty file reads as « nothing was declared » rather than as
     * a filter that does not apply.
     */
    protected function insurerId(Request $request, Pharmacy $pharmacy): ?int
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->declaredInsurers($pharmacy)->contains('id', $requested) ? $requested : null;
    }

    /**
     * The insurers this officine has declarations for, whether still ticked or
     * not: dropping one it stopped working with would hide its own past.
     *
     * @return Collection<int, Insurer>
     */
    protected function declaredInsurers(Pharmacy $pharmacy)
    {
        // Mémoïsée : index() la lit pour la liste déroulante et à nouveau, via
        // insurerId(), pour valider le filtre — deux fois la même requête.
        return $this->declaredInsurers ??= Insurer::query()
            ->whereIn('id', $pharmacy->declarations()->distinct()->pluck('insurer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
