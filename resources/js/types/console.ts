export type ConsoleNavItem = {
    label: string;
    href: string;
    active: boolean;
    /** Clé d'icône, traduite en composant par `@/lib/navIcons`. */
    icon: string;
};

export type ConsoleNoticeTone = 'gold' | 'neutral';

export type ConsoleNotice = {
    tone: ConsoleNoticeTone;
    title: string;
    body: string;
};

export type ConsoleSwitchablePharmacy = {
    name: string;
    slug: string;
    switchHref: string;
    current: boolean;
};

export type ConsoleCurrentPharmacy = {
    name: string;
    city: string | null;
};

export type ConsoleAccount = {
    name: string;
    logoutHref: string;
    /** L'officine sur laquelle la session est ouverte ; null dans l'espace réseau. */
    pharmacy: ConsoleCurrentPharmacy | null;
    pharmacies: ConsoleSwitchablePharmacy[];
};
