<script setup lang="ts">
import type { UIMessage } from 'ai';

import ChatAssistantMessage from '@/components/chat/ChatAssistantMessage.vue';
import ChatScrollContainer from '@/components/chat/ChatScrollContainer.vue';
import ChatToolPart from '@/components/chat/ChatToolPart.vue';
import ChatUserMessage from '@/components/chat/ChatUserMessage.vue';
import { resolveToolComponent } from '@/lib/chat/toolComponents';
import type { ChatApprovalDecision, ChatToolInvocation } from '@/types/chat';

const TOOL_TYPE_PREFIX = 'tool-';

withDefaults(
    defineProps<{
        messages: UIMessage[];
        pending?: boolean;
        /**
         * True while a turn is in flight. Forwarded to the prompt-kind tool
         * cards so they refuse to submit into a turn the page would drop —
         * the same guard `ChatComposer` gets.
         */
        disabled?: boolean;
        /**
         * True while the turn the card sent is in a failed state. Forwarded to
         * prompt-kind cards so one that latched into "sent" can un-latch: the
         * message never landed, and the card is the only place the choices
         * still exist.
         */
        failed?: boolean;
        testId?: string;
        endTestId?: string;
    }>(),
    {
        pending: false,
        disabled: false,
        failed: false,
        testId: 'chat-thread',
        endTestId: 'chat-end',
    },
);

const emit = defineEmits<{
    submit: [string];
    decide: [ChatApprovalDecision];
}>();

/**
 * Text an assistant turn produced AFTER opening an interactive card, which the
 * thread drops.
 *
 * An interactive card asks its own question and the user answers it by
 * clicking. Anything the model says once the card is on screen therefore lands
 * below it, either duplicating the question — two prompts competing to be
 * answered — or, by the time the user reads it, commenting on a choice they
 * have already made. The prompt asks the model to introduce a card and then
 * stop; this is what makes it so, since a prompt is a request and this is not.
 *
 * Only text after the card is dropped. The introduction written before it is
 * the point: it lands above the card and says why it is there.
 */
const isStaleAfterCard = (message: UIMessage, index: number): boolean => {
    if (message.role !== 'assistant') {
        return false;
    }

    return message.parts.slice(0, index).some((part) => {
        if (! part.type.startsWith(TOOL_TYPE_PREFIX)) {
            return false;
        }

        return resolveToolComponent(part.type.slice(TOOL_TYPE_PREFIX.length))?.kind === 'prompt';
    });
};

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
                    v-else-if="part.type === 'text' && ! isStaleAfterCard(message, index)"
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
                    :disabled="disabled"
                    :failed="failed"
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
