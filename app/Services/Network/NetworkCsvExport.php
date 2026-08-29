<?php

namespace App\Services\Network;

use App\Data\Period;

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

    public function __construct(protected NetworkExportRows $source)
    {
        //
    }

    /**
     * The rows of the file, header first, every value already a string.
     *
     * @return iterable<int, list<string>>
     */
    public function rows(Period $from, Period $to, ?string $city = null): iterable
    {
        yield self::COLUMNS;

        foreach ($this->source->rows($from, $to, $city) as $row) {
            yield array_map($this->render(...), $row);
        }
    }

    /**
     * Comma decimals: the file is read in a French Excel, not by a parser.
     */
    protected function render(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_float($value)
            ? str_replace('.', ',', (string) $value)
            : (string) $value;
    }
}
