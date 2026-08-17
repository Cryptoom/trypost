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

const props = defineProps<{
    personas: string[];
    modelValue: string;
}>();

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
            :data-testid="`welcome-persona-${persona}`"
            :dusk="`welcome-persona-${persona}`"
            :class="[
                'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors',
                props.modelValue === persona
                    ? 'border-primary/40 bg-primary/10 text-foreground'
                    : 'border-border bg-background text-foreground hover:bg-muted',
            ]"
            @click="select(persona)"
        >
            <component
                :is="metaFor(persona).icon"
                :class="[metaFor(persona).iconClass, 'size-3.5']"
                stroke-width="2"
            />
            <span>{{ personaLabel(persona) }}</span>
            <IconCheck
                v-if="props.modelValue === persona"
                class="size-3.5 text-primary"
                stroke-width="2.5"
            />
        </button>
    </div>
</template>
