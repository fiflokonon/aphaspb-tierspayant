import { beforeEach, describe, expect, test } from 'vitest';
import { exportChartToPng } from './chartPng';
import type { LegendEntry } from './chartPng';

/**
 * Ce que l'export a réellement dessiné.
 *
 * chartPng n'importe rien : il ne s'appuie que sur des globales du navigateur.
 * Les bouchonner ici plutôt que de monter un jsdom rend visible ce dont le
 * module dépend vraiment, et laisse inspecter la géométrie produite — la seule
 * chose qui ait cassé, deux fois.
 */
type Drawn =
    | { op: 'text'; text: string; x: number; y: number }
    | { op: 'image'; x: number; y: number; width: number; height: number };

let drawn: Drawn[] = [];
let canvasSize = { width: 0, height: 0 };

/** Une largeur de texte plausible et surtout déterministe. */
const CHAR_WIDTH = 6.2;

function stubContext(): CanvasRenderingContext2D {
    return {
        font: '',
        textBaseline: '',
        fillStyle: '',
        strokeStyle: '',
        lineWidth: 0,
        lineCap: '',
        measureText: (text: string) => ({ width: text.length * CHAR_WIDTH }),
        save: () => {},
        restore: () => {},
        beginPath: () => {},
        moveTo: () => {},
        lineTo: () => {},
        stroke: () => {},
        fill: () => {},
        rect: () => {},
        roundRect: () => {},
        setLineDash: () => {},
        scale: () => {},
        fillRect: () => {},
        fillText: (text: string, x: number, y: number) =>
            drawn.push({ op: 'text', text, x, y }),
        drawImage: (
            _image: unknown,
            x: number,
            y: number,
            width: number,
            height: number,
        ) => drawn.push({ op: 'image', x, y, width, height }),
    } as unknown as CanvasRenderingContext2D;
}

function installDom(): void {
    const canvas = {
        set width(value: number) {
            canvasSize.width = value;
        },
        set height(value: number) {
            canvasSize.height = value;
        },
        getContext: () => stubContext(),
        toBlob: (callback: (blob: unknown) => void) => callback({ size: 1 }),
    };

    Object.assign(globalThis, {
        document: {
            createElement: (tag: string) =>
                tag === 'canvas'
                    ? canvas
                    : { style: {}, remove: () => {}, click: () => {} },
        },
        window: {
            getComputedStyle: () => ({
                color: 'rgb(31, 111, 74)',
                getPropertyValue: () => '',
            }),
        },
        XMLSerializer: class {
            serializeToString() {
                return '<svg/>';
            }
        },
        URL: { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} },
        Image: class {
            onload: (() => void) | null = null;

            set src(_value: string) {
                queueMicrotask(() => this.onload?.());
            }
        },
    });
}

function containerWith(width: number, height: number): HTMLElement {
    const svg = {
        getBoundingClientRect: () => ({ width, height }),
        cloneNode: () => ({
            setAttribute: () => {},
            getAttribute: () => `0 0 ${width} ${height}`,
            children: [],
        }),
        children: [],
    };

    return {
        querySelector: () => svg,
        appendChild: () => {},
    } as unknown as HTMLElement;
}

const THREE_INSURERS: LegendEntry[] = [
    {
        label: 'NSIA Assurances · 33 %',
        color: 'var(--officine)',
        shape: 'square',
    },
    {
        label: "L'Africaine des Assurances · 33 %",
        color: 'var(--gold-mid)',
        shape: 'square',
    },
    {
        label: 'Courtier — Ascoma Bénin · 33 %',
        color: 'var(--terracotta)',
        shape: 'square',
    },
];

const legendLines = () =>
    drawn.filter((one) => one.op === 'text' && one.text.includes('%'));

const image = () => drawn.find((one) => one.op === 'image');

beforeEach(() => {
    drawn = [];
    canvasSize = { width: 0, height: 0 };
    installDom();
});

describe('exportChartToPng', () => {
    test('the legend never runs over the chart, however far it wraps', async () => {
        // Un beignet contraint à 240 px : la légende se replie, et c'est
        // exactement là que les deux passes divergeaient.
        await exportChartToPng(containerWith(240, 200), {
            title: 'Encours par assureur',
            subtitle: 'Plan Starter',
            legend: THREE_INSURERS,
            filename: 'encours',
        });

        const chart = image();
        const lowest = Math.max(...legendLines().map((one) => one.y));

        expect(chart).toBeDefined();
        expect(lowest).toBeLessThan(chart!.y);
    });

    test('a chart narrower than its legend still gets a canvas wide enough', async () => {
        await exportChartToPng(containerWith(240, 200), {
            title: 'Encours par assureur',
            legend: THREE_INSURERS,
            filename: 'encours',
        });

        // Le canevas est rendu en 2×, d'où la division.
        expect(canvasSize.width / 2).toBeGreaterThanOrEqual(420);
    });

    test('the chart is centred when the canvas is wider than it', async () => {
        await exportChartToPng(containerWith(240, 200), {
            title: 'Encours par assureur',
            legend: THREE_INSURERS,
            filename: 'encours',
        });

        const chart = image()!;
        const rightGap = canvasSize.width / 2 - (chart.x + chart.width);

        expect(chart.x).toBeCloseTo(rightGap, 1);
    });

    test('a wide chart keeps its own width', async () => {
        await exportChartToPng(containerWith(760, 220), {
            title: 'Évolution du délai de paiement',
            legend: [{ label: 'Réseau', color: 'var(--officine)' }],
            filename: 'delais',
        });

        expect(image()!.width).toBe(760);
        expect(canvasSize.width / 2).toBeGreaterThanOrEqual(760);
    });

    test('a container holding no chart yet draws nothing', async () => {
        const empty = { querySelector: () => null } as unknown as HTMLElement;

        await expect(
            exportChartToPng(empty, { title: 'Vide', filename: 'vide' }),
        ).resolves.toBe(false);

        expect(drawn).toHaveLength(0);
    });
});
