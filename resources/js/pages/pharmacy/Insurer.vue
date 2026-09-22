<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, Download } from '@lucide/vue';
import { ref, watch } from 'vue';
import DataTable from '@/components/aphaspb/DataTable.vue';
import DataTableRow from '@/components/aphaspb/DataTableRow.vue';
import FilterSelect from '@/components/aphaspb/FilterSelect.vue';
import KpiCard from '@/components/aphaspb/KpiCard.vue';
import KpiRow from '@/components/aphaspb/KpiRow.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';
import { formatAmount } from '@/lib/fcfa';
import { formatMillions } from '@/lib/millions';

type ExportFormat = 'pdf' | 'xlsx' | 'csv';

type Relationship = {
    insurerId: number;
    insurerName: string;
    standardDelayDays: number;
    penaltyTriggerDays: number | null;
    penaltyRatePercent: number | null;
    declarations: number;
    invoiced: number;
    received: number;
    outstanding: number;
    recoveryRate: number | null;
    weightedDelayDays: number | null;
    longestDelayDays: number | null;
    penalty: number | null;
};

type MonthRow = {
    id: number;
    monthLabel: string;
    statusLabel: string;
    invoiced: number;
    received: number;
    outstanding: number;
    depositedOn: string | null;
    paidOn: string | null;
    delayDays: number | null;
    instalments: number;
    penalty: number | null;
    editUrl: string;
};

const props = defineProps<{
    relationship: Relationship;
    months: MonthRow[];
    period: string;
    periodLabel: string;
    periods: { value: string; label: string }[];
    exportUrls: Record<ExportFormat, string> | null;
}>();

const period = ref<string | number | null>(props.period);

/**
 * La période ne change que les chiffres : un rechargement partiel évite de
 * refaire la navigation et garde la position de lecture.
 */
