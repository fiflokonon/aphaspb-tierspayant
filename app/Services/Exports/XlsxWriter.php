<?php

namespace App\Services\Exports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Write typed rows to a workbook a spreadsheet can compute on.
 *
 * The point of the .xlsx over the CSV is typing: an amount arrives as a number,
 * so a column totals without a conversion step. Which rows get written is never
 * decided here — the callers own that, and both the network file and the
 * officine's own come through this same writer.
 */
class XlsxWriter
{
    /** Wide enough for « L'Africaine des Assurances » without truncation. */
    protected const NAME_WIDTH = 32.0;

    protected const FIGURE_WIDTH = 16.0;

    /**
     * @param  list<string>  $columns
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public function write(string $path, string $sheetName, array $columns, iterable $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName($sheetName);
        $sheet->setColumnWidth(self::NAME_WIDTH, 1);

        // Column by column rather than range(): with a single-column file
        // range(2, 1) counts back down and would widen column 1 twice, undoing
        // the name width set just above.
        for ($column = 2; $column <= count($columns); $column++) {
            $sheet->setColumnWidth(self::FIGURE_WIDTH, $column);
        }

        $writer->addRow(Row::fromValuesWithStyle($columns, (new Style)->withFontBold(true)));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }
}
