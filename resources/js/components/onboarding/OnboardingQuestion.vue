<script setup lang="ts">
import ChatAssistantMessage from '@/components/chat/ChatAssistantMessage.vue';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        streaming?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        streaming: false,
    },
);
</script>

<template>
    <ChatAssistantMessage
        :title="title"
        :description="description"
        :streaming="streaming"
    >
        <slot />
    </ChatAssistantMessage>
</template>
