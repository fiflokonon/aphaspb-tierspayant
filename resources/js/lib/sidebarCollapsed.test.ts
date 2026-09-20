import { afterEach, describe, expect, it, vi } from 'vitest';
import { readCollapsed, writeCollapsed } from './sidebarCollapsed';

function stubStorage(impl: Partial<Storage>) {
    vi.stubGlobal('localStorage', impl as Storage);
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('sidebarCollapsed', () => {
    it('reads false when nothing was ever stored', () => {
        stubStorage({ getItem: () => null });

        expect(readCollapsed()).toBe(false);
    });

    it('reads true only for the exact stored marker', () => {
        stubStorage({ getItem: () => '1' });
        expect(readCollapsed()).toBe(true);

        stubStorage({ getItem: () => '0' });
        expect(readCollapsed()).toBe(false);

        stubStorage({ getItem: () => 'true' });
        expect(readCollapsed()).toBe(false);
    });

    it('opens expanded when the accessor throws', () => {
        // Navigation privée, données de site bloquées : l'accès lève. La barre
        // doit s'ouvrir déployée, pas planter la page.
        stubStorage({
            getItem: () => {
                throw new DOMException('denied');
            },
        });

        expect(readCollapsed()).toBe(false);
    });

    it('swallows a write that throws', () => {
        stubStorage({
            setItem: () => {
                throw new DOMException('quota');
            },
        });

        expect(() => writeCollapsed(true)).not.toThrow();
    });

    it('writes the marker the reader recognises', () => {
        // Les deux moitiés doivent s'accorder : un writer qui poserait 'true'
        // et un reader qui attend '1' passeraient chacun leur test isolément.
        const seen: Record<string, string> = {};
        stubStorage({
            setItem: (k: string, v: string) => {
                seen[k] = v;
            },
            getItem: (k: string) => seen[k] ?? null,
        });

        writeCollapsed(true);
        expect(readCollapsed()).toBe(true);

        writeCollapsed(false);
        expect(readCollapsed()).toBe(false);
    });
});
