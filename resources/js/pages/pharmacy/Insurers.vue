<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InsurerChecklist from '@/components/aphaspb/InsurerChecklist.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Insurer = {
    id: number;
    name: string;
    isActive: boolean;
    declarations: number;
    url: string;
};

const props = defineProps<{
    insurers: Insurer[];
    selected: number[];
}>();

const selected = ref<number[]>([...props.selected]);
const other = ref('');

const count = computed(
    () => selected.value.length + (other.value.trim() ? 1 : 0),
);

/**
 * Les assureurs sur lesquels il y a quelque chose à lire.
 *
 * Le lien vit dans un bloc à part plutôt que sur les noms de la liste à
 * cocher : InsurerChecklist sert aussi l'onboarding, où cette page n'existe
 * pas encore pour l'officine et où la route n'aurait rien à montrer.
 */
const withHistory = computed(() =>
    props.insurers.filter((insurer) => insurer.declarations > 0),
);

/** Insurers being untied that carry a history — the only untying worth a word. */
const losing = computed(() =>
    props.insurers.filter(
        (insurer) =>
            insurer.declarations > 0 &&
            props.selected.includes(insurer.id) &&
            !selected.value.includes(insurer.id),
    ),
);
</script>

<template>
    <Head title="Mes assureurs" />

    <div class="insurers-page">
        <ConsoleHeader title="Mes assureurs" class="insurers-header" />

        <div class="insurers-layout">
            <Form
                action="/pharmacy/insurers"
                method="patch"
                class="insurers-card"
                #default="{ errors, processing }"
            >
                <div class="card-top-line"></div>

                <div class="card-header">
                    <div>
                        <span class="card-eyebrow"> ASSUREURS </span>

                        <h2>Avec quels assureurs travaillez-vous ?</h2>

                        <p>
                            Ce sont les assureurs qui vous seront proposés
                            chaque mois. Vous pouvez modifier cette sélection à
                            tout moment.
                        </p>
                    </div>

                    <div class="selection-counter">
                        <span class="counter-number">
                            {{ count }}
                        </span>

                        <span class="counter-label">
                            sélectionné{{ count > 1 ? 's' : '' }}
                        </span>
                    </div>
                </div>

                <div class="checklist-container">
                    <InsurerChecklist
                        v-model:selected="selected"
                        v-model:other="other"
                        :insurers="insurers"
                    >
                        <template #heading>
                            <div class="checklist-heading">
                                <span class="checklist-dot"></span>

                                <span> Sélectionnez vos assureurs </span>
                            </div>
                        </template>
                    </InsurerChecklist>
                </div>

                <div v-if="withHistory.length > 0" class="history-links">
                    <span class="history-links-label">
                        Consulter le détail par assureur
                    </span>

                    <Link
                        v-for="insurer in withHistory"
                        :key="insurer.id"
                        :href="insurer.url"
                        class="history-link"
                    >
                        {{ insurer.name }}
                    </Link>
                </div>

                <div class="form-footer">
                    <div v-if="errors.insurers" class="message message-error">
                        <div class="message-icon">!</div>

                        <p>
                            {{ errors.insurers }}
                        </p>
                    </div>

                    <div
                        v-else-if="losing.length"
                        class="message message-warning"
                    >
                        <div class="message-icon">i</div>

                        <p>
                            {{ losing.length }} assureur{{
                                losing.length > 1 ? 's' : ''
                            }}
                            {{ losing.length > 1 ? 'perdront' : 'perdra' }}
                            sa place dans la déclaration mensuelle. Vos
                            déclarations passées restent dans votre historique.
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="processing || count === 0"
                        class="save-button"
                    >
                        <span class="save-icon"> ✓ </span>

                        <span>
                            Enregistrer · {{ count }} assureur{{
                                count > 1 ? 's' : ''
                            }}
                        </span>

                        <span class="save-arrow"> → </span>
                    </button>

                    <p class="form-hint">
                        Votre sélection sera utilisée pour vos prochaines
                        déclarations mensuelles.
                    </p>
                </div>
            </Form>

            <aside class="info-card">
                <div class="info-icon">✓</div>

                <div>
                    <span class="info-label"> À RETENIR </span>

                    <h3>Une sélection simple</h3>

                    <p>
                        Vous pouvez ajouter ou retirer un assureur à tout
                        moment. Les déclarations déjà enregistrées ne seront pas
                        modifiées.
                    </p>
                </div>

                <div class="info-line"></div>

                <div class="info-status">
                    <span class="status-dot"></span>
                    <span> Vos données restent dans votre espace </span>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.history-links {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
}

