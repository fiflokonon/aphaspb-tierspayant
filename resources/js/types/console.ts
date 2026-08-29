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

export type ConsoleAccount = {
    name: string;
    logoutHref: string;
};
