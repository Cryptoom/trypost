<script setup lang="ts">
import {
    IconCalendar,
    IconCheck,
    IconClock,
    IconCoin,
    IconCompass,
    IconDots,
    IconPalette,
    IconPlug,
    IconSparkles,
    IconTrendingUp,
    IconUsersGroup,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

const props = defineProps<{
    goals: string[];
    modelValue: string[];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const EXCLUSIVE_GOAL = 'just_exploring';

const goalMeta: Record<
    string,
    { icon: FunctionalComponent; iconClass: string; badge: string }
> = {
    save_time: {
        icon: IconClock,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    ai_content: {
        icon: IconSparkles,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    use_mcp: {
        icon: IconPlug,
        iconClass: 'text-teal-700',
        badge: 'bg-teal-100',
    },
    plan_calendar: {
        icon: IconCalendar,
        iconClass: 'text-blue-700',
        badge: 'bg-blue-100',
    },
    stay_on_brand: {
        icon: IconPalette,
        iconClass: 'text-orange-700',
        badge: 'bg-orange-100',
    },
    grow_audience: {
        icon: IconTrendingUp,
        iconClass: 'text-rose-700',
        badge: 'bg-rose-100',
    },
    drive_sales: {
        icon: IconCoin,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    manage_clients: {
        icon: IconUsersGroup,
        iconClass: 'text-cyan-700',
        badge: 'bg-cyan-100',
    },
    just_exploring: {
        icon: IconCompass,
        iconClass: 'text-sky-700',
        badge: 'bg-sky-100',
    },
    other: {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    },
};

const metaFor = (
    value: string,
): { icon: FunctionalComponent; iconClass: string; badge: string } =>
    goalMeta[value] ?? {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

const goalLabel = (value: string): string => trans(`welcome.goals.${value}`);

const isSelected = (value: string): boolean => props.modelValue.includes(value);

const toggle = (value: string): void => {
    if (value === EXCLUSIVE_GOAL) {
        emit('update:modelValue', isSelected(value) ? [] : [value]);

        return;
    }

    const withoutExclusive = props.modelValue.filter(
        (goal) => goal !== EXCLUSIVE_GOAL,
    );

    emit(
        'update:modelValue',
        isSelected(value)
            ? withoutExclusive.filter((goal) => goal !== value)
            : [...withoutExclusive, value],
    );
};
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button
            v-for="goal in goals"
            :key="goal"
            type="button"
            :aria-pressed="isSelected(goal)"
            :data-testid="`welcome-goal-${goal}`"
            :dusk="`welcome-goal-${goal}`"
            :class="[
                'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors',
                isSelected(goal)
                    ? 'border-primary/40 bg-primary/10 text-foreground'
                    : 'border-border bg-background text-foreground hover:bg-muted',
            ]"
            @click="toggle(goal)"
        >
            <component
                :is="metaFor(goal).icon"
                :class="[metaFor(goal).iconClass, 'size-3.5']"
                stroke-width="2"
            />
            <span>{{ goalLabel(goal) }}</span>
            <IconCheck
                v-if="isSelected(goal)"
                class="size-3.5 text-primary"
                stroke-width="2.5"
            />
        </button>
    </div>
</template>
