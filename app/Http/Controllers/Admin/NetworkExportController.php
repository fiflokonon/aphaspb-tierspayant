<?php

namespace App\Http\Controllers\Admin;

use App\Data\Period;
use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Insurer;
use App\Models\Pharmacy;
use App\Services\Network\NetworkCsvExport;
use App\Services\Network\NetworkPdfExport;
use App\Services\Network\NetworkXlsxExport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The aggregated network statistics, as a CSV for advocacy notes.
 *
 * Streamed rather than built in memory: seven insurers do not require it today,
 * but the cost is nil and it removes the question if the APhaSPB later asks for
 * one file per month and per city.
 */
class NetworkExportController extends Controller
{
    /** The window the file covers, until the admin picks otherwise. */
    protected const DEFAULT_PERIOD = StatsPeriod::LastTwelveMonths;

    /** @var Collection<int, Insurer>|null */
    protected ?Collection $declaredInsurers = null;

    public function __construct(
        protected NetworkCsvExport $csv,
        protected NetworkXlsxExport $xlsx,
        protected NetworkPdfExport $pdf,
    ) {
        //
    }

    public function index(Request $request): Response
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);

        return Inertia::render('admin/Exports', [
            'downloadUrl' => route('admin.csv-exports.download', absolute: false),
            'columns' => NetworkCsvExport::COLUMNS,
            'period' => $period->value,
            'periodLabel' => $period->describe(),
            'periods' => StatsPeriod::options(),
            'city' => $city,
            'cities' => Pharmacy::filterableCities(),
            'insurer' => $this->chosenInsurer($request)?->id,
            'insurers' => $this->declaredInsurers(),
        ]);
    }

    public function download(Request $request): StreamedResponse|BinaryFileResponse
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);
        $insurer = $this->chosenInsurer($request);
        $insurerId = $insurer?->id;

        [$from, $to] = $period->bounds();

        $stem = $this->filenameStem($to, $insurer);

        // Three formats, one route: the file then cannot cover a different
        // period, city or insurer from the screen the admin is looking at.
        if ($request->string('format')->value() === 'xlsx') {
            return $this->workbook($stem.'.xlsx', $from, $to, $city, $insurerId);
        }

        if ($request->string('format')->value() === 'pdf') {
            return $this->report($stem.'.pdf', $from, $to, $city, $insurerId);
        }

        $filename = $stem.'.csv';
        $rows = $this->csv->rows($from, $to, $city, $insurerId);

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
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Write the workbook to a temporary file and hand it over.
     *
     * A file rather than a stream: OpenSpout's browser writer sets its own
     * headers and fights streamDownload() for control of the response.
     */
    protected function workbook(string $filename, Period $from, Period $to, ?string $city, ?int $insurerId = null): BinaryFileResponse
    {
        // Le chemin rendu par tempnam() tel quel : y concaténer une extension
        // écrirait dans un second fichier et abandonnerait celui que tempnam()
        // vient de créer — un orphelin par téléchargement. Le nom que voit
        // l'utilisateur est porté par l'en-tête Content-Disposition, pas par le
        // chemin sur disque.
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        $this->xlsx->writeTo($path, $from, $to, $city, $insurerId);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * The same figures as a report meant to be read, not recomputed.
     *
     * Written to a file for the same reason as the workbook: dompdf's own
     * stream helper takes over the response, and a downloaded file is one code
     * path for both binary formats rather than two.
     */
    protected function report(string $filename, Period $from, Period $to, ?string $city, ?int $insurerId = null): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'aphaspb');

        file_put_contents($path, $this->pdf->document($from, $to, $city, $insurerId)->output());

        return response()->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend();
    }

    /**
     * The chosen insurer, resolved only when the network knows it.
     *
     * Returns the model rather than its id so the filename never has to look
     * it up again: a second lookup would be a second chance to find nothing,
     * and the dead `?? ''` that guarded it read as if that could happen.
     *
     * An unknown id would simply match nothing, and an empty file reads as
     * « nothing was declared » rather than as a filter that does not apply.
     *
     * Unlike the officine's own export, no ownership question arises here: an
     * APhaSPB admin may read every insurer. What an unauthorised id would buy
     * is an empty file, not someone else's figures.
     */
    protected function chosenInsurer(Request $request): ?Insurer
    {
        $requested = $request->integer('insurer');

        if ($requested === 0) {
            return null;
        }

        return $this->declaredInsurers()->firstWhere('id', $requested);
    }

    /**
     * The insurers the network has declarations for, in alphabetical order.
     *
     * Deliberately not narrowed by the period or the city on screen: a list
     * that emptied itself as the admin changed period would read as a bug, and
     * an insurer with nothing in the window yields an honest empty file.
     *
     * @return Collection<int, Insurer>
     */
    protected function declaredInsurers(): Collection
    {
        // Mémoïsée : index() la lit pour la liste déroulante et à nouveau, via
        // insurerId(), pour valider le filtre — deux fois la même requête.
        return $this->declaredInsurers ??= Insurer::query()
            ->whereIn('id', DB::table('declarations')->distinct()->pluck('insurer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The download's base name, which states the insurer when there is one.
     *
     * Without it, two files covering different insurers are indistinguishable
     * in a downloads folder.
     */
    protected function filenameStem(Period $to, ?Insurer $insurer): string
    {
        if ($insurer === null) {
            return sprintf('aphaspb-reseau-%04d-%02d', $to->year, $to->month);
        }

        return sprintf(
            'aphaspb-reseau-%s-%04d-%02d',
            Str::slug($insurer->name),
            $to->year,
            $to->month,
        );
    }
}
