<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';

import { chat } from '@/routes/app';

defineProps<{
    threads?: { id: string; title: string; group: string }[];
}>();
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
            v-if="!threads?.length"
            class="px-1 py-6 text-sm text-muted-foreground"
            data-testid="sidebar-no-chats"
        >
            {{ $t('sidebar.no_chats') }}
        </div>

        <div v-else class="space-y-3">
            <p
                class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground"
            >
                {{ $t('sidebar.last_7_days') }}
            </p>
        </div>
    </div>
</template>
