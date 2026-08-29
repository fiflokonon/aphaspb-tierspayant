<?php

namespace App\Services\Network;

use App\Data\Period;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The same rows as the CSV, in a workbook a spreadsheet can compute on.
 *
 * The point of the .xlsx over the CSV is typing: an amount arrives as a number,
 * so a column totals without a conversion step. Which insurers get figures is
 * not decided here — NetworkExportRows owns that, and both formats obey it.
 */
class NetworkXlsxExport
{
    /** Wide enough for « L'Africaine des Assurances » without truncation. */
    protected const NAME_WIDTH = 32.0;

    protected const FIGURE_WIDTH = 16.0;

    public function __construct(protected NetworkExportRows $source)
    {
        //
    }

    /**
     * Write the workbook to $path, overwriting whatever is there.
     */
    public function writeTo(string $path, Period $from, Period $to, ?string $city = null): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Réseau');
        $sheet->setColumnWidth(self::NAME_WIDTH, 1);
        $sheet->setColumnWidth(self::FIGURE_WIDTH, ...range(2, count(NetworkExportRows::COLUMNS)));

        $writer->addRow(Row::fromValuesWithStyle(
            NetworkExportRows::COLUMNS,
            (new Style)->withFontBold(true),
        ));

        foreach ($this->source->rows($from, $to, $city) as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }
}
