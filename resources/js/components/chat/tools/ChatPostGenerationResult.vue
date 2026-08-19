<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconAlertTriangle, IconExternalLink, IconLoader2, IconSparkles } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import ChatPostCard from '@/components/chat/tools/ChatPostCard.vue';
import {
    POST_CREATION_TIMEOUT_MS,
    usePostCreation,
    type PostCreationDetachReason,
} from '@/composables/echo/usePostCreation';
import date from '@/date';
import { edit as editPost } from '@/routes/app/posts';
import type { ChatPost, ChatPostGeneration } from '@/types/chat';

const props = defineProps<{
    data: ChatPostGeneration | null;
}>();

/**
 * How long the bar takes to reach its ceiling. The card never sees the image
 * count — that lives in the tool CALL's arguments, not its result — so this is
 * a flat estimate. The elapsed clock beside it is the honest number; the bar
 * only exists so a long wait still looks alive.
 */
const ESTIMATED_SECONDS = 120;
const MAX_PROGRESS = 0.95;

/**
 * Ceiling on the elapsed clock — the same horizon the generation itself gets
 * (`POST_CREATION_TIMEOUT_MS`, mirrored server-side by
 * `App\Ai\Tools\ToolReplayer::GENERATION_WINDOW_MINUTES`). Past it nothing is
 * coming, so a counter still climbing beside "this keeps running in the
 * background" is telling the user something false.
 *
 * The clock needs a ceiling of its own because the wait does not always end on
 * a timer. A refused subscription — broadcasting unavailable, self-hosted
 * without Reverb, or private-channel auth refused — detaches within seconds
 * and arms nothing, so without this the clock would count for as long as the
 * tab stays open.
 */
const ELAPSED_CEILING_SECONDS = POST_CREATION_TIMEOUT_MS / 1000;

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
    /**
     * A timeout stops the clock outright: the window closed, measured against
     * real time by the composable's own timer. A refused subscription is the
     * opposite case — it lands seconds after mounting, with the generation
     * genuinely in flight, so the clock keeps running and stays the card's
     * only sign that it is alive rather than frozen. It is bounded instead by
     * ELAPSED_CEILING_SECONDS.
     *
     * The two are complements, not duplicates: `setInterval` is throttled in a
     * backgrounded tab, so `elapsed` can lag real time badly and reach its
     * ceiling long after the window truly closed. Whichever notices first wins.
     */
    onDetached: (reason: PostCreationDetachReason): void => {
        detached.value = true;

        if (reason === 'timeout') {
            stopElapsed();
        }
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

        if (elapsed.value >= ELAPSED_CEILING_SECONDS) {
            stopElapsed();
        }
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
