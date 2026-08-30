<?php

namespace App\Http\Controllers\Admin;

use App\Data\Period;
use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Network\NetworkCsvExport;
use App\Services\Network\NetworkXlsxExport;
use Illuminate\Http\Request;
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

    public function __construct(
        protected NetworkCsvExport $csv,
        protected NetworkXlsxExport $xlsx,
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
        ]);
    }

    public function download(Request $request): StreamedResponse|BinaryFileResponse
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);

        [$from, $to] = $period->bounds();

        $stem = sprintf('aphaspb-reseau-%04d-%02d', $to->year, $to->month);

        // Two formats, one route: the file then cannot cover a different
        // period or city from the screen the admin is looking at.
        if ($request->string('format')->value() === 'xlsx') {
            return $this->workbook($stem.'.xlsx', $from, $to, $city);
        }

        $filename = $stem.'.csv';
        $rows = $this->csv->rows($from, $to, $city);

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // A BOM, or a French Excel renders « L'Africaine » as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
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
    protected function workbook(string $filename, Period $from, Period $to, ?string $city): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'aphaspb').'.xlsx';

        $this->xlsx->writeTo($path, $from, $to, $city);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
