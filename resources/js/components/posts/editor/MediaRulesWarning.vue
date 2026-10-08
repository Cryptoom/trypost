<script setup lang="ts">
// PATCH:story-photo-fit: story photos get an info hint matching the chosen fit mode instead of an error.
import { IconAlertTriangle, IconExternalLink, IconInfoCircle } from '@tabler/icons-vue';
import { computed } from 'vue';

import { getMediaValidationWarning, isStoryPhoto } from '@/composables/useMedia';
import { getMediaRulesForContentType } from '@/composables/useMediaRules';
import { mediaLimitsDocsUrl } from '@/lib/docs';
import { isNormalizedRect } from '@/lib/imageCrop';
import type { MediaItem } from '@/types/media';

const props = withDefaults(
    defineProps<{
        contentType: string;
        media: MediaItem[];
        platform: string;
        meta?: Record<string, any>;
    }>(),
    { meta: () => ({}) },
);

const warning = computed(() => getMediaValidationWarning(props.contentType, props.media));

// An aspect-ratio warning on an auto-fitting type (story) can only come from a video.
const isStoryVideoAspect = computed(
    () =>
        (warning.value?.key === 'aspect_ratio_too_narrow' || warning.value?.key === 'aspect_ratio_too_wide') &&
        getMediaRulesForContentType(props.contentType).autoFitsImage === true,
);

const storyHintKey = computed(() => {
    if (warning.value || !isStoryPhoto(props.contentType, props.media)) {
        return null;
    }

    const mode = (props.meta.story_fit as string | null | undefined) || 'center';

    if (mode === 'manual' && !isNormalizedRect(props.meta.story_crop)) {
        return 'manual_missing';
    }

    return ['smart', 'manual', 'fit'].includes(mode) ? mode : 'center';
});
</script>

<template>
    <p
        v-if="warning"
        class="flex items-start gap-2 rounded-lg border-2 border-foreground bg-rose-50 p-2 text-xs font-semibold text-rose-700"
        data-testid="media-rules-warning"
    >
        <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
        <span>
            <template v-if="isStoryVideoAspect">{{ $t('posts.story_fit.video_aspect', { current: warning.params.current }) }}</template>
            <template v-else>{{ $t(`posts.form.warnings.${warning.key}`, warning.params) }}</template>
            <a
                :href="mediaLimitsDocsUrl(platform)"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-0.5 underline underline-offset-2"
            >
                {{ $t('posts.edit.compliance.media_limits_docs') }}
                <IconExternalLink class="size-3" />
            </a>
        </span>
    </p>
    <p
        v-else-if="storyHintKey"
        class="flex items-start gap-2 rounded-lg border-2 border-foreground bg-foreground/5 p-2 text-xs font-semibold text-foreground"
        data-testid="story-fit-hint"
    >
        <IconInfoCircle class="mt-0.5 size-3.5 shrink-0" />
        <span>{{ $t(`posts.story_fit.hints.${storyHintKey}`) }}</span>
    </p>
</template>
