/**
 * Turn a rendered chart into a PNG the officine can paste into a letter.
 *
 * Two things make this less trivial than serialising the <svg> and calling it
 * done:
 *
 * 1. Unovis paints with CSS custom properties (`var(--officine)`,
 *    `--vis-axis-tick-label-color`). A serialised SVG carries no stylesheet, so
 *    every one of those resolves to nothing and the export comes out black or
 *    transparent. Each painted property is therefore read back with
 *    getComputedStyle and written inline on the clone before serialisation.
 * 2. The legend and the title are HTML siblings of the <svg>, not part of it. A
 *    delay curve exported without its legend is three anonymous lines, so the
 *    caption is painted onto the canvas rather than dropped.
 *
 * No dependency: the file is a data URL, which keeps the canvas untainted and
 * toBlob() callable.
 */

/** The paint-bearing properties; anything else is layout the clone inherits. */
const PAINTED = [
    'fill',
    'fill-opacity',
    'stroke',
    'stroke-width',
    'stroke-dasharray',
    'stroke-linecap',
    'stroke-opacity',
    'opacity',
    'font-family',
    'font-size',
    'font-weight',
    'letter-spacing',
    'text-anchor',
    'display',
    'visibility',
] as const;

export type LegendEntry = {
    label: string;
    color: string;
    /** Drawn as a dashed rule, matching how the chart distinguishes it. */
    dashed?: boolean;
    /** Squares for areas and wedges, rules for lines — as on screen. */
    shape?: 'line' | 'square';
};

export type ChartPngOptions = {
    title: string;
    subtitle?: string;
    legend?: LegendEntry[];
    /** Without the .png suffix; the date is appended. */
    filename: string;
};

const SCALE = 2;
const PADDING = 28;
const BACKGROUND = '#fdfbf7';
const INK = '#17211c';
const FONT =
    'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

/**
 * Copy the computed paint of every node onto the matching node of the clone.
 *
 * The two trees are walked in lockstep rather than matched by selector: unovis
 * gives most of its nodes neither id nor stable class.
 */
function inlinePaintedStyles(source: Element, clone: Element): void {
    const computed = window.getComputedStyle(source);
    const declarations: string[] = [];

    for (const property of PAINTED) {
        const value = computed.getPropertyValue(property);

        if (value !== '' && value !== 'none' && value !== 'normal') {
            declarations.push(`${property}:${value}`);
        }
    }

    if (declarations.length > 0) {
        clone.setAttribute('style', declarations.join(';'));
    }

    const sourceChildren = source.children;
    const cloneChildren = clone.children;

    for (let index = 0; index < sourceChildren.length; index++) {
        const nextSource = sourceChildren[index];
        const nextClone = cloneChildren[index];

        if (nextSource !== undefined && nextClone !== undefined) {
            inlinePaintedStyles(nextSource, nextClone);
        }
    }
}

/** The chart as a standalone SVG string, plus the size it was drawn at. */
function serialise(svg: SVGSVGElement): {
    markup: string;
    width: number;
    height: number;
} {
    const box = svg.getBoundingClientRect();
    const width = Math.max(1, Math.round(box.width));
    const height = Math.max(1, Math.round(box.height));

    const clone = svg.cloneNode(true) as SVGSVGElement;

    inlinePaintedStyles(svg, clone);

    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    clone.setAttribute('width', String(width));
    clone.setAttribute('height', String(height));

    if (clone.getAttribute('viewBox') === null) {
        clone.setAttribute('viewBox', `0 0 ${width} ${height}`);
    }

    return {
        markup: new XMLSerializer().serializeToString(clone),
        width,
        height,
    };
}

/**
 * encodeURIComponent rather than btoa: the labels carry « é » and the narrow
 * no-break space of the FCFA formatter, and btoa throws on anything outside
 * Latin-1.
 */
function toImage(markup: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();

        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('SVG illisible'));
        image.src =
            'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
    });
}

/**
 * Turn a CSS colour into something the canvas understands.
 *
 * The palette is written in custom properties — `var(--officine)`,
 * `color-mix(in srgb, …)`. A canvas resolves neither: assigning one to
 * strokeStyle is silently ignored and the swatch stays the default black,
 * which is exactly how the exported legend lost its colours. The browser is
 * asked to compute the value instead, against an element inside the chart so
 * the custom properties are in scope.
 */
function resolveColor(value: string, host: HTMLElement): string {
    const probe = document.createElement('span');

    probe.style.color = value;
    probe.style.display = 'none';
    host.appendChild(probe);

    const resolved = window.getComputedStyle(probe).color;

    probe.remove();

    return resolved === '' ? value : resolved;
}

