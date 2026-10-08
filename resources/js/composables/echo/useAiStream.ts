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

    const clearIdleTimer = () => {
        if (idleTimer) {
            clearTimeout(idleTimer);
        }
        idleTimer = null;
    };

    const fail = (message?: string | null) => {
        clearIdleTimer();
        status.value = 'failed';
        errorMessage.value = message || trans('posts.ai.generate.errors.generation_failed');
    };

    const armIdleTimer = () => {
        clearIdleTimer();
        idleTimer = setTimeout(() => {
            if (status.value === 'streaming') {
                fail(trans('posts.ai.generate.errors.timeout'));
            }
        }, AI_STREAM_IDLE_TIMEOUT_MS);
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
        armIdleTimer();

        return subscribePrivateChannel(channelName, (channel) => {
            channel
                .listen('.text_delta', (e: TextDeltaEvent) => {
                    text.value += e.delta ?? '';
                    armIdleTimer();
                })
                .listen('.stream_end', () => {
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

    return { text, status, errorMessage, subscribe, unsubscribe, reset };
};
