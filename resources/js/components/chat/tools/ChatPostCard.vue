<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Badge } from '@/components/ui/badge';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import { getPostStatusConfig } from '@/composables/usePostStatus';
import date from '@/date';
import { edit as editPost } from '@/routes/app/posts';
import type { ChatPost } from '@/types/chat';

const props = defineProps<{
    data: ChatPost;
}>();

const statusConfig = computed(() => getPostStatusConfig(props.data.status ?? 'draft'));

const scheduledOrPublishedAt = computed(() => props.data.scheduled_at ?? props.data.published_at ?? null);

const platforms = computed(() => props.data.platforms ?? []);
</script>

<template>
    <div
        class="space-y-2 rounded-xl border border-foreground/15 bg-background p-3"
        data-testid="chat-post-card"
        :dusk="`chat-post-card-${data.id}`"
    >
        <p
            v-if="data.deleted"
            class="text-sm text-muted-foreground"
            data-testid="chat-post-card-deleted"
        >
            {{ $t('chat.tool_card.post_deleted') }}
        </p>

        <template v-else>
            <div class="flex items-start justify-between gap-2">
                <div
                    v-if="platforms.length"
                    class="flex -space-x-1.5"
                >
                    <span
                        v-for="(platform, index) in platforms"
                        :key="`${platform.platform ?? 'unknown'}-${index}`"
                        class="inline-flex size-6 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs"
                        :title="platform.handle ? `${getPlatformLabel(platform.platform ?? '')} · @${platform.handle}` : getPlatformLabel(platform.platform ?? '')"
                    >
                        <img
                            :src="getPlatformLogo(platform.platform ?? '')"
                            :alt="getPlatformLabel(platform.platform ?? '')"
                            class="size-full object-cover"
                        />
                    </span>
                </div>

                <Badge :variant="statusConfig.variant" class="ms-auto">
                    <component :is="statusConfig.icon" class="size-3" />
                    {{ statusConfig.label }}
                </Badge>
            </div>

            <p
                v-if="data.content"
                class="line-clamp-3 text-sm text-foreground/90"
            >
                {{ data.content }}
            </p>
            <p
                v-else
                class="text-sm italic text-muted-foreground"
            >
                {{ $t('chat.tool_card.untitled') }}
            </p>

            <p
                v-if="scheduledOrPublishedAt"
                class="text-xs text-muted-foreground"
            >
                {{ date.formatDateTime(scheduledOrPublishedAt) }}
            </p>

            <Link
                :href="editPost.url(data.id)"
                class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                data-testid="chat-post-card-open"
                dusk="chat-post-card-open"
            >
                <IconExternalLink class="size-3.5" />
                {{ $t('chat.tool_card.open_in_editor') }}
            </Link>
        </template>
    </div>
</template>
