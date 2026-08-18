<script setup lang="ts">
import { ref } from 'vue';

import { useChatScroll } from '@/composables/useChatScroll';

const root = ref<HTMLElement | null>(null);

useChatScroll(root);
</script>

<template>
    <div
        ref="root"
        class="flex flex-col gap-5"
        data-testid="welcome-chat-thread"
        dusk="welcome-chat-thread"
    >
        <slot />
        <div
            data-testid="welcome-chat-end"
            dusk="welcome-chat-end"
            class="h-24 shrink-0"
            aria-hidden="true"
        />
    </div>
</template>
