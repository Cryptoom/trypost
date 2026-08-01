<script setup lang="ts">
import { Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { IconListCheck, IconX } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { onboarding } from '@/routes/app';
import { dismiss } from '@/routes/app/onboarding';
import type { OnboardingResidual } from '@/types';

const page = usePage();
const { urlIsActive } = useActiveUrl();

const residual = computed<OnboardingResidual | false>(
    () => page.props.onboardingResidual,
);

const progress = computed(() => {
    if (residual.value === false) {
        return 0;
    }

    const { completed, total } = residual.value;

    return total > 0 ? Math.round((completed / total) * 100) : 0;
});

// Echo is the fast path; slow poll covers Reverb outages while the banner shows.
// Skip on the onboarding page — that page already reloads residual with status.
useWorkspaceEcho('.onboarding.status.updated', () => {
    if (
        page.props.onboardingResidual === false ||
        page.component === 'onboarding/Index'
    ) {
        return;
    }

    router.reload({ only: ['onboardingResidual'] });
});

const { start, stop } = usePoll(
    10000,
    { only: ['onboardingResidual'] },
    { autoStart: false },
);

watch(
    () => [page.props.onboardingResidual, page.component] as const,
    ([current, component]) => {
        if (current === false || component === 'onboarding/Index') {
            stop();

            return;
        }

        start();
    },
    { immediate: true },
);

// `stay` keeps the user on the current page — only the onboarding page Skip
// leaves for the calendar.
const dismissForm = useForm({ stay: true });

const dismissResidual = (): void => {
    if (!dismissForm.processing) {
        dismissForm.submit(dismiss());
    }
};
</script>

<template>
    <div
        v-if="residual !== false"
        class="px-1 pb-1 group-data-[collapsible=icon]:hidden"
    >
        <div
            :class="[
                'rounded-lg border-2 border-foreground p-3 shadow-2xs transition-colors',
                urlIsActive(onboarding.url())
                    ? 'bg-amber-200'
                    : 'bg-amber-100 hover:bg-amber-200',
            ]"
        >
            <div class="flex items-start justify-between gap-2">
                <Link
                    :href="onboarding.url()"
                    dusk="sidebar-onboarding"
                    class="flex min-w-0 flex-1 items-center gap-2"
                >
                    <span
                        class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border-2 border-foreground bg-card shadow-2xs"
                    >
                        <IconListCheck
                            class="size-4 text-amber-800"
                            stroke-width="2.5"
                        />
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block truncate text-sm font-bold text-foreground"
                        >
                            {{ $t('sidebar.onboarding') }}
                        </span>
                        <span class="block truncate text-xs text-foreground/70">
                            {{ $t('sidebar.onboarding_hint') }}
                        </span>
                    </span>
                </Link>
                <div class="flex shrink-0 items-center gap-1.5">
                    <span
                        class="rounded-full border-2 border-foreground bg-card px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
                    >
                        {{ residual.completed }}/{{ residual.total }}
                    </span>
                    <button
                        type="button"
                        class="inline-flex size-5 items-center justify-center rounded-full border-2 border-foreground bg-card text-foreground/70 transition-colors hover:text-foreground"
                        :aria-label="$t('onboarding.skip')"
                        :disabled="dismissForm.processing"
                        dusk="sidebar-onboarding-dismiss"
                        @click="dismissResidual"
                    >
                        <IconX class="size-3" stroke-width="2.5" />
                    </button>
                </div>
            </div>
            <Link
                :href="onboarding.url()"
                tabindex="-1"
                class="mt-2.5 block"
                :aria-hidden="true"
            >
                <div
                    class="h-1.5 overflow-hidden rounded-full border border-foreground bg-card"
                    role="progressbar"
                    :aria-valuenow="residual.completed"
                    :aria-valuemin="0"
                    :aria-valuemax="residual.total"
                >
                    <div
                        class="h-full bg-foreground transition-[width]"
                        :style="{ width: `${progress}%` }"
                    />
                </div>
            </Link>
        </div>
    </div>
</template>
