<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InsurerChecklist from '@/components/aphaspb/InsurerChecklist.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

const props = defineProps<{
    insurers: { id: number; name: string }[];
    selected: number[];
    withDeclarations: number[];
}>();

const selected = ref<number[]>([...props.selected]);
const other = ref('');

const count = computed(
    () => selected.value.length + (other.value.trim() ? 1 : 0),
);

const losing = computed(() =>
    props.withDeclarations.filter((id) => !selected.value.includes(id)),
);
</script>



<template>
    <Head title="Mes assureurs" />

    <div class="insurers-page">
  
        <ConsoleHeader
            eyebrow="MON OFFICINE"
            title="Mes assureurs"
            class="insurers-header"
        />

        <section class="insurers-intro">
            <div class="intro-glow"></div>

            <div class="intro-content">
                <div class="intro-icon">
                    <span>◈</span>
                </div>

                <div class="intro-text">
                    <span class="intro-label">
                        CONFIGURATION DU RÉSEAU
                    </span>

                    <h1>
                        Vos assureurs partenaires
                    </h1>

                    <p>
                        Sélectionnez les assureurs avec lesquels votre officine
                        travaille. Ils vous seront proposés lors de vos
                        déclarations mensuelles.
                    </p>
                </div>
            </div>

            <div class="intro-badge">
                <span class="badge-dot"></span>
                <span>Configuration active</span>
            </div>
        </section>

  
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
                        <span class="card-eyebrow">
                            ASSUREURS
                        </span>

                        <h2>
                            Avec quels assureurs travaillez-vous ?
                        </h2>

                        <p>
                            Ce sont les assureurs qui vous seront proposés
                            chaque mois. Vous pouvez modifier cette sélection
                            à tout moment.
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
                        :with-declarations="withDeclarations"
                    >
                        <template #heading>
                  
                            <div class="checklist-heading">
                                <span class="checklist-dot"></span>

                                <span>
                                    Sélectionnez vos assureurs
                                </span>
                            </div>
                        </template>
                    </InsurerChecklist>
                </div>

           
                <div class="form-footer">
               
                    <div
                        v-if="errors.insurers"
                        class="message message-error"
                    >
                        <div class="message-icon">
                            !
                        </div>

                        <p>
                            {{ errors.insurers }}
                        </p>
                    </div>

  
                    <div
                        v-else-if="losing.length"
                        class="message message-warning"
                    >
                        <div class="message-icon">
                            i
                        </div>

                        <p>
                            {{ losing.length }} assureur{{
                                losing.length > 1 ? 's' : ''
                            }}
                            {{ losing.length > 1 ? 'perdront' : 'perdra' }}
                            sa place dans la déclaration mensuelle.
                            Vos déclarations passées restent dans votre
                            historique.
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="processing || count === 0"
                        class="save-button"
                    >
                        <span class="save-icon">
                            ✓
                        </span>

                        <span>
                            Enregistrer · {{ count }} assureur{{
                                count > 1 ? 's' : ''
                            }}
                        </span>

                        <span class="save-arrow">
                            →
                        </span>
                    </button>

                    <p class="form-hint">
                        Votre sélection sera utilisée pour vos prochaines
                        déclarations mensuelles.
                    </p>
                </div>
            </Form>

            <aside class="info-card">
                <div class="info-icon">
                    ✓
                </div>

                <div>
                    <span class="info-label">
                        À RETENIR
                    </span>

                    <h3>
                        Une sélection simple
                    </h3>

                    <p>
                        Vous pouvez ajouter ou retirer un assureur à tout
                        moment. Les déclarations déjà enregistrées ne seront
                        pas modifiées.
                    </p>
                </div>

                <div class="info-line"></div>

                <div class="info-status">
                    <span class="status-dot"></span>
                    <span>
                        Vos données restent dans votre espace
                    </span>
                </div>
            </aside>
        </div>
    </div>
</template>

<style>

.insurers-page {
    --apha-primary: #008f83;
    --apha-primary-dark: #006f68;
    --apha-primary-soft: #e8f6f3;

    --apha-gold: #d7a33d;
    --apha-gold-soft: #fff8e9;

    --apha-ink: #243333;
    --apha-muted: #788585;
    --apha-light: #a2adad;

    --apha-border: #e7eceb;
    --apha-background: #f7f9f9;

    position: relative;
    min-height: 100%;
    padding-bottom: 60px;

    /* background:
        radial-gradient(
            circle at 95% 0%,
            rgba(0, 143, 131, 0.045),
            transparent 28%
        ),
        radial-gradient(
            circle at 5% 35%,
            rgba(215, 163, 61, 0.025),
            transparent 25%
        ); */

    animation: pageAppear 0.5s ease both;
}



.insurers-header {
    position: relative;
    z-index: 2;
}



