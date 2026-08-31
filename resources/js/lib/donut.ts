/**
 * Rank the wedges of a distribution and give each one its colour.
 *
 * Shared rather than kept inside OutstandingDonutChart: the PNG export paints
 * its own caption from the same list, and a legend computed twice is a legend
 * that eventually disagrees with the drawing.
 */

/**
 * Past six wedges the eye stops separating them and the palette runs out of
 * distinguishable hues, so the tail is summed into « Autres » rather than drawn
 * as a fringe of unreadable slivers.
 */
export const VISIBLE_SLICES = 6;

const COLORS = [
    'var(--officine)',
    'var(--gold-mid)',
    'var(--terracotta)',
    'var(--officine-dark)',
    'var(--terracotta-dark)',
    'color-mix(in srgb, var(--gold-mid) 55%, white)',
];

const REMAINDER_COLOR = 'rgb(23 33 28 / 0.28)';

export type Slice = { label: string; value: number };

export type RankedSlice = Slice & { color: string; share: number };

export function rankSlices(slices: Slice[]): RankedSlice[] {
    const ranked = slices
        .filter((slice) => slice.value > 0)
        .sort((a, b) => b.value - a.value);

    const head = ranked.slice(0, VISIBLE_SLICES);
    const tail = ranked.slice(VISIBLE_SLICES);

    const kept: Slice[] =
        tail.length === 0
            ? head
            : [
                  ...head,
                  {
                      label: 'Autres',
                      value: tail.reduce((sum, slice) => sum + slice.value, 0),
                  },
              ];

    const total = kept.reduce((sum, slice) => sum + slice.value, 0);

    return kept.map((slice, index) => ({
        ...slice,
        color:
            index >= VISIBLE_SLICES
                ? REMAINDER_COLOR
                : (COLORS[index] ?? REMAINDER_COLOR),
        share: total === 0 ? 0 : Math.round((slice.value / total) * 100),
    }));
}

export function sliceTotal(slices: RankedSlice[]): number {
    return slices.reduce((sum, slice) => sum + slice.value, 0);
}
