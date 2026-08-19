<script setup lang="ts">
import ChatScrollContainer from '@/components/chat/ChatScrollContainer.vue';
</script>

<template>
    <ChatScrollContainer
        test-id="onboarding-chat-thread"
        end-test-id="onboarding-chat-end"
    >
        <slot />
    </ChatScrollContainer>
</template>
