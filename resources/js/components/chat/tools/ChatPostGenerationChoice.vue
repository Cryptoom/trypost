<script setup lang="ts">
import { getPlatformLabel } from '@/composables/usePlatformLogo';

/**
 * One answered step of `ChatPostGenerationCard`, rendered as the user's own
 * message: the choices are collected client-side (no model round trip per
 * step), but the thread must read as if the user said each one out loud.
 * Mirrors `ChatUserMessage`'s bubble so a record and a real user turn are
 * visually the same thing.
 */
withDefaults(
    defineProps<{
        text: string;
        logos?: Array<{ platform: string; logo: string }>;
        /** False for a step that had no alternative to pick (a lone format). */
        changeable?: boolean;
        testId: string;
    }>(),
    {
        logos: () => [],
        changeable: false,
    },
);

const emit = defineEmits<{
    change: [];
}>();

const onChange = (): void => emit('change');
</script>

<template>
    <div
        class="flex animate-in flex-col items-end gap-1 fade-in slide-in-from-right-4 duration-300 motion-reduce:animate-none"
    >
        <div
            class="flex min-w-0 max-w-[90%] items-center gap-2 rounded-2xl rounded-tr-md border-2 border-foreground bg-amber-100 px-3.5 py-2 shadow-2xs"
            :data-testid="testId"
            :dusk="testId"
        >
            <span v-if="logos.length" class="flex shrink-0 -space-x-1.5">
                <span
                    v-for="entry in logos"
                    :key="entry.platform"
                    class="inline-flex size-5 items-center justify-center overflow-hidden rounded-full border border-foreground/20 bg-card"
                >
                    <img
                        :src="entry.logo"
                        :alt="getPlatformLabel(entry.platform)"
                        class="size-full object-cover"
                    />
                </span>
            </span>

            <p class="min-w-0 flex-1 text-sm leading-relaxed text-foreground">
                {{ text }}
            </p>
        </div>

        <button
            v-if="changeable"
            type="button"
            class="cursor-pointer px-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
            :data-testid="`${testId}-change`"
            :dusk="`${testId}-change`"
            @click="onChange"
        >
            {{ $t('chat.post_generation.change') }}
        </button>
    </div>
</template>
