<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolve the number of rows a paged screen shows.
 *
 * The size is read from the query string so it survives a shared link and the
 * back button, like the filters it sits next to. It is matched against a
 * whitelist rather than clamped: `?per_page=500000` would otherwise hydrate the
 * whole register into memory on a single unauthenticated-shaped request, and a
 * clamp still lets a caller walk arbitrary sizes to probe the cost.
 */
class PageSize
{
    /** The sizes the selector offers, and the only ones honoured. */
    public const OPTIONS = [10, 25, 50, 100];

    public static function resolve(Request $request, int $default): int
    {
        $requested = $request->integer('per_page');

        return in_array($requested, self::OPTIONS, true) ? $requested : $default;
    }
}
