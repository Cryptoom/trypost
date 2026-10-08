import { echo } from '@laravel/echo-vue';
import { trans } from 'laravel-vue-i18n';
import { onUnmounted, ref } from 'vue';

import { subscribePrivateChannel } from './subscribePrivateChannel';

interface TextDeltaEvent {
    delta: string;
}

interface ErrorEvent {
    message?: string;
}

// PATCH:aig-01 Fail a stream that goes silent instead of showing '...' forever.
export const AI_STREAM_IDLE_TIMEOUT_MS = 60_000;
// PATCH:aig-01 Generous grace until the first event: the queue worker may need a while to pick the job up.
export const AI_STREAM_FIRST_EVENT_TIMEOUT_MS = 120_000;

export type AiStreamStatus = 'idle' | 'streaming' | 'completed' | 'failed';

export const aiGenerationChannel = (userId: string, generationId: string): string => `user.${userId}.ai-gen.${generationId}`;

/**
 * Subscribe to a private channel for an in-flight AI generation.
 * Reactive state accumulates `.TextDelta` event deltas and transitions to
 * `completed` on `.StreamEnd` or `failed` on `.Error`.
 */
export const useAiStream = () => {
    const text = ref('');
    const status = ref<AiStreamStatus>('idle');
    const errorMessage = ref<string | null>(null);
    let subscribedName: string | null = null;
    let idleTimer: ReturnType<typeof setTimeout> | null = null;
    let seenEvent = false;

    const clearIdleTimer = () => {
        if (idleTimer) {
            clearTimeout(idleTimer);
        }
        idleTimer = null;
    };

    const fail = (message?: string | null) => {
        // PATCH:aig-01 Never overwrite a finished stream (late or duplicate error event).
        if (status.value === 'completed') {
            return;
        }
        clearIdleTimer();
        status.value = 'failed';
        errorMessage.value = message || trans('posts.ai.generate.errors.generation_failed');
    };

    const armTimer = (ms: number) => {
        clearIdleTimer();
        idleTimer = setTimeout(() => {
            if (status.value === 'streaming') {
                fail(trans('posts.ai.generate.errors.timeout'));
                // Leave the channel so late events of this generation are not delivered.
                unsubscribe();
            }
        }, ms);
    };

    // Any progress event re-arms the idle timer; ignored once the stream is no longer running.
    const onProgress = (): boolean => {
        if (status.value !== 'streaming') {
            return false;
        }
        seenEvent = true;
        armTimer(AI_STREAM_IDLE_TIMEOUT_MS);

        return true;
    };

    // Call after the generate POST succeeded: starts the first-event deadline.
    const start = () => {
        if (status.value === 'streaming' && ! seenEvent) {
            armTimer(AI_STREAM_FIRST_EVENT_TIMEOUT_MS);
        }
    };

    const reset = () => {
        clearIdleTimer();
        text.value = '';
        status.value = 'idle';
        errorMessage.value = null;
    };

    const unsubscribe = () => {
        clearIdleTimer();
        if (subscribedName) {
            echo().leave(`private-${subscribedName}`);
        }
        subscribedName = null;
    };

    const subscribe = (channelName: string): Promise<boolean> => {
        unsubscribe();
        reset();
        status.value = 'streaming';
        subscribedName = channelName;
        seenEvent = false;

        return subscribePrivateChannel(channelName, (channel) => {
            channel
                .listen('.stream_start', () => onProgress())
                .listen('.reasoning_start', () => onProgress())
                .listen('.reasoning_delta', () => onProgress())
                .listen('.text_delta', (e: TextDeltaEvent) => {
                    if (! onProgress()) return;
                    text.value += e.delta ?? '';
                })
                .listen('.stream_end', () => {
                    if (status.value !== 'streaming') return;
                    clearIdleTimer();
                    if (text.value.trim() === '') {
                        fail(trans('posts.ai.generate.errors.empty'));
                        return;
                    }
                    status.value = 'completed';
                })
                .listen('.error', (e: ErrorEvent) => fail(e?.message));
        });
    };

    onUnmounted(() => unsubscribe());

    return { text, status, errorMessage, subscribe, start, unsubscribe, reset };
};