.insurers-intro {
    position: relative;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 24px;

    margin-top: 16px;
    margin-bottom: 22px;

    padding: 23px 25px;

    overflow: hidden;

    border: 1px solid var(--apha-border);
    border-radius: 18px;

    background:
        linear-gradient(
            110deg,
            #ffffff 0%,
            #f9fcfb 100%
        );

    /* box-shadow:
        0 8px 30px rgba(35, 70, 68, 0.035); */

    animation: introAppear 0.55s ease both;
}

.intro-glow {
    position: absolute;

    right: -70px;
    top: -90px;

    width: 210px;
    height: 210px;

    border-radius: 50%;

    background:
        radial-gradient(
            circle,
            rgba(0, 143, 131, 0.09),
            transparent 68%
        );

    pointer-events: none;
}

.intro-content {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: center;

    gap: 16px;
}

.intro-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 50px;
    height: 50px;

    flex-shrink: 0;

    border-radius: 15px;

    color: #ffffff;

    background:
        linear-gradient(
            135deg,
            var(--apha-primary),
            var(--apha-primary-dark)
        );

    box-shadow:
        0 8px 20px rgba(0, 143, 131, 0.18);

    animation: iconFloat 3s ease-in-out infinite;
}

.intro-icon span {
    font-size: 20px;
}

.intro-label {
    display: block;

    margin-bottom: 4px;

    color: var(--apha-primary);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.13em;
}

.intro-text h1 {
    color: var(--apha-ink);

    font-size: 20px;
    line-height: 1.2;

    font-weight: 750;

    letter-spacing: -0.025em;
}

.intro-text p {
    max-width: 680px;

    margin-top: 5px;

    color: var(--apha-muted);

    font-size: 11px;
    line-height: 1.5;
}

.intro-badge {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: center;

    gap: 8px;

    flex-shrink: 0;

    padding: 8px 12px;

    border-radius: 30px;

    background: var(--apha-primary-soft);

    color: var(--apha-primary-dark);

    font-size: 10px;
    font-weight: 700;

    white-space: nowrap;
}

.badge-dot {
    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow:
        0 0 0 4px rgba(0, 143, 131, 0.08);

    animation: statusPulse 2.2s infinite;
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

    border: 1px solid var(--apha-border);
    border-radius: 18px;

    background: #ffffff;

    box-shadow:
        0 8px 30px rgba(35, 70, 68, 0.035);

    animation: cardAppear 0.6s ease both;
}

.card-top-line {
    position: absolute;

    left: 0;
    top: 0;

    width: 100%;
    height: 3px;

    background:
        linear-gradient(
            90deg,
            var(--apha-primary),
            #35a799,
            var(--apha-gold)
        );

    opacity: 0.9;
}


.card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;

    gap: 20px;

    padding: 25px 26px 21px;

    border-bottom: 1px solid var(--apha-border);
}

.card-eyebrow {
    display: block;

    margin-bottom: 6px;

    color: var(--apha-primary);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: 0.12em;
}

.card-header h2 {
    color: var(--apha-ink);

    font-size: 18px;
    line-height: 1.25;

    font-weight: 750;
}

.card-header p {
    max-width: 620px;

    margin-top: 7px;

    color: var(--apha-muted);

    font-size: 11px;
    line-height: 1.5;
}

.selection-counter {
    display: flex;
    align-items: center;
    justify-content: center;

    min-width: 82px;

    padding: 9px 12px;

    border: 1px solid rgba(0, 143, 131, 0.1);
    border-radius: 12px;

    background: var(--apha-primary-soft);

    flex-direction: column;

    flex-shrink: 0;

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease;
}

.selection-counter:hover {
    transform: translateY(-2px);

    box-shadow:
        0 7px 16px rgba(0, 143, 131, 0.08);
}

.counter-number {
    color: var(--apha-primary-dark);

    font-size: 18px;
    line-height: 1;

    font-weight: 800;
}

.counter-label {
    margin-top: 4px;

    color: var(--apha-muted);

    font-size: 8px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: 0.06em;
}


.checklist-container {
    padding: 4px 5px;

    background:
        linear-gradient(
            180deg,
            #ffffff 0%,
            #fcfdfd 100%
        );
}

.checklist-heading {
    display: flex;
    align-items: center;

    gap: 8px;

    margin-bottom: 4px;

    color: var(--apha-muted);

    font-size: 10px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.checklist-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: var(--apha-gold);

    box-shadow:
        0 0 0 4px var(--apha-gold-soft);
}



.form-footer {
    padding: 18px 24px 21px;

    border-top: 1px solid var(--apha-border);

    background:
        linear-gradient(
            180deg,
            #fbfcfc 0%,
            #f8faf9 100%
        );
}



.message {
    display: flex;
    align-items: flex-start;

    gap: 9px;

    margin-bottom: 12px;

    padding: 10px 12px;

    border-radius: 11px;

    font-size: 10.5px;
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
    border: 1px solid rgba(197, 82, 69, 0.18);

    background: rgba(197, 82, 69, 0.06);

    color: #9f4035;
}

