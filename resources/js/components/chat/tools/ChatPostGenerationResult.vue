<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconAlertTriangle, IconExternalLink, IconLoader2, IconSparkles } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import ChatPostCard from '@/components/chat/tools/ChatPostCard.vue';
import { usePostCreation } from '@/composables/echo/usePostCreation';
import date from '@/date';
import { edit as editPost } from '@/routes/app/posts';
import type { ChatPost, ChatPostGeneration } from '@/types/chat';

const props = defineProps<{
    data: ChatPostGeneration | null;
}>();

/**
 * How long the bar takes to reach its ceiling. The card, unlike the loading
 * screen it replaces, never sees the image count — that lives in the tool
 * CALL's arguments, not its result — so this is a flat estimate rather than
 * `AiPostWizard`'s per-image one. The elapsed clock beside it is the honest
 * number; the bar only exists so a long wait still looks alive.
 */
const ESTIMATED_SECONDS = 120;
const MAX_PROGRESS = 0.95;

const broadcastPostId = ref<string | null>(null);
const failed = ref(false);
const failureMessage = ref<string | null>(null);
const elapsed = ref(0);

/**
 * True once the card stopped listening without an answer — the socket refused
 * the channel, or the wait ran out. The generation is unaffected and still
 * writes its post, so the card keeps its waiting shape and only swaps the
 * hint: reopening the conversation resolves the post from `creation_id`.
 */
const detached = ref(false);

let elapsedTimer: ReturnType<typeof setInterval> | null = null;

/**
 * The post the server already resolved from `creation_id` — present only when
 * the conversation was reopened after the generation had finished. Read
 * through a computed rather than snapshotted, because `ChatToolPart` re-parses
 * the payload on every parent render and hands down a fresh object each time.
 */
const replayedPost = computed<ChatPost | null>(() => props.data?.post ?? null);

const readyPostId = computed<string | null>(() => replayedPost.value?.id ?? broadcastPostId.value);

const isWaiting = computed<boolean>(() => readyPostId.value === null && ! failed.value);

const elapsedLabel = computed<string>(() => date.formatClock(elapsed.value));

const progressPercent = computed<number>(() =>
    Math.round(Math.min(MAX_PROGRESS, elapsed.value / ESTIMATED_SECONDS) * 100),
);

const stopElapsed = (): void => {
    if (elapsedTimer !== null) {
        clearInterval(elapsedTimer);
        elapsedTimer = null;
    }
};

const fail = (message: string | null): void => {
    failed.value = true;
    failureMessage.value = message;
    stopElapsed();
};

const { watchCreation } = usePostCreation({
    onReady: (postId: string): void => {
        broadcastPostId.value = postId;
        stopElapsed();
    },
    onFailed: fail,
    onDetached: (): void => {
        detached.value = true;
    },
});

onMounted(() => {
    if (replayedPost.value !== null) {
        return;
    }

    const channel = props.data?.channel;

    // `settled` means the server already established the generation ended
    // without a post: the turn predates the whole generation window, so
    // nothing will ever arrive on the channel. Subscribing would spin for the
    // length of the timeout implying work is still in progress.
    if (! channel || props.data?.settled === true) {
        fail(null);

        return;
    }

    elapsedTimer = setInterval(() => {
        elapsed.value += 1;
    }, 1000);

    watchCreation(channel);
});

onBeforeUnmount(stopElapsed);
</script>

<template>
    <div data-testid="chat-post-generation-result">
        <ChatPostCard
            v-if="replayedPost"
            :data="replayedPost"
        />

        <div
            v-else-if="readyPostId"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-foreground/15 bg-background p-3"
            data-testid="chat-post-generation-ready"
        >
            <IconSparkles class="size-4 shrink-0 text-primary" />

            <span class="text-sm text-foreground/90">{{ $t('chat.post_generation.result_ready') }}</span>

            <Link
                :href="editPost.url(readyPostId)"
                class="ms-auto inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                data-testid="chat-post-generation-open"
                dusk="chat-post-generation-open"
            >
                <IconExternalLink class="size-3.5" />
                {{ $t('chat.tool_card.open_in_editor') }}
            </Link>
        </div>

        <div
            v-else-if="isWaiting"
            class="space-y-2 rounded-xl border border-foreground/15 bg-background p-3"
            data-testid="chat-post-generation-waiting"
        >
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <IconLoader2
                    class="size-4 shrink-0 animate-spin"
                    aria-hidden="true"
                />
                <span>{{ $t('chat.post_generation.result_waiting') }}</span>
                <span
                    class="ms-auto font-mono text-xs"
                    :aria-label="$t('chat.post_generation.result_elapsed_label', { elapsed: elapsedLabel })"
                >
                    {{ elapsedLabel }}
                </span>
            </div>

            <div
                class="h-1.5 w-full overflow-hidden rounded-full bg-accent"
                role="progressbar"
                :aria-valuenow="progressPercent"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="$t('chat.post_generation.result_waiting')"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width] duration-700 ease-out"
                    :style="{ width: `${progressPercent}%` }"
                ></div>
            </div>

            <p
                class="text-xs text-muted-foreground"
                data-testid="chat-post-generation-waiting-hint"
            >
                {{ detached ? $t('chat.post_generation.result_detached_hint') : $t('chat.post_generation.result_waiting_hint') }}
            </p>
        </div>

        <div
            v-else
            class="flex items-center gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
            data-testid="chat-post-generation-failed"
        >
            <IconAlertTriangle class="size-4 shrink-0" />
            <span>{{ failureMessage || $t('chat.post_generation.result_failed') }}</span>
        </div>
    </div>
</template>
