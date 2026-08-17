<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import type { AvailablePlatform } from '@/components/accounts/NetworkConnectGrid.vue';

const props = defineProps<{
    platforms: AvailablePlatform[];
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const logoFor = (value: string): string =>
    value === 'instagram-facebook'
        ? '/images/accounts/instagram.png'
        : `/images/accounts/${value}.png`;

const shortLabel = (label: string): string =>
    label.includes('(') ? label.split('(')[0].trim() : label;

const select = (value: string): void => {
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
            :class="[
                'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors',
                props.modelValue === platform.value
                    ? 'border-primary/40 bg-primary/10 text-foreground'
                    : 'border-border bg-background text-foreground hover:bg-muted',
            ]"
            @click="select(platform.value)"
        >
            <img
                :src="logoFor(platform.value)"
                alt=""
                class="size-3.5 rounded-sm"
            />
            <span>{{ shortLabel(platform.label) }}</span>
            <IconCheck
                v-if="props.modelValue === platform.value"
                class="size-3.5 text-primary"
                stroke-width="2.5"
            />
        </button>
    </div>
</template>
