<script setup lang="ts">
import type { UIMessage } from 'ai';

import ChatAssistantMessage from '@/components/chat/ChatAssistantMessage.vue';
import ChatScrollContainer from '@/components/chat/ChatScrollContainer.vue';
import ChatToolPart from '@/components/chat/ChatToolPart.vue';
import ChatUserMessage from '@/components/chat/ChatUserMessage.vue';
import type { ChatApprovalDecision, ChatToolInvocation } from '@/types/chat';

withDefaults(
    defineProps<{
        messages: UIMessage[];
        pending?: boolean;
        testId?: string;
        endTestId?: string;
    }>(),
    {
        pending: false,
        testId: 'chat-thread',
        endTestId: 'chat-end',
    },
);

const emit = defineEmits<{
    submit: [string];
    decide: [ChatApprovalDecision];
}>();

const onSubmit = (text: string): void => emit('submit', text);
const onDecide = (decision: ChatApprovalDecision): void => emit('decide', decision);
</script>

<template>
    <ChatScrollContainer :test-id="testId" :end-test-id="endTestId">
        <template v-for="message in messages" :key="message.id">
            <template v-for="(part, index) in message.parts" :key="`${message.id}-${index}`">
                <ChatUserMessage
                    v-if="part.type === 'text' && message.role === 'user'"
                    :text="part.text"
                />
                <ChatAssistantMessage
                    v-else-if="part.type === 'text'"
                    :description="part.text"
                    :streaming="part.state === 'streaming'"
                />
                <!--
                    Shallow-copied, not passed by reference: the `ai` package's
                    UI message stream reducer mutates a tool part's own
                    properties in place (`part.state = ...`, see
                    `updateToolPart` in `node_modules/ai/dist/index.js`)
                    instead of replacing the object. `messages` above is
                    already rebuilt fresh per render (see the comment on
                    `renderedMessages` in `pages/chat/Index.vue`), but that
                    only gives a new *array*; the individual part object
                    inside it is still the same reference across state
                    transitions. Vue's prop diffing skips re-rendering a
                    child when a prop's reference is unchanged, so
                    `ChatToolPart` would otherwise never see a tool call
                    move from "running" to its resolved state.
                -->
                <ChatToolPart
                    v-else-if="part.type.startsWith('tool-')"
                    :part="{ ...(part as unknown as ChatToolInvocation) }"
                    @submit="onSubmit"
                    @decide="onDecide"
                />
            </template>
        </template>

        <ChatAssistantMessage
            v-if="pending"
            streaming
            :description="$t('chat.thinking')"
            data-testid="chat-thinking"
            dusk="chat-thinking"
        />
    </ChatScrollContainer>
</template>
