<script setup lang="ts">
import { ref } from 'vue';

import { useChatScroll } from '@/composables/useChatScroll';

withDefaults(
    defineProps<{
        testId?: string;
        endTestId?: string;
    }>(),
    {
        testId: 'chat-thread',
        endTestId: 'chat-end',
    },
);

const root = ref<HTMLElement | null>(null);

useChatScroll(root);
</script>

<template>
    <div
        ref="root"
        class="flex flex-col gap-5"
        :data-testid="testId"
        :dusk="testId"
    >
        <slot />
        <div
            :data-testid="endTestId"
            :dusk="endTestId"
            class="h-24 shrink-0"
            aria-hidden="true"
        />
    </div>
</template>