watch(period, (chosen) => {
    router.get(
        window.location.pathname,
        { period: chosen },
        {
            only: [
                'relationship',
                'months',
                'period',
                'periodLabel',
                'exportUrls',
            ],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
});

/**
 * Les mêmes formats, dans le même ordre et avec les mêmes mots que l'écran
 * d'export : le relevé à joindre d'abord, le fichier brut en dernier.
 */
const EXPORT_FORMATS = [
    { key: 'pdf', badge: 'PDF', lede: 'Relevé mis en page' },
    { key: 'xlsx', badge: 'XLSX', lede: 'Classeur Excel' },
    { key: 'csv', badge: 'CSV', lede: 'Fichier brut' },
] as const satisfies readonly {
    key: ExportFormat;
    badge: string;
    lede: string;
}[];

const TEMPLATE = '.9fr .9fr 1fr 1fr 1fr .9fr .9fr .7fr .9fr';
const COLUMNS = [
    'MOIS',
    'STATUT',
    'FACTURÉ',
    'ENCAISSÉ',
    'RESTE DÛ',
    'DÉPÔT',
    'DERNIER VERS.',
    'DÉLAI',
    'PÉNALITÉ',
];
</script>

<template>
    <div class="insurer-page">
        <Head :title="relationship.insurerName" />

        <ConsoleHeader :title="relationship.insurerName">
            <template #filters>
                <FilterSelect
                    v-model="period"
                    :options="periods"
                    aria-label="Période"
                />
            </template>

            <template #action>
                <!--
                    Un menu plutôt que trois boutons dans l'en-tête : le geste
                    reste « exporter », et le format est un détail qu'on choisit
                    une fois dedans. Les trois liens portent déjà la période et
                    l'assureur affichés — c'est ce qu'on regarde qui part dans
                    le fichier.
                -->
                <DropdownMenu v-if="exportUrls">
                    <DropdownMenuTrigger class="export-link">
                        <Download :size="15" />

                        Exporter

                        <ChevronDown :size="14" class="export-chevron" />
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end" class="w-60">
                        <DropdownMenuLabel class="export-menu-title">
                            {{ periodLabel }} · {{ relationship.insurerName }}
                        </DropdownMenuLabel>

                        <DropdownMenuSeparator />

                        <DropdownMenuItem
                            v-for="format in EXPORT_FORMATS"
                            :key="format.key"
                            as-child
                        >
                            <a
                                :href="exportUrls[format.key]"
                                class="export-format"
                            >
                                <span class="export-format-badge">
                                    {{ format.badge }}
                                </span>

                                <span class="export-format-lede">
                                    {{ format.lede }}
                                </span>
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </ConsoleHeader>

        <!--
            La convention est rappelée en tête parce que sans elle la colonne
            pénalité est un nombre tombé du ciel. Lecture seule : c'est
            l'APhaSPB qui la renseigne, assureur par assureur.
        -->
        <section class="convention">
            <p class="convention-terms">
                Délai de remboursement convenu :
                <strong>{{ relationship.standardDelayDays }} jours</strong>.

                <template
                    v-if="
                        relationship.penaltyTriggerDays !== null &&
                        relationship.penaltyRatePercent !== null
                    "
                >
                    Pénalité à partir de
                    <strong>{{ relationship.penaltyTriggerDays }} jours</strong
                    >,
                    <strong
                        >{{
                            relationship.penaltyRatePercent.toLocaleString(
                                'fr-FR',
                            )
                        }}
                        %</strong
                    >
                    par tranche de 30 jours.
                </template>

                <template v-else>
                    Aucune clause de pénalité enregistrée pour cet assureur.
                </template>
            </p>

            <p class="convention-source">
                Renseigné par l'APhaSPB d'après la convention signée ·
                {{ periodLabel }}
            </p>
        </section>

        <KpiRow :columns="3">
            <KpiCard
                label="FACTURÉ"
                :value="formatMillions(relationship.invoiced)"
                unit="FCFA"
                :hint="`${relationship.declarations} déclarations sur la période`"
            />

            <KpiCard
                label="ENCAISSÉ"
                :value="formatMillions(relationship.received)"
                unit="FCFA"
                :hint="
                    relationship.recoveryRate === null
                        ? 'rien de déclaré'
                        : `${relationship.recoveryRate.toLocaleString('fr-FR')} % recouvrés`
                "
            />

            <KpiCard
                label="RESTE DÛ"
                :value="formatMillions(relationship.outstanding)"
                unit="FCFA"
                hint="sur la période retenue"
            />

            <KpiCard
                label="DÉLAI LE PLUS LONG"
                :value="relationship.longestDelayDays?.toString() ?? '—'"
                unit="jours"
                hint="mois réglés et encours confondus"
            />
        </KpiRow>

        <KpiRow :columns="3">
            <KpiCard
                label="VOTRE DÉLAI MOYEN"
                :value="
                    relationship.weightedDelayDays?.toLocaleString('fr-FR') ??
                    '—'
                "
                unit="jours"
                hint="pondéré par les montants reçus"
            />

            <!--
                formatAmount() rend « — » sur null : « pas de clause » et « une
                clause mais rien à réclamer » ne doivent pas se lire pareil.
            -->
            <KpiCard
                label="PÉNALITÉ RÉCLAMABLE"
                :value="
                    relationship.penalty === null
                        ? '—'
                        : formatMillions(relationship.penalty)
                "
                unit="FCFA"
                hint="mois soldés en retard compris"
            />
        </KpiRow>

        <DataTable title="" :columns="COLUMNS" :template="TEMPLATE">
            <DataTableRow
                v-for="row in months"
                :key="row.id"
                :template="TEMPLATE"
            >
                <div>
                    <Link :href="row.editUrl" class="month-link">
                        {{ row.monthLabel }}
                    </Link>
                </div>

                <div>{{ row.statusLabel }}</div>

                <div>{{ formatAmount(row.invoiced) }}</div>

                <div>{{ formatAmount(row.received) }}</div>

                <div>{{ formatAmount(row.outstanding) }}</div>

                <div>{{ row.depositedOn ?? '—' }}</div>

                <div
                    :title="
                        row.instalments > 1
                            ? `Réglée en ${row.instalments} versements`
                            : undefined
                    "
                >
                    {{ row.paidOn ?? '—' }}
                </div>

                <div>
                    {{ row.delayDays === null ? '—' : `${row.delayDays} j` }}
                </div>

                <div>{{ formatAmount(row.penalty) }}</div>
            </DataTableRow>
        </DataTable>

        <p v-if="months.length === 0" class="empty">
            Aucune déclaration pour cet assureur sur {{ periodLabel }}.
        </p>
    </div>
</template>

<style scoped>
.insurer-page {
    /*
      --muted est ici une couleur de TEXTE. Le thème réserve --muted à une
      surface (#faf8f3) : la retirer rendrait ce texte presque blanc.
    */
    --muted: color-mix(in srgb, var(--ink) 55%, transparent);

    position: relative;
    min-height: 100vh;
    padding-bottom: 45px;
    color: var(--ink);
}

.convention {
    margin-top: 18px;
    padding: 13px 16px;
    border-radius: var(--radius-card);
    box-shadow: var(--surface-shadow);
    background: #fff;
}

.convention-terms {
    font-size: 12.5px;
    line-height: 1.5;
}

.convention-source {
    margin-top: 4px;
    font-size: 12.5px;
    color: var(--muted);
}

.export-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 13px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fff;
    font-size: 11.5px;
    font-weight: 700;
}

.export-chevron {
    margin-left: 1px;
    opacity: 0.45;
}

.export-menu-title {
    font-size: 11px;
    font-weight: 600;
    color: var(--muted);
}

.export-format {
    display: flex;
    align-items: center;
    gap: 9px;
    width: 100%;
    cursor: pointer;
}

/*
  Le badge porte le format, le reste dit à quoi il sert : « XLSX » seul se lit
  comme du jargon, « Classeur Excel » seul ne dit pas ce qu'on télécharge.
*/
.export-format-badge {
    flex-shrink: 0;
    min-width: 38px;
    padding: 2px 6px;
    border-radius: 5px;
    background: var(--cream-state);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 9.5px;
    font-weight: 700;
    text-align: center;
    color: var(--ink);
}

.export-format-lede {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ink);
}

.month-link {
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.empty {
    padding: 32px 0;
    font-size: 12.5px;
    color: var(--muted);
    text-align: center;
}
</style>
