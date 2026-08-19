<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/composables/useActiveUrl';
import dayjs from '@/dayjs';
import { chat } from '@/routes/app';
import { show } from '@/routes/app/chat';
import type { ChatConversationSummary } from '@/types/chat';

const props = defineProps<{
    conversations?: ChatConversationSummary[];
}>();

const { urlIsActive } = useActiveUrl();

type GroupKey = 'today' | 'yesterday' | 'last_7_days' | 'last_30_days' | 'older';

const GROUP_ORDER: GroupKey[] = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'older'];

/**
 * Grouped client-side, not by the backend, so the boundaries fall on the
 * user's own local day — `dayjs` is already timezone-aware app-wide via
 * `@/dayjs`, and a server-computed group would use the server's timezone
 * instead.
 */
const groupFor = (updatedAt: string | null): GroupKey => {
    const date = dayjs(updatedAt ?? undefined);
    const startOfToday = dayjs().startOf('day');

    if (date.isSame(startOfToday, 'day')) {
        return 'today';
    }

    if (date.isSame(startOfToday.subtract(1, 'day'), 'day')) {
        return 'yesterday';
    }

    if (date.isAfter(startOfToday.subtract(7, 'day'))) {
        return 'last_7_days';
    }

    if (date.isAfter(startOfToday.subtract(30, 'day'))) {
        return 'last_30_days';
    }

    return 'older';
};

const groupedConversations = computed<{ key: GroupKey; items: ChatConversationSummary[] }[]>(() => {
    const buckets = new Map<GroupKey, ChatConversationSummary[]>();

    for (const conversation of props.conversations ?? []) {
        const key = groupFor(conversation.updated_at);
        buckets.set(key, [...(buckets.get(key) ?? []), conversation]);
    }

    return GROUP_ORDER.filter((key) => buckets.has(key)).map((key) => ({
        key,
        items: buckets.get(key) ?? [],
    }));
});
</script>

<template>
    <div
        class="flex min-h-0 flex-1 flex-col px-2"
        data-testid="sidebar-chat-history"
        dusk="sidebar-chat-history"
    >
        <div class="mb-2 flex items-center justify-between px-1">
            <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                {{ $t('sidebar.chat_history') }}
            </p>
            <Link
                :href="chat.url()"
                class="inline-flex size-7 items-center justify-center rounded-md border-2 border-foreground bg-card text-foreground shadow-2xs hover:bg-accent"
                :aria-label="$t('sidebar.new_chat')"
                data-testid="sidebar-new-chat"
                dusk="sidebar-new-chat"
            >
                <IconPlus class="size-3.5" />
            </Link>
        </div>

        <div
            v-if="!conversations?.length"
            class="px-1 py-6 text-sm text-muted-foreground"
            data-testid="sidebar-no-chats"
        >
            {{ $t('sidebar.no_chats') }}
        </div>

        <div v-else class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto">
            <div v-for="group in groupedConversations" :key="group.key" class="space-y-1">
                <p class="px-1 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                    {{ $t(`chat.groups.${group.key}`) }}
                </p>

                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="conversation in group.items"
                        :key="conversation.id"
                    >
                        <SidebarMenuButton
                            as-child
                            :is-active="urlIsActive(show.url({ conversation: conversation.id }))"
                            :tooltip="conversation.title ?? ''"
                        >
                            <Link
                                :href="show.url({ conversation: conversation.id })"
                                data-testid="sidebar-chat-item"
                                :dusk="`sidebar-chat-item-${conversation.id}`"
                            >
                                <span class="truncate">{{ conversation.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </div>
        </div>
    </div>
</template>
