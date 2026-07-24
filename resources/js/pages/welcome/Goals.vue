<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconCalendar,
    IconChartBar,
    IconCheck,
    IconClock,
    IconCoin,
    IconCompass,
    IconDots,
    IconPalette,
    IconRobot,
    IconSparkles,
    IconTrendingUp,
    IconUsers,
    IconUsersGroup,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/goals';

const props = defineProps<{
    goals: string[];
    selected?: string[] | null;
}>();

const EXCLUSIVE_GOAL = 'just_exploring';

const form = useForm<{ goals: string[] }>({ goals: props.selected ?? [] });

const goalMeta: Record<string, { icon: FunctionalComponent; color: string }> = {
    save_time: { icon: IconClock, color: 'text-amber-600' },
    ai_content: { icon: IconSparkles, color: 'text-violet-700' },
    plan_calendar: { icon: IconCalendar, color: 'text-blue-700' },
    stay_on_brand: { icon: IconPalette, color: 'text-orange-600' },
    grow_audience: { icon: IconTrendingUp, color: 'text-rose-600' },
    drive_sales: { icon: IconCoin, color: 'text-emerald-600' },
    manage_clients: { icon: IconUsersGroup, color: 'text-cyan-600' },
    team_collaboration: { icon: IconUsers, color: 'text-fuchsia-600' },
    automate_api: { icon: IconRobot, color: 'text-teal-600' },
    track_performance: { icon: IconChartBar, color: 'text-indigo-600' },
    just_exploring: { icon: IconCompass, color: 'text-sky-600' },
    other: { icon: IconDots, color: 'text-foreground' },
};

const goalIcon = (value: string): FunctionalComponent =>
    goalMeta[value]?.icon ?? IconDots;

const goalColor = (value: string): string =>
    goalMeta[value]?.color ?? 'text-foreground';

const goalLabel = (value: string): string => trans(`welcome.goals.${value}`);

const isSelected = (value: string): boolean => form.goals.includes(value);

const toggle = (value: string): void => {
    if (value === EXCLUSIVE_GOAL) {
        form.goals = isSelected(value) ? [] : [value];

        return;
    }

    const withoutExclusive = form.goals.filter(
        (goal) => goal !== EXCLUSIVE_GOAL,
    );

    form.goals = isSelected(value)
        ? withoutExclusive.filter((goal) => goal !== value)
        : [...withoutExclusive, value];
};

const submit = (): void => {
    if (form.goals.length === 0 || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.goals_title')" />

    <WelcomeLayout
        :title="$t('welcome.goals_title')"
        :description="$t('welcome.goals_description')"
        :step="2"
        wide
    >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="goal in goals"
                :key="goal"
                type="button"
                :class="[
                    'relative flex cursor-pointer flex-col items-start gap-3 rounded-2xl border-2 border-foreground p-5 text-left shadow-2xs transition-shadow hover:shadow-md',
                    isSelected(goal) ? 'bg-violet-100' : 'bg-card',
                ]"
                @click="toggle(goal)"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                >
                    <component
                        :is="goalIcon(goal)"
                        :class="[goalColor(goal), 'size-5']"
                        stroke-width="2.25"
                    />
                </span>
                <span
                    class="text-base font-bold tracking-tight text-foreground"
                >
                    {{ goalLabel(goal) }}
                </span>
                <span
                    v-if="isSelected(goal)"
                    class="absolute top-4 right-4 inline-flex size-5 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
                >
                    <IconCheck
                        class="size-3 text-background"
                        stroke-width="3"
                    />
                </span>
            </button>
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="form.goals.length === 0 || form.processing"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
                <IconArrowRight class="size-4" />
            </Button>
        </div>
    </WelcomeLayout>
</template>
