<script setup lang="ts">
import { IconCheck, IconPencil, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

const props = withDefaults(
    defineProps<{
        methods: string[];
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

const methodMeta: Record<
    string,
    { icon: FunctionalComponent; iconClass: string; badge: string }
> = {
    manual: {
        icon: IconPencil,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    ai: {
        icon: IconSparkles,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
};

const metaFor = (
    value: string,
): { icon: FunctionalComponent; iconClass: string; badge: string } =>
    methodMeta[value] ?? {
        icon: IconPencil,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

const methodLabel = (value: string): string =>
    trans(`welcome.publish_method.${value}`);

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
            v-for="method in methods"
            :key="method"
            type="button"
            :aria-pressed="props.modelValue === method"
            :disabled="props.disabled || props.readonly"
            :data-testid="`welcome-publish-${method}`"
            :dusk="`welcome-publish-${method}`"
            :class="[
                'inline-flex items-center gap-2 rounded-full border-2 border-foreground py-1.5 ps-1.5 pe-3 text-start shadow-2xs',
                props.readonly
                    ? 'cursor-default'
                    : 'cursor-pointer transition-shadow hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60',
                props.modelValue === method ? 'bg-violet-100' : 'bg-card',
            ]"
            @click="select(method)"
        >
            <span
                :class="[
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                    metaFor(method).badge,
                ]"
            >
                <component
                    :is="metaFor(method).icon"
                    :class="[metaFor(method).iconClass, 'size-3.5']"
                    stroke-width="2"
                />
            </span>
            <span class="text-sm font-bold tracking-tight text-foreground">
                {{ methodLabel(method) }}
            </span>
            <span
                v-if="props.modelValue === method"
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
