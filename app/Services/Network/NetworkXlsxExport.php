<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Services\Exports\XlsxWriter;

/**
 * The same rows as the CSV, in a workbook a spreadsheet can compute on.
 *
 * The point of the .xlsx over the CSV is typing: an amount arrives as a number,
 * so a column totals without a conversion step. Which insurers get figures is
 * not decided here — NetworkExportRows owns that, and both formats obey it.
 */
class NetworkXlsxExport
{
    public function __construct(
        protected NetworkExportRows $source,
        protected XlsxWriter $writer,
    ) {
        //
    }

    /**
     * Write the workbook to $path, overwriting whatever is there.
     */
    public function writeTo(string $path, Period $from, Period $to, ?string $city = null): void
    {
        $this->writer->write(
            $path,
            'Réseau',
            NetworkExportRows::COLUMNS,
            $this->source->rows($from, $to, $city),
        );
    }
}
