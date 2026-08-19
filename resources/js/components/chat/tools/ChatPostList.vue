<script setup lang="ts">
import ChatPostCard from '@/components/chat/tools/ChatPostCard.vue';
import type { ChatPost } from '@/types/chat';

defineProps<{
    data: ChatPost[];
}>();
</script>

<template>
    <div
        class="space-y-2"
        data-testid="chat-post-list"
        dusk="chat-post-list"
    >
        <p
            v-if="!data.length"
            class="text-sm text-muted-foreground"
        >
            {{ $t('chat.tool_card.empty_list') }}
        </p>

        <ChatPostCard
            v-for="post in data"
            :key="post.id"
            :data="post"
        />
    </div>
</template>
