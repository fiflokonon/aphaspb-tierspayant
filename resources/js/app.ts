import { createInertiaApp } from '@inertiajs/vue3';
import ConsoleShellLayout from '@/layouts/ConsoleShellLayout.vue';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('onboarding/'):
                return OnboardingLayout;
            // Every other page runs the console shell: admin or officine, the
            // difference lives entirely in the descriptor the server builds.
            default:
                return ConsoleShellLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();
