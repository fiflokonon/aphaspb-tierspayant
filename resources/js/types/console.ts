export type ConsoleNavItem = {
    label: string;
    href: string;
    active: boolean;
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

export type ConsoleAccount = {
    name: string;
    logoutHref: string;
    pharmacies: ConsoleSwitchablePharmacy[];
};
