import { createInertiaApp } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import PharmacyLayout from '@/layouts/PharmacyLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
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
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('pharmacy/'):
                return PharmacyLayout;
            case name.startsWith('settings/'):
            case name.startsWith('pharmacies/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();