/** Legend swatches wrap, so the caption height is not known in advance. */
function drawLegend(
    context: CanvasRenderingContext2D,
    entries: LegendEntry[],
    top: number,
    maxWidth: number,
): number {
    const RULE = 18;
    const GAP = 7;
    const COLUMN_GAP = 18;
    const LINE = 20;

    context.font = `500 12px ${FONT}`;
    context.textBaseline = 'middle';

    let x = PADDING;
    let y = top;

    for (const entry of entries) {
        const width = RULE + GAP + context.measureText(entry.label).width;

        if (x > PADDING && x + width > PADDING + maxWidth) {
            x = PADDING;
            y += LINE;
        }

        context.save();

        if (entry.shape === 'square') {
            context.fillStyle = entry.color;
            context.beginPath();

            // roundRect est récent : une exception ici ferait échouer tout
            // l'export pour un coin arrondi.
            if (typeof context.roundRect === 'function') {
                context.roundRect(x + 3, y - 5, 11, 11, 2);
            } else {
                context.rect(x + 3, y - 5, 11, 11);
            }

            context.fill();
        } else {
            context.strokeStyle = entry.color;
            context.lineWidth = 3;
            context.lineCap = 'round';
            context.setLineDash(entry.dashed ? [5, 4] : []);
            context.beginPath();
            context.moveTo(x, y);
            context.lineTo(x + RULE, y);
            context.stroke();
        }

        context.restore();

        context.fillStyle = 'rgba(23, 33, 28, 0.62)';
        context.fillText(entry.label, x + RULE + GAP, y);

        x += width + COLUMN_GAP;
    }

    return y + LINE;
}

/** La plus large entrée de légende, pastille comprise. */
function widestLegendEntry(
    context: CanvasRenderingContext2D | null,
    entries: LegendEntry[],
): number {
    if (context === null || entries.length === 0) {
        return 0;
    }

    context.font = `500 12px ${FONT}`;

    return Math.max(
        ...entries.map((entry) => 25 + context.measureText(entry.label).width),
    );
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * Render `container`'s chart to a PNG and hand it to the browser's downloader.
 *
 * Returns false when the container holds no <svg> yet — a deferred prop that
 * has not landed, or a skeleton still on screen — so the caller can stay quiet
 * rather than surface an error the officine cannot act on.
 */
export async function exportChartToPng(
    container: HTMLElement | null,
    options: ChartPngOptions,
): Promise<boolean> {
    const svg = container?.querySelector('svg') ?? null;

    if (svg === null) {
        return false;
    }

    const { markup, width, height } = serialise(svg as SVGSVGElement);
    const image = await toImage(markup);

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (context === null) {
        return false;
    }

    // Résolues une fois, contre le conteneur du graphique : hors du DOM, une
    // variable CSS n'a plus de valeur.
    const legend = (options.legend ?? []).map((entry) => ({
        ...entry,
        color: resolveColor(entry.color, container as HTMLElement),
    }));

    const measure = document.createElement('canvas').getContext('2d');

    const headerHeight = options.subtitle === undefined ? 34 : 54;

    // Une seule origine pour la mesure et pour le tracé. Mesurer à un endroit
    // et dessiner à un autre faisait couler la légende sur le graphique.
    const legendTop = headerHeight + 10;

    // Le graphique dicte sa largeur, mais un camembert contraint à 240 px
    // laisserait la légende se replier sur cinq lignes et déborder. On prend
    // donc la plus large des deux, avec un plancher lisible et un plafond qui
    // évite une bande démesurée.
    const contentWidth = Math.min(
        900,
        Math.max(width, 420, widestLegendEntry(measure, legend)),
    );

    const captionHeight =
        legend.length === 0 || measure === null
            ? headerHeight
            : drawLegend(measure, legend, legendTop, contentWidth) + 6;

    const totalWidth = contentWidth + PADDING * 2;
    const totalHeight = captionHeight + height + PADDING + 34;

    canvas.width = totalWidth * SCALE;
    canvas.height = totalHeight * SCALE;
    context.scale(SCALE, SCALE);

    // An SVG has no background of its own; without this the PNG is transparent
    // and unreadable pasted into a document.
    context.fillStyle = BACKGROUND;
    context.fillRect(0, 0, totalWidth, totalHeight);

    context.textBaseline = 'alphabetic';
    context.fillStyle = INK;
    context.font = `700 17px ${FONT}`;
    context.fillText(options.title, PADDING, PADDING + 4);

    if (options.subtitle !== undefined) {
        context.fillStyle = 'rgba(23, 33, 28, 0.55)';
        context.font = `400 12px ${FONT}`;
        context.fillText(options.subtitle, PADDING, PADDING + 24);
    }

    if (legend.length > 0) {
        drawLegend(context, legend, legendTop, contentWidth);
    }

    // Centré : le graphique est souvent plus étroit que la légende.
    context.drawImage(
        image,
        PADDING + (contentWidth - width) / 2,
        captionHeight,
        width,
        height,
    );

    context.textBaseline = 'alphabetic';
    context.fillStyle = 'rgba(23, 33, 28, 0.4)';
    context.font = `400 10px ${FONT}`;
    context.fillText(
        `APHASPB · Tiers payant — ${today()}`,
        PADDING,
        totalHeight - PADDING + 12,
    );

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/png'),
    );

    if (blob === null) {
        return false;
    }

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = `${options.filename}-${today()}.png`;
    link.click();

    URL.revokeObjectURL(url);

    return true;
}
