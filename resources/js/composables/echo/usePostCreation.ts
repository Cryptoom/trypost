import { echo } from '@laravel/echo-vue';
import { onBeforeUnmount, ref, type Ref } from 'vue';

import { subscribePrivateChannel } from '@/composables/echo/subscribePrivateChannel';

/**
 * How long a generation is given before the wait is abandoned. Generating a
 * carousel with ten images legitimately takes minutes, so this is an upper
 * bound on the whole job rather than a responsiveness budget.
 */
export const POST_CREATION_TIMEOUT_MS = 960_000;

/** The payload `App\Events\Ai\PostCreationReady::broadcastWith()` sends. */
export interface PostCreationCompleted {
    creation_id?: string;
    post_id?: string | null;
    error?: string | null;
}

export interface UsePostCreationOptions {
    /** Called once with the finished post's id. */
    onReady: (postId: string) => void;
    /**
     * Called once when the GENERATION itself reported a failure. The message is
     * whatever the event carried, or null when the caller supplies its own copy.
     */
    onFailed: (message: string | null) => void;
    /**
     * Called once when the wait was abandoned without ever hearing back: the
     * channel refused the subscription, or the timeout ran out.
     *
     * Deliberately separate from `onFailed`. Losing the socket says nothing
     * about the generation, which keeps running on the queue and still writes
     * its post — so a caller must not report it as a failed generation. The
     * post is recoverable by reopening, where the server resolves it from
     * `creation_id` instead of listening for a broadcast that already fired.
     */
    onDetached: () => void;
    timeoutMs?: number;
}

export interface UsePostCreation {
    /** True between `watchCreation()` and the first outcome. */
    watching: Ref<boolean>;
    watchCreation: (channel: string) => Promise<void>;
    stopWatching: () => void;
}

/**
 * Waits for one AI post generation to finish.
 *
 * `generate_post` (and the controller it replaces) returns as soon as the job
 * is queued, naming a private channel — WITHOUT the `private-` prefix, which
 * Echo adds back — that `PostCreationReady` broadcasts `.ai.creation.completed`
 * on. This subscribes to it, resolves exactly one outcome, and leaves the
 * channel again.
 *
 * It settles at most once, into exactly one of three outcomes: the post is
 * ready, the generation failed, or the wait was abandoned without an answer.
 * The first of the event, a refused subscription and the timeout wins; every
 * later one is dropped. That matters because the event can arrive before
 * `subscribePrivateChannel` has even resolved, and arming the timeout
 * afterwards would later abandon a generation that already succeeded.
 *
 * Cleanup is registered on the calling component's unmount, because the chat
 * mounts and unmounts one of these per generation card as a conversation is
 * scrolled and reopened — a leaked subscription per card is not the same
 * cost it was on the single-use loading screen.
 */
export const usePostCreation = (options: UsePostCreationOptions): UsePostCreation => {
    const watching = ref(false);

    let subscribedChannel: string | null = null;
    let timeout: ReturnType<typeof setTimeout> | null = null;
    let settled = false;
    let disposed = false;

    const stopWatching = (): void => {
        if (subscribedChannel !== null) {
            echo().leave(`private-${subscribedChannel}`);
            subscribedChannel = null;
        }

        if (timeout !== null) {
            clearTimeout(timeout);
            timeout = null;
        }

        watching.value = false;
    };

    const settle = (outcome: () => void): void => {
        if (settled) {
            return;
        }

        settled = true;
        stopWatching();

        if (! disposed) {
            outcome();
        }
    };

    const watchCreation = async (channel: string): Promise<void> => {
        if (disposed || settled || watching.value) {
            return;
        }

        watching.value = true;

        const confirmed = await subscribePrivateChannel(channel, (privateChannel) => {
            subscribedChannel = channel;

            privateChannel.listen('.ai.creation.completed', (event: PostCreationCompleted) => {
                const postId = event.post_id;

                if (event.error || ! postId) {
                    settle(() => options.onFailed(event.error ?? null));

                    return;
                }

                settle(() => options.onReady(postId));
            });
        });

        if (disposed || settled) {
            stopWatching();

            return;
        }

        if (! confirmed) {
            settle(options.onDetached);

            return;
        }

        timeout = setTimeout(() => settle(options.onDetached), options.timeoutMs ?? POST_CREATION_TIMEOUT_MS);
    };

    onBeforeUnmount(() => {
        disposed = true;
        stopWatching();
    });

    return { watching, watchCreation, stopWatching };
};
