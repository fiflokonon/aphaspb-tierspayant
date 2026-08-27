import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import type { ConsoleNavItem, ConsoleNotice } from '@/types/console';

export type ConsoleShell = {
    space: string | null;
    nav: ConsoleNavItem[];
    notices: ConsoleNotice[];
};

export type UseConsoleShellReturn = {
    space: ComputedRef<string | null>;
    nav: ComputedRef<ConsoleNavItem[]>;
    notices: ComputedRef<ConsoleNotice[]>;
};

/**
 * Read the console shell descriptor the server shares.
 *
 * Navigation and notices are built in PHP so route names and active state live
 * in one place. The layout only renders what it is handed, and tolerates the
 * prop being absent — a page rendered outside a profile still mounts.
 */
export function useConsoleShell(): UseConsoleShellReturn {
    const page = usePage();
    const shell = computed(
        () => (page.props.console ?? null) as ConsoleShell | null,
    );

    return {
        space: computed(() => shell.value?.space ?? null),
        nav: computed(() => shell.value?.nav ?? []),
        notices: computed(() => shell.value?.notices ?? []),
    };
}
