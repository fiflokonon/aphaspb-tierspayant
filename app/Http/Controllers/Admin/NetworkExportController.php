<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatsPeriod;
use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Services\Network\NetworkCsvExport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
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

    public function __construct(protected NetworkCsvExport $export)
    {
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

    public function download(Request $request): StreamedResponse
    {
        $city = $request->string('city')->value() ?: null;
        $period = StatsPeriod::fromRequest($request->string('period')->value(), self::DEFAULT_PERIOD);

        [$from, $to] = $period->bounds();

        $filename = sprintf(
            'aphaspb-reseau-%04d-%02d.csv',
            $to->year,
            $to->month,
        );

        $rows = $this->export->rows($from, $to, $city);

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
}
