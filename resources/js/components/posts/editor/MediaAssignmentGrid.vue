<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { isVideo } from '@/lib/mediaType';
import type { MediaItem } from '@/types/media';

const props = withDefaults(
    defineProps<{
        media: MediaItem[];
        selectedMediaIds: string[];
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    'update:selectedMediaIds': [ids: string[]];
}>();

// An empty array means "no scoping yet", the current, unscoped behaviour
// (every item applies to every platform), so every toggle renders as on
// until the user narrows the selection for the first time.
const isIncluded = (mediaId: string): boolean =>
    props.selectedMediaIds.length === 0 || props.selectedMediaIds.includes(mediaId);

const toggle = (mediaId: string): void => {
    const current = props.selectedMediaIds.length === 0
        ? props.media.map((item) => item.id)
        : props.selectedMediaIds;

    const next = isIncluded(mediaId)
        ? current.filter((id) => id !== mediaId)
        : [...current, mediaId];

    emit('update:selectedMediaIds', next);
};
</script>

<template>
    <div v-if="media.length > 1" class="space-y-2">
        <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">
            {{ $t('posts.edit.media_assignment.label') }}
        </p>
        <div class="flex flex-wrap gap-2">
            <TooltipProvider v-for="item in media" :key="item.id" :delay-duration="200">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border-2 transition-opacity disabled:cursor-not-allowed"
                            :class="isIncluded(item.id) ? 'border-foreground opacity-100' : 'border-foreground/30 opacity-40'"
                            :disabled="disabled"
                            :aria-pressed="isIncluded(item.id)"
                            :data-testid="`media-assignment-${item.id}`"
                            @click="toggle(item.id)"
                        >
                            <video v-if="isVideo(item)" :src="item.url" class="size-full object-cover" muted playsinline />
                            <img v-else :src="item.url" :alt="item.meta?.alt_text ?? item.original_filename ?? ''" class="size-full object-cover" />
                            <span
                                v-if="isIncluded(item.id)"
                                class="absolute -bottom-1 -right-1 inline-flex size-5 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-foreground shadow-2xs"
                                :data-testid="`media-assignment-${item.id}-on`"
                            >
                                <IconCheck class="size-3" stroke-width="3" />
                            </span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p class="text-xs">
                            {{ isIncluded(item.id) ? $t('posts.edit.media_assignment.included') : $t('posts.edit.media_assignment.excluded') }}
                        </p>
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </div>
    </div>
</template>
