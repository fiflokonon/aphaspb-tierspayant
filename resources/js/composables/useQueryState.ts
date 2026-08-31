import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * A preference kept in the query string without asking the server for anything.
 *
 * Chart type and — on the trends screen — the insurer filter change nothing the
 * server computes: every series is already in the payload. Routing them through
 * router.get() would spend a round trip to redraw data the browser is holding.
 *
 * replaceState is handed the existing history state rather than null: Inertia
 * stores its page object there, and dropping it breaks the back button.
 */
export function useQueryState<T extends string>(
    key: string,
    fallback: T,
    accepts: (value: string) => value is T,
): Ref<T> {
    const initial = read(key);
    const state = ref(
        initial !== null && accepts(initial) ? initial : fallback,
    ) as Ref<T>;

    watch(state, (value) => write(key, value === fallback ? null : value));

    return state;
}

/** The same, for a filter whose value is an id rather than a keyword. */
export function useQueryId(key: string): Ref<number | null> {
    const initial = Number(read(key));
    const state = ref<number | null>(
        Number.isInteger(initial) && initial > 0 ? initial : null,
    );

    watch(state, (value) => write(key, value === null ? null : String(value)));

    return state;
}

function read(key: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return new URL(window.location.href).searchParams.get(key);
}

function write(key: string, value: string | null): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (value === null) {
        url.searchParams.delete(key);
    } else {
        url.searchParams.set(key, value);
    }

    window.history.replaceState(window.history.state, '', url);
}