.history-links-label {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    opacity: 0.5;
}

.history-link {
    padding: 5px 11px;
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 600;
}

.insurers-page {
    /* La palette vient de :root — voir resources/css/app.css. */

    position: relative;
    min-height: 100%;
    padding-bottom: 60px;

    animation: pageAppear 0.5s ease both;
}

.insurers-header {
    position: relative;
    z-index: 2;
}

.insurers-layout {
    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        270px;

    align-items: start;

    gap: 18px;
}

.insurers-card {
    position: relative;

    overflow: hidden;

    border-radius: var(--radius-card);

    background: #ffffff;

    box-shadow: var(--surface-shadow);

    animation: cardAppear 0.6s ease both;
}

.card-top-line {
    position: absolute;

    left: 0;
    top: 0;

    width: 100%;
    height: 3px;

    background: var(--primary);

    opacity: 0.9;
}

.card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;

    gap: 20px;

    padding: 25px 26px 21px;

    border-bottom: 1px solid var(--border);
}

.card-eyebrow {
    display: block;

    margin-bottom: 6px;

    color: var(--primary);

    font-size: 9.5px;
    font-weight: 800;

    letter-spacing: 0.14em;
}

.card-header h2 {
    color: var(--ink);

    font-size: 17px;
    line-height: 1.25;

    font-weight: 750;
}

.card-header p {
    max-width: 620px;

    margin-top: 7px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
    line-height: 1.5;
}

.selection-counter {
    display: flex;
    align-items: center;
    justify-content: center;

    min-width: 82px;

    padding: 9px 12px;

    border: 1px solid color-mix(in srgb, var(--officine) 10%, transparent);
    border-radius: 12px;

    background: var(--primary-soft);

    flex-direction: column;

    flex-shrink: 0;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.selection-counter:hover {
    transform: translateY(-2px);

    box-shadow: 0 7px 16px color-mix(in srgb, var(--officine) 8%, transparent);
}

.counter-number {
    color: var(--primary-dark);

    font-size: 18px;
    line-height: 1;

    font-weight: 800;
}

.counter-label {
    margin-top: 4px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 9.5px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: 0.14em;
}

.checklist-container {
    padding: 4px 5px;

    background: #fff;
}

.checklist-heading {
    display: flex;
    align-items: center;

    gap: 8px;

    margin-bottom: 4px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 9.5px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: 0.14em;
}

.checklist-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--gold-mid);

    box-shadow: 0 0 0 4px var(--gold-soft);
}

.form-footer {
    padding: 18px 24px 21px;

    border-top: 1px solid var(--border);

    background: var(--cream-state);
}

.message {
    display: flex;
    align-items: flex-start;

    gap: 9px;

    margin-bottom: 12px;

    padding: 10px 12px;

    border-radius: var(--radius-card);

    font-size: 14px;
    line-height: 1.45;
}

.message-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 20px;
    height: 20px;

    flex-shrink: 0;

    border-radius: 50%;

    font-size: 10px;
    font-weight: 800;
}

.message p {
    padding-top: 2px;
}

.message-error {
    box-shadow: var(--surface-shadow);

    background: color-mix(in srgb, var(--terracotta) 6%, transparent);

    color: var(--terracotta-dark);
}

.message-error .message-icon {
    background: var(--terracotta);
    color: white;
}

.message-warning {
    box-shadow: var(--surface-shadow);

    background: var(--gold-soft);

    color: var(--ink);
}

.message-warning .message-icon {
    background: var(--gold-mid);
    color: white;
}

.save-button {
    position: relative;

    display: flex;
    align-items: center;
    justify-content: center;

    gap: 9px;

    width: 100%;
    height: 52px;

    overflow: hidden;

    border: 0;
    border-radius: 12px;

    background: var(--primary);

    color: #ffffff;

    font-size: 13px;
    font-weight: 750;

    cursor: pointer;

    box-shadow: 0 7px 18px color-mix(in srgb, var(--officine) 16%, transparent);

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        opacity 0.25s ease;
}

