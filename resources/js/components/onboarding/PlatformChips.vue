<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import type { AvailablePlatform } from '@/components/accounts/NetworkConnectGrid.vue';
import { welcomePlatformLabel } from '@/components/welcome/welcomePlatformLabel';
import { getPlatformLogo } from '@/composables/usePlatformLogo';

const props = withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        modelValue: string;
        readonly?: boolean;
    }>(),
    {
        readonly: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const select = (value: string): void => {
    if (props.readonly) {
        return;
    }

    emit('update:modelValue', value);
};
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button
            v-for="platform in platforms"
            :key="platform.value"
            type="button"
            :aria-pressed="props.modelValue === platform.value"
            :data-testid="`welcome-platform-${platform.value}`"
            :dusk="`welcome-platform-${platform.value}`"
            :disabled="props.readonly"
            :class="[
                'inline-flex items-center gap-2 rounded-full border-2 border-foreground py-1.5 ps-1.5 pe-3 text-start shadow-2xs',
                props.readonly
                    ? 'cursor-default'
                    : 'cursor-pointer transition-shadow hover:shadow-md',
                props.modelValue === platform.value
                    ? 'bg-violet-100'
                    : 'bg-card',
            ]"
            @click="select(platform.value)"
        >
            <span
                class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground shadow-2xs"
            >
                <img
                    :src="getPlatformLogo(platform.value)"
                    alt=""
                    class="size-full object-cover"
                />
            </span>
            <span class="text-sm font-bold tracking-tight text-foreground">
                {{ welcomePlatformLabel(platform.label) }}
            </span>
            <span
                v-if="props.modelValue === platform.value"
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
