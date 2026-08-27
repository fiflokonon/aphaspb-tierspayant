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

export type DataTableRowTone = 'default' | 'alert' | 'muted';

export const rowToneClass: Record<DataTableRowTone, string> = {
    default: '',
    alert: 'bg-terracotta/[0.04]',
    muted: 'bg-cream-state',
};
