<?php

namespace App\Services\Network;

use App\Data\Period;
use App\Services\Exports\CsvRenderer;

/**
 * The network statistics as a CSV a French Excel opens without a wizard.
 *
 * Only a renderer: which insurers get figures, and which are withheld, is
 * decided once in NetworkExportRows and shared with every other format.
 */
class NetworkCsvExport
{
    /** @var list<string> */
    public const COLUMNS = NetworkExportRows::COLUMNS;

    public function __construct(
        protected NetworkExportRows $source,
        protected CsvRenderer $renderer,
    ) {
        //
    }

    /**
     * The rows of the file, header first, every value already a string.
     *
     * @return iterable<int, list<string>>
     */
    public function rows(Period $from, Period $to, ?string $city = null): iterable
    {
        return $this->renderer->render(self::COLUMNS, $this->source->rows($from, $to, $city));
    }
}
