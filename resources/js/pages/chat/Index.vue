<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconAlertTriangle, IconChartBar, IconClock, IconFileText, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatThread from '@/components/chat/ChatThread.vue';
import { Button } from '@/components/ui/button';
import { ChatRequestError, useConversationChat } from '@/composables/useConversationChat';
import AppLayout from '@/layouts/AppLayout.vue';
import { buildInitialMessages } from '@/lib/chat/seedMessages';
import { chat } from '@/routes/app';
import { index as billingIndex } from '@/routes/app/billing';
import { show } from '@/routes/app/chat';
import type { ChatApprovalDecision, ChatConversationSummary, ChatServerMessage } from '@/types/chat';

const props = defineProps<{
    conversations: ChatConversationSummary[];
    conversation?: ChatConversationSummary;
    messages?: ChatServerMessage[];
}>();

// Stable for the component's lifetime: a brand-new chat gets a client-generated
// id up front (the backend's claim() creates the row on the first message, keyed
// by whatever id the client sends), and Inertia remounts this page on every real
// navigation to a different conversation, so this never needs to be reactive.
const conversationId = props.conversation?.id ?? crypto.randomUUID();

const { messages, status, error, sendMessage, submitDecisions, clearError } = useConversationChat(
    conversationId,
    buildInitialMessages(props.messages ?? []),
);

const draft = ref('');

/**
 * `@ai-sdk/vue`'s `useChat` stores `messages` in a `shallowRef` and updates it
 * by mutating the array in place (`.push()`, index assignment) followed by a
 * manual `triggerRef()` — see `chatStateWrapper` in
 * `node_modules/@ai-sdk/vue/dist/index.js`. That correctly invalidates this
 * component's own render effect (and any `watch(messages, ...)`), but the
 * array's *reference* never changes, so Vue's child-prop diffing on
 * `<ChatThread :messages="...">` sees the same reference on every stream
 * chunk and skips re-rendering the child — the thread visibly freezes
 * mid-turn until some *other* prop on it changes value. Rebuilding a fresh
 * array here on every read forces the child to see a new reference and
 * re-render on each update, same as the framework-agnostic core already does
 * for the messages/parts objects nested inside it.
 */
const renderedMessages = computed(() => [...messages.value]);

const isBusy = computed(() => status.value === 'streaming' || status.value === 'submitted');

// Covers the gap between hitting send and the first visible token: the SDK can
// flip to 'streaming' as soon as the response starts (a protocol "start" chunk)
// before any renderable part exists yet, so this checks for actual content
// rather than trusting the status value alone.
const isWaitingForAssistant = computed(() => {
    if (! isBusy.value) {
        return false;
    }

    const last = messages.value[messages.value.length - 1];

    return last === undefined || last.role !== 'assistant' || last.parts.length === 0;
});

const requestError = computed<ChatRequestError | null>(() =>
    error.value instanceof ChatRequestError ? error.value : null,
);

const errorMessage = computed<string>(() => {
    const requestErr = requestError.value;

    if (requestErr === null) {
        return trans('chat.errors.stream_failed');
    }

    // 402 (billing gate) and 409 (turn already in progress) already carry a
    // translated message from the backend. 403/404 don't — Laravel's default
    // abort() response for those isn't localized, so those get their own copy.
    if (requestErr.status === 403 || requestErr.status === 404) {
        return trans('chat.errors.access_error');
    }

    return requestErr.message;
});

const errorTone = computed<'warning' | 'info' | 'error'>(() => {
    const httpStatus = requestError.value?.status;

    if (httpStatus === 402) {
        return 'warning';
    }

    if (httpStatus === 409) {
        return 'info';
    }

    return 'error';
});

const send = (text: string): void => {
    const trimmed = text.trim();

    if (trimmed === '' || isBusy.value) {
        return;
    }

    if (props.conversation === undefined) {
        window.history.replaceState(window.history.state, '', show.url({ conversation: conversationId }));
    }

    draft.value = '';
    sendMessage({ text: trimmed });
};

const submitDraft = (): void => send(draft.value);

const ask = (prompt: string): void => send(prompt);

