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

const props = withDefaults(
    defineProps<{
        goals: string[];
        modelValue: string[];
        disabled?: boolean;
        readonly?: boolean;
    }>(),
    {
        disabled: false,
        readonly: false,
    },
);

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

const goalLabel = (value: string): string => trans(`onboarding.goals.${value}`);

const isSelected = (value: string): boolean => props.modelValue.includes(value);

const toggle = (value: string): void => {
    if (props.readonly || props.disabled) {
        return;
    }

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
            :data-testid="`onboarding-goal-${goal}`"
            :dusk="`onboarding-goal-${goal}`"
            :disabled="props.disabled || props.readonly"
            :class="[
                'inline-flex items-center gap-2 rounded-full border-2 border-foreground py-1.5 ps-1.5 pe-3 text-start shadow-2xs',
                props.readonly
                    ? 'cursor-default'
                    : 'cursor-pointer transition-shadow hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60',
                isSelected(goal) ? 'bg-violet-100' : 'bg-card',
            ]"
            @click="toggle(goal)"
        >
            <span
                :class="[
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                    metaFor(goal).badge,
                ]"
            >
                <component
                    :is="metaFor(goal).icon"
                    :class="[metaFor(goal).iconClass, 'size-3.5']"
                    stroke-width="2"
                />
            </span>
            <span class="text-sm font-bold tracking-tight text-foreground">
                {{ goalLabel(goal) }}
            </span>
            <span
                v-if="isSelected(goal)"
                class="inline-flex size-4 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
            >
                <IconCheck
                    class="size-2.5 text-background"
                    stroke-width="3"
                />
            </span>
        </button>
    </div>
</template>
