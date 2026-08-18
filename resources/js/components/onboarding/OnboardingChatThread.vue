<script setup lang="ts">
import ChatThread from '@/components/chat/ChatThread.vue';
</script>

<template>
    <ChatThread
        test-id="onboarding-chat-thread"
        end-test-id="onboarding-chat-end"
    >
        <slot />
    </ChatThread>
</template>