const onDecide = (decision: ChatApprovalDecision): void => {
    submitDecisions({ [decision.toolCallId]: { action: decision.action, result: decision.result } });
};
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('chat.title')" />

        <div
            class="mx-auto flex min-h-[calc(100dvh-1rem)] w-full max-w-2xl flex-col px-4 py-8"
            data-testid="workspace-chat"
            dusk="workspace-chat"
        >
            <div class="flex flex-1 flex-col">
                <ChatThread
                    v-if="messages.length"
                    :messages="renderedMessages"
                    :pending="isWaitingForAssistant"
                    :disabled="isBusy"
                    @submit="ask"
                    @decide="onDecide"
                />

                <div
                    v-else
                    class="flex flex-1 flex-col items-center justify-center pb-10 text-center"
                >
                    <img
                        src="/images/trypost/icon.png"
                        alt=""
                        class="size-14 rounded-2xl border-2 border-foreground shadow-2xs"
                    />
                    <h1
                        class="mt-6 text-2xl font-normal tracking-tight text-foreground"
                        style="font-family: var(--font-display);"
                    >
                        {{ $t('chat.headline') }}
                    </h1>
                    <p class="mt-2 max-w-md text-sm text-muted-foreground">
                        {{ $t('chat.description') }}
                    </p>
                </div>
            </div>

            <div class="sticky bottom-0 bg-background pt-4 pb-2">
                <div
                    v-if="error"
                    class="mb-3 flex items-start gap-2 rounded-xl border p-3 text-sm"
                    :class="{
                        'border-amber-300 bg-amber-50 text-amber-900': errorTone === 'warning',
                        'border-foreground/15 bg-background text-muted-foreground': errorTone === 'info',
                        'border-destructive/30 bg-destructive/5 text-destructive': errorTone === 'error',
                    }"
                    data-testid="chat-error"
                    :dusk="`chat-error-${requestError?.status ?? 'unknown'}`"
                >
                    <IconClock v-if="errorTone === 'info'" class="mt-0.5 size-4 shrink-0" />
                    <IconAlertTriangle v-else class="mt-0.5 size-4 shrink-0" />

                    <div class="flex-1 space-y-2">
                        <p>{{ errorMessage }}</p>

                        <Link
                            v-if="requestError?.status === 402"
                            :href="billingIndex.url()"
                            class="inline-flex text-sm font-semibold underline underline-offset-4"
                            data-testid="chat-error-billing-cta"
                            dusk="chat-error-billing-cta"
                        >
                            {{ $t('chat.errors.payment_required_cta') }}
                        </Link>

                        <Link
                            v-else-if="requestError?.status === 403 || requestError?.status === 404"
                            :href="chat.url()"
                            class="inline-flex text-sm font-semibold underline underline-offset-4"
                            data-testid="chat-error-new-chat-cta"
                            dusk="chat-error-new-chat-cta"
                        >
                            {{ $t('sidebar.new_chat') }}
                        </Link>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="$t('common.close')"
                        data-testid="chat-error-dismiss"
                        dusk="chat-error-dismiss"
                        @click="clearError"
                    >
                        <IconX class="size-4" />
                    </Button>
                </div>

                <ChatComposer
                    v-model="draft"
                    :placeholder="$t('chat.placeholder')"
                    :send-label="$t('chat.send')"
                    :disabled="isBusy"
                    @submit="submitDraft"
                />

                <div
                    v-if="!messages.length"
                    class="mt-4"
                >
                    <p
                        class="mb-2 text-center text-xs font-bold uppercase tracking-wide text-muted-foreground"
                    >
                        {{ $t('chat.suggestions_label') }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border-2 border-foreground bg-card px-3 py-1.5 text-sm font-semibold shadow-2xs hover:bg-accent"
                            data-testid="chat-suggestion-posts"
                            dusk="chat-suggestion-posts"
                            @click="ask($t('chat.suggestions.posts'))"
                        >
                            <IconFileText class="size-4" />
                            {{ $t('chat.suggestions.posts') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-full border-2 border-foreground bg-card px-3 py-1.5 text-sm font-semibold shadow-2xs hover:bg-accent"
                            data-testid="chat-suggestion-metrics"
                            dusk="chat-suggestion-metrics"
                            @click="ask($t('chat.suggestions.metrics'))"
                        >
                            <IconChartBar class="size-4" />
                            {{ $t('chat.suggestions.metrics') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
