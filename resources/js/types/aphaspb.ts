import { ChartColumn, ChartLine, ChartPie } from '@lucide/vue';
import type { Component } from 'vue';

export type KpiTone = 'neutral' | 'good' | 'warn' | 'bad';

export const kpiToneClass: Record<KpiTone, string> = {
    neutral: 'text-ink',
    good: 'text-officine',
    warn: 'text-gold-dark',
    bad: 'text-terracotta-dark',
};

export const kpiToneFill: Record<KpiTone, string> = {
    neutral: 'bg-ink',
    good: 'bg-officine',
    warn: 'bg-gold-mid',
    bad: 'bg-terracotta',
};

export type DeclarationStatus = 'paid' | 'partial' | 'unpaid' | 'rejected';

export const statusChipClass: Record<DeclarationStatus, string> = {
    paid: 'text-officine bg-officine/[0.12]',
    partial: 'text-gold-dark bg-gold/[0.18]',
    unpaid: 'text-ink/60 bg-ink/[0.07]',
    rejected: 'text-terracotta-dark bg-terracotta/[0.12]',
};

/**
 * A month the officine may declare: the current one and the twelve before it.
 *
 * Built server-side by DeclarationCalendar, url included, so no screen has to
 * assemble a query string of its own.
 */
export type SelectablePeriod = {
    year: number;
    month: number;
    label: string;
    isComplete: boolean;
    isCurrent: boolean;
    url: string;
};

export type DataTableRowTone = 'default' | 'alert' | 'muted';

export const rowToneClass: Record<DataTableRowTone, string> = {
    default: '',
    alert: 'bg-terracotta/[0.04]',
    muted: 'bg-cream-state',
};

/** How a chart block draws its figures, chosen by the reader. */
export type ChartType = 'bar' | 'line' | 'pie';

export const chartTypeLabel: Record<ChartType, string> = {
    bar: 'Barres',
    line: 'Courbes',
    pie: 'Camembert',
};

/**
 * Des SVG et non des caractères : la police de l'app, « Plus Jakarta Sans »,
 * ne couvre pas le bloc Geometric Shapes, et le repli système ne le couvre
 * pas partout — les glyphes ◔ / ▥ / ◕ s'affichaient vides.
 */
export const chartTypeIcon: Record<ChartType, Component> = {
    bar: ChartColumn,
    line: ChartLine,
    pie: ChartPie,
};

/**
 * The narrow palette the charts share: green, gold, terracotta.
 *
 * Past three series colour alone stops separating them, which is why the line
 * chart dashes the fourth onward rather than reaching for more hues.
 */
export const CHART_COLORS = [
    'var(--officine)',
    'var(--gold-mid)',
    'var(--terracotta)',
] as const;

export function isChartType(value: unknown): value is ChartType {
    return value === 'bar' || value === 'line' || value === 'pie';
}
