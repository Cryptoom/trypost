<script setup lang="ts">
// PATCH:story-photo-fit: how a story photo is fitted to 9:16 (stored in platforms.*.meta.story_fit / story_crop).
import { IconCrop } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ImageCropperDialog from '@/components/ImageCropperDialog.vue';
import { Button } from '@/components/ui/button';
import { boundStoryCrop, type NormalizedRect } from '@/lib/imageCrop';
import type { MediaItem } from '@/types/media';

interface Props {
    meta?: Record<string, any>;
    media: MediaItem[];
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    meta: () => ({}),
    disabled: false,
});

const emit = defineEmits<{
    'update:meta': [meta: Record<string, any>];
}>();

const modes = ['center', 'smart', 'manual', 'fit'] as const;

const STORY_ASPECT = 9 / 16;

const cropOpen = ref(false);

const selectedMode = computed(() => {
    const mode = props.meta.story_fit as string | null | undefined;

    return (modes as readonly string[]).includes(mode ?? '') ? (mode as (typeof modes)[number]) : 'center';
});

const photo = computed(() => props.media[0] ?? null);

// Only a frame drawn on the photo that is published first counts as saved.
const savedRect = computed<NormalizedRect | null>(() => boundStoryCrop(props.meta, photo.value?.id));

const pickMode = (mode: string) => {
    if (props.disabled) return;
    // Leaving manual drops the frame and its photo binding (explicit nulls: the server merges meta and
    // ignores missing keys), so a stale frame cannot silently come back later.
    emit('update:meta', { ...props.meta, story_fit: mode, ...(mode === 'manual' ? {} : { story_crop: null, story_crop_media_id: null }) });
};

const saveRect = (rect: NormalizedRect) => {
    emit('update:meta', { ...props.meta, story_fit: 'manual', story_crop: rect, story_crop_media_id: photo.value?.id ?? null });
};
</script>

<template>
    <div class="space-y-2" data-testid="story-fit-settings">
        <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.story_fit.label') }}</p>
        <div class="flex flex-wrap gap-2" role="group" :aria-label="$t('posts.story_fit.label')">
            <button
                v-for="mode in modes"
                :key="mode"
                type="button"
                :aria-pressed="selectedMode === mode"
                class="cursor-pointer rounded-full border-2 px-3 py-1 text-xs font-bold uppercase tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                :class="selectedMode === mode
                    ? 'border-foreground bg-violet-100 text-foreground shadow-2xs'
                    : 'border-foreground/30 text-foreground/70 hover:border-foreground hover:text-foreground'"
                :disabled="disabled"
                :data-testid="`story-fit-mode-${mode}`"
                @click="pickMode(mode)"
            >
                {{ $t(`posts.story_fit.modes.${mode}.label`) }}
            </button>
        </div>
        <p class="text-xs font-medium text-foreground/70" data-testid="story-fit-mode-description">
            {{ $t(`posts.story_fit.modes.${selectedMode}.description`) }}
        </p>

        <div v-if="selectedMode === 'manual'" class="flex flex-wrap items-center gap-3">
            <Button
                type="button"
                size="sm"
                :disabled="disabled || !photo"
                data-testid="story-fit-pick-crop"
                @click="cropOpen = true"
            >
                <IconCrop class="size-4" />
                {{ savedRect ? $t('posts.story_fit.change_crop') : $t('posts.story_fit.pick_crop') }}
            </Button>
            <span class="text-xs font-medium text-foreground/70" role="status" data-testid="story-fit-crop-status">
                {{ savedRect ? $t('posts.story_fit.crop_saved') : $t('posts.story_fit.crop_missing') }}
            </span>
        </div>

        <ImageCropperDialog
            v-if="photo"
            v-model:open="cropOpen"
            :src="photo.url"
            :aspect="STORY_ASPECT"
            emit-rect-only
            :initial-rect="savedRect"
            :title="$t('posts.story_fit.crop_title')"
            :description="$t('posts.story_fit.crop_description')"
            @rect="saveRect"
        />
    </div>
</template>
