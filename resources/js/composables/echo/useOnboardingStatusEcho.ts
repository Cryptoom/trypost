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
 *
 * Early-return when residual is false is safe: every path to false is terminal
 * (completed, dismissed, non-owner, or no app access). A future non-terminal
 * false would need this guard revisited.
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
