<script setup lang="ts">
import { IconExternalLink } from '@tabler/icons-vue';

import PostPlatformMetrics from '@/components/posts/PostPlatformMetrics.vue';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import type { ChatPostMetricRow, ChatPostMetrics as ChatPostMetricsData, ChatPostMetricsValue } from '@/types/chat';

defineProps<{
    data: ChatPostMetricsData;
}>();

const metricRows = (value: ChatPostMetricsValue): ChatPostMetricRow[] => (Array.isArray(value) ? value : []);

const unsupportedReason = (value: ChatPostMetricsValue): string | null => (Array.isArray(value) ? null : value.reason);

/**
 * `PostMetricsFetcher::forPlatform()` only ever returns the two reasons
 * below, but a future platform could add a third — falling back to
 * `platform_not_supported`'s copy keeps that case readable instead of
 * printing a raw i18n key.
 */
const unsupportedReasonKey = (reason: string | null): string =>
    reason === 'not_published' ? 'chat.metrics.unsupported.not_published' : 'chat.metrics.unsupported.platform_not_supported';
</script>

<template>
    <div
        class="space-y-2"
        data-testid="chat-post-metrics"
        dusk="chat-post-metrics"
    >
        <p
            v-if="!data.platforms.length"
            class="text-sm text-muted-foreground"
        >
            {{ $t('chat.metrics.empty') }}
        </p>

        <div
            v-for="platform in data.platforms"
            :key="platform.post_platform_id"
            class="space-y-1.5 rounded-xl border border-foreground/15 bg-background p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1.5">
                    <span class="inline-flex size-5 items-center justify-center overflow-hidden rounded-full border border-foreground/20 bg-card">
                        <img
                            :src="getPlatformLogo(platform.platform)"
                            :alt="getPlatformLabel(platform.platform)"
                            class="size-full object-cover"
                        />
                    </span>
                    <span class="text-sm font-semibold">{{ getPlatformLabel(platform.platform) }}</span>
                </div>

                <a
                    v-if="platform.platform_url"
                    :href="platform.platform_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    data-testid="chat-post-metrics-platform-link"
                >
                    <IconExternalLink class="size-3.5" />
                </a>
            </div>

            <p
                v-if="unsupportedReason(platform.metrics)"
                class="text-xs text-muted-foreground"
            >
                {{ $t(unsupportedReasonKey(unsupportedReason(platform.metrics))) }}
            </p>
            <PostPlatformMetrics
                v-else-if="metricRows(platform.metrics).length"
                :metrics="metricRows(platform.metrics)"
            />
            <p
                v-else
                class="text-xs text-muted-foreground"
            >
                {{ $t('chat.metrics.empty') }}
            </p>
        </div>
    </div>
</template>
