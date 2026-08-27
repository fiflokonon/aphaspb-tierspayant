<?php

namespace App\Support;

/**
 * Render an FCFA amount for display.
 *
 * The thousands separator is a narrow no-break space (U+202F), not an ordinary
 * one: with a breakable space the browser splits « 5 303 000 » across two
 * lines, which reads as two numbers. The TypeScript side uses the same
 * character in resources/js/lib/fcfa.ts.
 */
class Fcfa
{
    public const THIN_NBSP = "\u{202F}";

    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', self::THIN_NBSP);
    }
}
