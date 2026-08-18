<script setup lang="ts">
import {
    IconBriefcase,
    IconBuildingSkyscraper,
    IconBuildingStore,
    IconCheck,
    IconCode,
    IconDots,
    IconRocket,
    IconShoppingBag,
    IconSpeakerphone,
    IconUser,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

const props = withDefaults(
    defineProps<{
        personas: string[];
        modelValue: string;
        disabled?: boolean;
        readonly?: boolean;
    }>(),
    {
        disabled: false,
        readonly: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const personaMeta: Record<
    string,
    { icon: FunctionalComponent; iconClass: string; badge: string }
> = {
    creator: {
        icon: IconUser,
        iconClass: 'text-rose-700',
        badge: 'bg-rose-100',
    },
    freelancer: {
        icon: IconBriefcase,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    developer: {
        icon: IconCode,
        iconClass: 'text-cyan-700',
        badge: 'bg-cyan-100',
    },
    startup: {
        icon: IconRocket,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    agency: {
        icon: IconBuildingSkyscraper,
        iconClass: 'text-blue-700',
        badge: 'bg-blue-100',
    },
    small_business: {
        icon: IconBuildingStore,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    marketer: {
        icon: IconSpeakerphone,
        iconClass: 'text-fuchsia-700',
        badge: 'bg-fuchsia-100',
    },
    online_store: {
        icon: IconShoppingBag,
        iconClass: 'text-teal-700',
        badge: 'bg-teal-100',
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
    personaMeta[value] ?? {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

const personaLabel = (value: string): string =>
    trans(`welcome.personas.${value}`);

const select = (value: string): void => {
    if (props.disabled || props.readonly) {
        return;
    }

    emit('update:modelValue', value);
};
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button
            v-for="persona in personas"
            :key="persona"
            type="button"
            :aria-pressed="props.modelValue === persona"
            :disabled="props.disabled || props.readonly"
            :data-testid="`welcome-persona-${persona}`"
            :dusk="`welcome-persona-${persona}`"
            :class="[
                'inline-flex items-center gap-2 rounded-full border-2 border-foreground py-1.5 ps-1.5 pe-3 text-start shadow-2xs',
                props.readonly
                    ? 'cursor-default'
                    : 'cursor-pointer transition-shadow hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60',
                props.modelValue === persona ? 'bg-violet-100' : 'bg-card',
            ]"
            @click="select(persona)"
        >
            <span
                :class="[
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                    metaFor(persona).badge,
                ]"
            >
                <component
                    :is="metaFor(persona).icon"
                    :class="[metaFor(persona).iconClass, 'size-3.5']"
                    stroke-width="2"
                />
            </span>
            <span class="text-sm font-bold tracking-tight text-foreground">
                {{ personaLabel(persona) }}
            </span>
            <span
                v-if="props.modelValue === persona"
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
