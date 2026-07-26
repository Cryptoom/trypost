import { router, usePage } from '@inertiajs/vue3';

import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';

/**
 * Refresh the given Inertia props when onboarding checklist progress changes.
 */
export const useOnboardingStatusEcho = (only: string[]): void => {
    useWorkspaceEcho('.onboarding.status.updated', () => {
        router.reload({ only });
    });
};

/**
 * Keep the sidebar residual banner in sync while it is visible.
 * Skips on the onboarding page — that page already reloads residual with status.
 */
export const useOnboardingResidualEcho = (): void => {
    const page = usePage();

    useWorkspaceEcho('.onboarding.status.updated', () => {
        if (page.props.onboardingResidual === false) {
            return;
        }

        if (page.component === 'onboarding/Index') {
            return;
        }

        router.reload({ only: ['onboardingResidual'] });
    });
};