.message-error .message-icon {
    background: #c55245;
    color: white;
}

.message-warning {
    border: 1px solid rgba(215, 163, 61, 0.18);

    background: var(--apha-gold-soft);

    color: var(--apha-ink);
}

.message-warning .message-icon {
    background: var(--apha-gold);
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

    background:
        linear-gradient(
            135deg,
            var(--apha-primary),
            var(--apha-primary-dark)
        );

    color: #ffffff;

    font-size: 13px;
    font-weight: 750;

    cursor: pointer;

    box-shadow:
        0 7px 18px rgba(0, 143, 131, 0.16);

    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        opacity 0.25s ease;
}

.save-button::before {
    content: "";

    position: absolute;

    top: 0;
    left: -100%;

    width: 60%;
    height: 100%;

    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(255, 255, 255, 0.12),
            transparent
        );

    transition: left 0.55s ease;
}

.save-button:hover:not(:disabled) {
    transform: translateY(-2px);

    box-shadow:
        0 11px 24px rgba(0, 143, 131, 0.21);
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

    transition:
        transform 0.25s ease;
}

.save-button:hover:not(:disabled) .save-arrow {
    transform: translateX(4px);
}

.form-hint {
    margin-top: 9px;

    color: var(--apha-light);

    font-size: 9.5px;
    line-height: 1.45;

    text-align: center;
}



.info-card {
    position: relative;

    padding: 20px;

    overflow: hidden;

    border: 1px solid var(--apha-border);
    border-radius: 16px;

    background:
        linear-gradient(
            145deg,
            #ffffff,
            #f8fbfa
        );

    box-shadow:
        0 8px 26px rgba(35, 70, 68, 0.03);

    animation:
        sideAppear 0.65s ease 0.1s both;
}

.info-card::after {
    content: "";

    position: absolute;

    right: -45px;
    bottom: -50px;

    width: 130px;
    height: 130px;

    border-radius: 50%;

    background:
        radial-gradient(
            circle,
            rgba(215, 163, 61, 0.07),
            transparent 70%
        );

    pointer-events: none;
}

.info-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 38px;
    height: 38px;

    margin-bottom: 15px;

    border-radius: 11px;

    background: var(--apha-gold-soft);

    color: var(--apha-gold);

    font-size: 15px;
    font-weight: 800;
}

.info-label {
    display: block;

    margin-bottom: 5px;

    color: var(--apha-gold);

    font-size: 8.5px;
    font-weight: 800;

    letter-spacing: 0.12em;
}

.info-card h3 {
    color: var(--apha-ink);

    font-size: 14px;
    font-weight: 750;
}

.info-card p {
    margin-top: 7px;

    color: var(--apha-muted);

    font-size: 10.5px;
    line-height: 1.55;
}

.info-line {
    height: 1px;

    margin: 17px 0;

    background:
        linear-gradient(
            90deg,
            var(--apha-border),
            transparent
        );
}

.info-status {
    position: relative;
    z-index: 1;

    display: flex;
    align-items: flex-start;

    gap: 8px;

    color: var(--apha-primary-dark);

    font-size: 9.5px;
    font-weight: 650;
    line-height: 1.4;
}

.status-dot {
    width: 6px;
    height: 6px;

    flex-shrink: 0;

    margin-top: 4px;

    border-radius: 50%;

    background: var(--apha-primary);

    box-shadow:
        0 0 0 4px rgba(0, 143, 131, 0.08);
}



@keyframes pageAppear {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

@keyframes introAppear {
    from {
        opacity: 0;
        transform: translateY(9px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
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

@keyframes iconFloat {
    0%,
    100% {
        transform: translateY(0);
    }

    50% {
        transform: translateY(-3px);
    }
}

@keyframes statusPulse {
    0% {
        box-shadow:
            0 0 0 0 rgba(0, 143, 131, 0.25);
    }

    70% {
        box-shadow:
            0 0 0 5px rgba(0, 143, 131, 0);
    }

    100% {
        box-shadow:
            0 0 0 0 rgba(0, 143, 131, 0);
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
    .insurers-intro {
        align-items: flex-start;

        flex-direction: column;

        padding: 18px;
    }

    .intro-badge {
        align-self: flex-start;
    }

    .card-header {
        padding: 21px 18px 18px;
    }
}

@media (max-width: 560px) {
    .insurers-page {
        padding-bottom: 80px;
    }

    .intro-content {
        align-items: flex-start;
    }

    .intro-icon {
        width: 42px;
        height: 42px;

        border-radius: 12px;
    }

    .intro-text h1 {
        font-size: 17px;
    }

    .intro-text p {
        font-size: 10px;
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

@media (max-width: 400px) {
    .insurers-intro {
        border-radius: 14px;
    }

    .insurers-card {
        border-radius: 14px;
    }

    .info-card {
        border-radius: 14px;
    }

    .intro-icon {
        width: 39px;
        height: 39px;
    }

    .intro-text h1 {
        font-size: 16px;
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