/*
  Même raison que le balayage de Declare.vue : sans son dégradé, ce
  pseudo-élément n'a plus rien à montrer.
*/

.save-button:hover:not(:disabled) {
    transform: translateY(-2px);

    box-shadow: 0 11px 24px color-mix(in srgb, var(--officine) 21%, transparent);
}

.save-button:hover:not(:disabled)::before {
    left: 120%;
}

.save-button:active:not(:disabled) {
    transform: translateY(0);
}

.save-button:disabled {
    opacity: 0.48;
    cursor: not-allowed;
    box-shadow: none;
}

.save-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 22px;
    height: 22px;

    border-radius: 7px;

    background: rgba(255, 255, 255, 0.14);

    font-size: 11px;
}

.save-arrow {
    margin-left: 2px;

    font-size: 15px;

    opacity: 0.75;

    transition: transform 0.25s ease;
}

.save-button:hover:not(:disabled) .save-arrow {
    transform: translateX(4px);
}

.form-hint {
    margin-top: 9px;

    color: color-mix(in srgb, var(--ink) 38%, transparent);

    font-size: 12.5px;
    line-height: 1.45;

    text-align: center;
}

.info-card {
    position: relative;

    padding: 20px;

    overflow: hidden;

    border-radius: var(--radius-card);

    background: #fff;

    box-shadow: var(--surface-shadow);

    animation: sideAppear 0.65s ease 0.1s both;
}

.info-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 38px;
    height: 38px;

    margin-bottom: 15px;

    border-radius: 11px;

    background: var(--gold-soft);

    color: var(--gold-mid);

    font-size: 15px;
    font-weight: 800;
}

.info-label {
    display: block;

    margin-bottom: 5px;

    color: var(--gold-mid);

    font-size: 9.5px;
    font-weight: 800;

    letter-spacing: 0.14em;
}

.info-card h3 {
    color: var(--ink);

    font-size: 14px;
    font-weight: 750;
}

.info-card p {
    margin-top: 7px;

    color: color-mix(in srgb, var(--ink) 55%, transparent);

    font-size: 12.5px;
    line-height: 1.55;
}

.info-line {
    height: 1px;

    margin: 17px 0;

    background: var(--border);
}

.info-status {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: flex-start;

    gap: 8px;

    color: var(--primary-dark);

    font-size: 12.5px;
    font-weight: 650;
    line-height: 1.4;
}

.status-dot {
    width: 6px;
    height: 6px;

    flex-shrink: 0;

    margin-top: 4px;

    border-radius: 50%;

    background: var(--primary);

    box-shadow: 0 0 0 4px color-mix(in srgb, var(--officine) 8%, transparent);
}

@keyframes pageAppear {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

@keyframes cardAppear {
    from {
        opacity: 0;
        transform: translateY(13px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes sideAppear {
    from {
        opacity: 0;
        transform: translateX(8px);
    }

    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@media (max-width: 900px) {
    .insurers-layout {
        grid-template-columns: 1fr;
    }

    .info-card {
        display: grid;

        grid-template-columns: auto 1fr;

        column-gap: 13px;

        align-items: start;
    }

    .info-icon {
        grid-row: span 2;

        margin-bottom: 0;
    }

    .info-line {
        grid-column: 1 / -1;

        margin: 14px 0;
    }

    .info-status {
        grid-column: 1 / -1;
    }
}

@media (max-width: 700px) {
    .card-header {
        padding: 21px 18px 18px;
    }
}

@media (max-width: 560px) {
    .insurers-page {
        padding-bottom: 80px;
    }

    .card-header {
        flex-direction: column;

        gap: 14px;
    }

    .selection-counter {
        flex-direction: row;

        gap: 7px;

        width: auto;
        min-width: 0;

        align-self: flex-start;
    }

    .counter-label {
        margin-top: 0;
    }

    .form-footer {
        padding: 16px;
    }

    .save-button {
        height: 50px;

        font-size: 12px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .insurers-page *,
    .insurers-page *::before,
    .insurers-page *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
