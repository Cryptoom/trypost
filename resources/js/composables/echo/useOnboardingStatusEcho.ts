import { router, usePage, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';

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
 * Echo is the fast path; a slow poll covers Reverb outages. Polling only runs
 * while the banner is visible so completed/dismissed owners are not hit.
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

    const { start, stop } = usePoll(
        10000,
        {
            only: ['onboardingResidual'],
        },
        {
            autoStart: false,
        },
    );

    watch(
        () =>
            [
                page.props.onboardingResidual,
                page.component,
            ] as const,
        ([residual, component]) => {
            if (residual === false || component === 'onboarding/Index') {
                stop();

                return;
            }

            start();
        },
        { immediate: true },
    );
};
