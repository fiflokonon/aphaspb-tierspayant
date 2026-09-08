<?php

namespace App\Services\Exports;

/**
 * Turn typed rows into the strings a French Excel opens without a wizard.
 *
 * Extracted from NetworkCsvExport when the officine exports arrived: two copies
 * of the decimal-comma rule would eventually disagree, and the day they did,
 * one of the two files would silently become unreadable in Excel.
 */
class CsvRenderer
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<int, list<string|int|float|null>>  $rows
     * @return iterable<int, list<string>>
     */
    public function render(array $columns, iterable $rows): iterable
    {
        yield $columns;

        foreach ($rows as $row) {
            yield array_map($this->cell(...), $row);
        }
    }

    /**
     * Comma decimals: the file is read in a French Excel, not by a parser.
     */
    protected function cell(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_float($value)
            ? str_replace('.', ',', (string) $value)
            : (string) $value;
    }
}
