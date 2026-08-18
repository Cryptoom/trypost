<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconChartBar, IconFileText } from '@tabler/icons-vue';
import { ref } from 'vue';

import ChatAssistantMessage from '@/components/chat/ChatAssistantMessage.vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatThread from '@/components/chat/ChatThread.vue';
import ChatUserMessage from '@/components/chat/ChatUserMessage.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type ChatTurn = {
    id: number;
    role: 'user' | 'assistant';
    text: string;
};

defineProps<{
    threads: { id: string; title: string; group: string }[];
}>();

const draft = ref('');
const turns = ref<ChatTurn[]>([]);
let nextId = 1;

const send = (text: string): void => {
    const message = text.trim();

    if (! message) {
        return;
    }

    turns.value = [
        ...turns.value,
        { id: nextId++, role: 'user', text: message },
        {
            id: nextId++,
            role: 'assistant',
            text: '',
        },
    ];
    draft.value = '';
};

const submitDraft = (): void => {
    send(draft.value);
};

const ask = (prompt: string): void => {
    send(prompt);
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
                <ChatThread v-if="turns.length">
                    <template v-for="turn in turns" :key="turn.id">
                        <ChatUserMessage
                            v-if="turn.role === 'user'"
                            :text="turn.text"
                        />
                        <ChatAssistantMessage
                            v-else
                            :description="
                                turn.text || $t('chat.coming_soon')
                            "
                        />
                    </template>
                </ChatThread>

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
                <ChatComposer
                    v-model="draft"
                    :placeholder="$t('chat.placeholder')"
                    :send-label="$t('chat.send')"
                    @submit="submitDraft"
                />

                <div
                    v-if="!turns.length"
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
