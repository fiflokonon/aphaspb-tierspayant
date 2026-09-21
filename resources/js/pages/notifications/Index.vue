<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BellOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Pagination from '@/components/aphaspb/Pagination.vue';
import ConsoleHeader from '@/layouts/console/ConsoleHeader.vue';

type Item = {
    id: string;
    kind: 'notification' | 'invitation';
    title: string;
    body: string;
    href: string | null;
    tone: 'neutral' | 'warn' | 'alert';
    createdAt: string;
    readAt: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    notifications: {
        items: Paginated<Item>;
        unread: number;
        perPage: number;
    };
    pageSizes: number[];
}>();

const perPage = ref(props.notifications.perPage);

function reload(page: number) {
    router.get(
        '/notifications',
        { per_page: perPage.value, page },
        {
            only: ['notifications'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(perPage, () => reload(1));

/**
 * Une invitation n'a pas d'état lu : elle est en attente ou elle a disparu.
 * Seules les notifications se marquent, et une seule à la fois — le compteur
 * dit « il reste ceci à voir », pas « il y a du nouveau ».
 */
function markAsRead(item: Item) {
    if (item.kind !== 'notification' || item.readAt !== null) {
        return;
    }

    router.patch(
        `/notifications/${item.id}`,
        {},
        { preserveScroll: true, only: ['notifications'] },
    );
}

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

const when = (iso: string) =>
    iso === '' ? '' : dateFormatter.format(new Date(iso));

const toneClass: Record<Item['tone'], string> = {
    neutral: 'bg-ink/[0.35]',
    warn: 'bg-gold-mid',
    alert: 'bg-terracotta',
};

const empty = computed(() => props.notifications.items.total === 0);
</script>

<template>
    <Head title="Notifications" />

    <div class="notifications-page">
        <ConsoleHeader
            eyebrow="CE QUI VOUS ATTEND"
            :title="
                notifications.unread === 0
                    ? 'Notifications'
                    : `Notifications · ${notifications.unread} en attente`
            "
        />

        <section class="notifications-card">
            <div v-if="empty" class="empty-state">
                <BellOff class="empty-icon" :stroke-width="1.5" />

                <p class="empty-title">Rien à signaler</p>

                <p class="empty-body">
                    Les récapitulatifs de retard de paiement et les invitations
                    apparaîtront ici.
                </p>
            </div>

            <ul v-else class="notification-list">
                <li
                    v-for="item in notifications.items.data"
                    :key="item.id"
                    class="notification-row"
                    :class="{ unread: item.readAt === null }"
                >
                    <span class="tone-dot" :class="toneClass[item.tone]" />

                    <div class="row-body">
                        <component
                            :is="item.href === null ? 'div' : Link"
                            v-bind="
                                item.href === null ? {} : { href: item.href }
                            "
                            class="row-title"
                            @click="markAsRead(item)"
                        >
                            {{ item.title }}
                        </component>

                        <p class="row-text">{{ item.body }}</p>

                        <div class="row-meta">
                            <span>{{ when(item.createdAt) }}</span>

                            <span v-if="item.kind === 'invitation'" class="tag">
                                Invitation
                            </span>

                            <button
                                v-else-if="item.readAt === null"
                                type="button"
                                class="mark-read"
                                @click="markAsRead(item)"
                            >
                                Marquer comme lue
                            </button>
                        </div>
                    </div>
                </li>
            </ul>

            <Pagination
                :page="notifications.items.current_page"
                :last-page="notifications.items.last_page"
                :from="notifications.items.from"
                :to="notifications.items.to"
                :total="notifications.items.total"
                noun="notification"
                :per-page="notifications.perPage"
                :page-sizes="pageSizes"
                @update:page="reload"
                @update:per-page="perPage = $event"
            />
        </section>
    </div>
</template>

<style scoped>
.notifications-page {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.notifications-card {
    border-radius: var(--radius-card);
    background: #fff;
    padding: 16px;
    box-shadow: var(--surface-shadow);
}

.notification-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.notification-row {
    display: flex;
    gap: 11px;
    align-items: flex-start;

    border-radius: 10px;
    padding: 12px 11px;

    transition: background-color 0.15s ease;
}

.notification-row:hover {
    background: color-mix(in srgb, var(--ink) 3%, transparent);
}

/* Le non-lu se signale par un fond, pas par une graisse : la liste mélange des
   titres de longueurs très différentes et le gras les fait sauter. */
.notification-row.unread {
    background: color-mix(in srgb, var(--officine) 4.5%, transparent);
}

.tone-dot {
    margin-top: 6px;
    height: 8px;
    width: 8px;
    flex-shrink: 0;
    border-radius: 9999px;
}

.row-body {
    min-width: 0;
    flex: 1;
}

.row-title {
    display: block;

    font-size: 13.5px;
    font-weight: 600;
    line-height: 1.35;
    color: var(--ink);

    text-decoration: none;
}

a.row-title:hover {
    text-decoration: underline;
}

.row-text {
    margin-top: 3px;

    font-size: 12.5px;
    line-height: 1.45;
    color: color-mix(in srgb, var(--ink) 62%, transparent);
}

.row-meta {
    margin-top: 6px;

    display: flex;
    align-items: center;
    gap: 10px;

    font-size: 11px;
    color: color-mix(in srgb, var(--ink) 45%, transparent);
}

.tag {
    border-radius: 5px;
    background: color-mix(in srgb, var(--ink) 7%, transparent);
    padding: 2px 6px;

    font-size: 10px;
    font-weight: 600;
}

.mark-read {
    font-size: 11px;
    font-weight: 600;
    color: color-mix(in srgb, var(--officine) 90%, transparent);
}

.mark-read:hover {
    text-decoration: underline;
}

.empty-state {
    padding: 44px 16px;
    text-align: center;
}

.empty-icon {
    display: block;
    margin: 0 auto;

    height: 28px;
    width: 28px;

    color: color-mix(in srgb, var(--ink) 25%, transparent);
}

.empty-title {
    margin-top: 10px;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
}

.empty-body {
    margin: 6px auto 0;
    max-width: 340px;
    font-size: 12.5px;
    line-height: 1.5;
    color: color-mix(in srgb, var(--ink) 55%, transparent);
}
</style>
