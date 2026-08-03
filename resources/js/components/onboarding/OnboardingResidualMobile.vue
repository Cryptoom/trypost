<script setup lang="ts">
import { Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconChevronRight, IconListCheck } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import { useSidebar } from '@/components/ui/sidebar';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import { onboarding } from '@/routes/app';
import type { OnboardingResidual } from '@/types';

// Mobile counterpart of SidebarOnboarding: the sidebar sheet unmounts on
// phones, so the residual strip lives in the app layout instead. Only active
// on mobile — on desktop the sidebar instance owns Echo/poll.
const page = usePage();
const { isMobile, openMobile } = useSidebar();

const residual = computed<OnboardingResidual | false>(
    () => page.props.onboardingResidual,
);

const visible = computed(
    () =>
        isMobile.value &&
        !openMobile.value &&
        residual.value !== false &&
        page.component !== 'onboarding/Index',
);

useWorkspaceEcho('.onboarding.status.updated', () => {
    if (!visible.value) {
        return;
    }

    router.reload({ only: ['onboardingResidual'] });
});

const { start, stop } = usePoll(
    30000,
    { only: ['onboardingResidual'] },
    { autoStart: false },
);

watch(
    visible,
    (on) => {
        if (on) {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);
</script>

<template>
    <div
        v-if="visible && residual !== false"
        class="shrink-0 px-4 pt-3 md:hidden"
    >
        <Link
            :href="onboarding.url()"
            class="flex items-center gap-2 rounded-lg border-2 border-foreground bg-amber-100 p-2.5 ps-12 shadow-2xs"
            data-testid="sidebar-onboarding-mobile"
        >
            <span
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border-2 border-foreground bg-card shadow-2xs"
            >
                <IconListCheck
                    class="size-4 text-amber-800"
                    stroke-width="2.5"
                />
            </span>
            <span
                class="min-w-0 flex-1 truncate text-sm font-bold text-foreground"
            >
                {{ $t('sidebar.onboarding') }}
            </span>
            <span
                class="shrink-0 rounded-full border-2 border-foreground bg-card px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
                role="progressbar"
                :aria-label="$t('sidebar.onboarding')"
                :aria-valuemin="0"
                :aria-valuemax="residual.total"
                :aria-valuenow="residual.completed"
            >
                {{ residual.completed }}/{{ residual.total }}
            </span>
            <IconChevronRight class="size-4 shrink-0 text-foreground/70" />
        </Link>
    </div>
</template>
