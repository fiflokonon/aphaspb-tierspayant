export type ConsoleNavItem = {
    label: string;
    href: string;
    active: boolean;
    /** Clé d'icône, traduite en composant par `@/lib/navIcons`. */
    icon: string;
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
    /** Vrai dans l'espace réseau ; c'est l'espace qui le dit, pas le rôle. */
    administrator: boolean;
    logoutHref: string;
    /** L'officine sur laquelle la session est ouverte ; null dans l'espace réseau. */
    pharmacy: ConsoleCurrentPharmacy | null;
    pharmacies: ConsoleSwitchablePharmacy[];
};
